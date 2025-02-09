<?php

declare(strict_types=1);

namespace PhluxorSaga;

use Exception;
use Google\Protobuf\Internal\Message;
use Phluxor\ActorSystem\Behavior;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\{ActorInterface, ReceiveFunction, Restarting, Started, Stopped, Stopping};
use Phluxor\ActorSystem\Props;
use Phluxor\ActorSystem\ProtoBuf\Terminated;
use Phluxor\ActorSystem\Ref;
use Phluxor\Persistence\Mixin;
use Phluxor\Persistence\PersistentInterface;
use PhluxorSaga\Message\Credit;
use PhluxorSaga\Message\Debit;
use PhluxorSaga\Message\GetBalance;
use PhluxorSaga\Message\Ok;
use PhluxorSaga\Message\Refused;
use PhluxorSaga\Message\UnknownResult;
use PhluxorSaga\ProtoBuf\SuccessResult;
use Random\RandomException;

class TransferProcess implements ActorInterface, PersistentInterface
{
    use Mixin;

    private bool $processCompleted = false;
    private bool $restarting = false;
    private bool $stopping = false;

    public function __construct(
        private readonly Ref $from,
        private readonly Ref $to,
        private readonly float $amount,
        private readonly float $availability,
        private readonly Behavior $behavior = new Behavior()
    ) {
    }

    /**
     * @throws RandomException
     * @throws Exception
     */
    public function receive(ContextInterface $context): void
    {
        $message = $context->message();
        switch (true) {
            case $message instanceof Started:
                $this->behavior->become(
                    new ReceiveFunction(
                        fn($context) => $this->starting($context)
                    )
                );
                break;
            case $message instanceof Stopping:
                $this->stopping = true;
                break;
            case $message instanceof Restarting:
                $this->restarting = true;
                break;
            case $message instanceof Stopped && !$this->processCompleted:
                $parent = $context->parent();
                $self = $context->self();
                if (!$this->recovering()) {
                    $this->persistenceReceive(
                        new ProtoBuf\TransferFailed('Process stopped unexpectedly')
                    );
                    $this->persistenceReceive(
                        new ProtoBuf\EscalateTransfer('Unknown failure. Transfer Process crashed')
                    );
                }
                $context->send($parent, new UnknownResult($self));
                break;
            case $message instanceof Stopped && ($this->restarting || $this->stopping):
                break;
            case $message instanceof Message:
                $this->applyEvent($message);
                break;
            default:
                if ($this->fail()) {
                    throw new Exception();
                }
                break;
        }
        $this->behavior->receive($context);
    }

    /**
     * @param ContextInterface $context
     * @return void
     */
    private function starting(ContextInterface $context): void
    {
        if ($context->message() instanceof ProtoBuf\TransferStarted) {
            $context->spawnNamed($this->tryDebit($this->from, -$this->amount), 'DebitAttempt');
            $this->persistenceReceive(new ProtoBuf\TransferStarted());
        }
    }

    private function tryDebit(Ref $targetActor, float $amount): Props
    {
        return Props::fromProducer(
            fn() => new AccountProxy(
                $targetActor,
                fn($sender) => new Debit($amount, $sender)
            )
        );
    }

    private function applyEvent(Message $event): void
    {
        // Console.WriteLine($"Applying event: {@event.Data}");
        switch (true) {
            case $event instanceof ProtoBuf\TransferStarted:
                $this->behavior->become(
                    new ReceiveFunction(
                        fn($context) => $this->awaitingDebitConfirmation($context)
                    )
                );
                break;
            case $event instanceof ProtoBuf\AccountDebited:
                $this->behavior->become(
                    new ReceiveFunction(
                        fn($context) => $this->awaitingCreditConfirmation($context)
                    )
                );
                break;
            case $event instanceof ProtoBuf\CreditRefused:
                $this->behavior->become(
                    new ReceiveFunction(
                        fn($context) => $this->rollingBackDebit($context)
                    )
                );
                break;
            case $event instanceof ProtoBuf\AccountCredited:
            case $event instanceof ProtoBuf\DebitRolledBack:
            case $event instanceof ProtoBuf\TransferFailed:
                $this->processCompleted = true;
                break;
        }
    }

    /**
     * @param ContextInterface $context
     * @return void
     */
    private function awaitingDebitConfirmation(ContextInterface $context): void
    {
        $message = $context->message();
        switch (true) {
            case $message instanceof Started:
                $context->spawnNamed(
                    $this->tryDebit($this->from, -$this->amount),
                    'DebitAttempt'
                );
                break;
            case $message instanceof Ok:
                $this->persistenceReceive(new ProtoBuf\AccountDebited());
                $context->spawnNamed(
                    $this->tryCredit($this->to, +$this->amount),
                    'CreditAttempt'
                );
                break;
            case $message instanceof Refused:
                $parent = $context->parent();
                $self = $context->self();
                $this->persistenceReceive(new ProtoBuf\TransferFailed('Debit refused'));
                $context->send($parent, new ProtoBuf\FailedButConsistentResult([
                        'from' => $self->protobufPid()
                    ]
                ));
                $this->stopAll($context);
                break;
            case $message instanceof Terminated:
                $this->persistenceReceive(new ProtoBuf\StatusUnknown());
                $this->stopAll($context);
                break;
        }
    }

    private function tryCredit(Ref $targetActor, float $amount): Props
    {
        return Props::fromProducer(
            fn() => new AccountProxy(
                $targetActor,
                fn($sender) => new Credit($amount, $sender)
            )
        );
    }

    private function stopAll(ContextInterface $context): void
    {
        $context->stop($this->from);
        $context->stop($this->to);
        $context->stop($context->self());
    }

    /**
     * @param ContextInterface $context
     * @return void
     */
    private function awaitingCreditConfirmation(ContextInterface $context): void
    {
        $message = $context->message();
        switch (true) {
            case $message instanceof Started:
                $context->spawnNamed(
                    $this->tryCredit($this->to, +$this->amount),
                    'CreditAttempt'
                );
                break;
            case $message instanceof Ok:
                $parent = $context->parent();
                $fromBalance = $context->requestFuture($this->from, new GetBalance(), 2000);
                $fromBalanceResult = $fromBalance->result()->value();
                $toBalance = $context->requestFuture($this->to, new GetBalance(), 2000);
                $toBalanceResult = $toBalance->result()->value();
                $this->persistenceReceive(new ProtoBuf\AccountDebited());
                $completed = new ProtoBuf\TransferCompleted();
                $this->persistenceReceive(
                    $completed->setFromBalance((float)$fromBalanceResult)
                        ->setToBalance((float)$toBalanceResult)
                        ->setFrom($this->from->protobufPid())
                        ->setTo($this->to->protobufPid())
                );
                $context->send($parent, new SuccessResult([
                    'from' => $context->self()->protobufPid()
                ]));
                $this->stopAll($context);
                break;
            case $message instanceof Refused:
                $this->persistenceReceive(new ProtoBuf\CreditRefused());
                $context->spawnNamed(
                    $this->tryCredit($this->from, +$this->amount),
                    'RollbackDebit'
                );
                break;
            case $message instanceof Terminated:
                $this->persistenceReceive(new ProtoBuf\StatusUnknown());
                $this->stopAll($context);
                break;
        }
    }

    /**
     * @param ContextInterface $context
     * @return void
     */
    private function rollingBackDebit(ContextInterface $context): void
    {
        $message = $context->message();
        switch (true) {
            case $message instanceof Started:
                $context->spawnNamed(
                    $this->tryCredit($this->from, +$this->amount),
                    'RollbackDebit'
                );
                break;
            case $message instanceof Ok:
                $this->persistenceReceive(new ProtoBuf\DebitRolledBack());
                $this->persistenceReceive(
                    new ProtoBuf\TransferFailed(
                        sprintf('Unable to rollback debit to %s', $this->to->protobufPid()->getId())
                    )
                );
                $context->send(
                    $context->parent(),
                    new ProtoBuf\FailedAndInconsistent([
                        'from' => $context->self()->protobufPid(),
                    ])
                );
                $this->stopAll($context);
                break;
            case $message instanceof Refused:
            case $message instanceof Terminated:
                $this->persistenceReceive(
                    new ProtoBuf\TransferFailed(
                        sprintf(
                            'Unable to rollback process. %s is owed %s',
                            $this->to->protobufPid()->getId(),
                            $this->amount
                        )
                    )
                );
                $this->persistenceReceive(
                    new ProtoBuf\EscalateTransfer(
                        sprintf(
                            '%s is owed %s',
                            $this->to->protobufPid()->getId(),
                            $this->amount
                        )
                    )
                );
                $context->send(
                    $context->parent(),
                    new ProtoBuf\FailedAndInconsistent([
                        'from' => $context->self()->protobufPid(),
                    ])
                );
                $this->stopAll($context);
                break;
        }
    }

    /**
     * @throws RandomException
     */
    private function fail(): bool
    {
        $comparison = random_int(0, 100);
        return $comparison > $this->availability;
    }

    public function receiveRecover(mixed $message): void
    {
        if ($message instanceof Message) {
            $this->applyEvent($message);
        }
    }
}
