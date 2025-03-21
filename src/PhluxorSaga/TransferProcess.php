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
        $this->behavior->become(
            new ReceiveFunction(
                fn($context) => $this->starting($context)
            )
        );
    }

    /**
     * @param ContextInterface $context
     * @return void
     */
    private function starting(ContextInterface $context): void
    {
        if ($context->message() instanceof Started) {
            $context->spawnNamed($this->tryDebit($this->from, -$this->amount), 'DebitAttempt');
            $this->persistEvent(new ProtoBuf\TransferStarted());
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
                    $this->persistEvent(
                        new ProtoBuf\TransferFailed([
                            'reason' => 'Process stopped unexpectedly'
                        ])
                    );
                    $this->persistEvent(
                        new ProtoBuf\EscalateTransfer([
                            'reason' => 'Unknown failure. Transfer Process crashed'
                        ])
                    );
                }
                $context->send($parent, new UnknownResult($self));
                break;
            case $message instanceof Stopped && ($this->restarting || $this->stopping):
                break;
            default:
                if ($this->fail()) {
                    throw new Exception();
                }
                break;
        }
        $this->behavior->receive($context);
    }

    private function applyEvent(Message $event): void
    {
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
        $self = $context->self();
        switch (true) {
            // if we are in this state when restarted then we need to recreate the TryDebit actor
            // もし再起動時にこの状態にいる場合は、TryDebitアクターを再作成する必要があり
            case $message instanceof Started:
                $context->spawnNamed(
                    $this->tryDebit($this->from, -$this->amount),
                    'DebitAttempt'
                );
                break;
            case $message instanceof Ok:
                $this->persistEvent(new ProtoBuf\AccountDebited());
                $context->spawnNamed(
                    $this->tryCredit($this->to, +$this->amount),
                    'CreditAttempt'
                );
                break;
            case $message instanceof Refused:
                $parent = $context->parent();
                $this->persistEvent(new ProtoBuf\TransferFailed([
                    'reason' => 'Debit refused'
                ]));
                $context->send($parent, new ProtoBuf\FailedButConsistentResult([
                    'from' => $self->protobufPid()
                ]));
                $this->stopAll($context, $self);
                break;
            case $message instanceof Terminated:
                $this->persistEvent(new ProtoBuf\StatusUnknown());
                $this->stopAll($context, $self);
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

    /**
     * @param ContextInterface $context
     * @param Ref $self
     * @return void
     */
    private function stopAll(ContextInterface $context, Ref $self): void
    {
        $context->stop($this->from);
        $context->stop($this->to);
        $context->stop($self);
    }

    /**
     * @param ContextInterface $context
     * @return void
     */
    private function awaitingCreditConfirmation(ContextInterface $context): void
    {
        $message = $context->message();
        $self = $context->self();
        switch (true) {
            case $message instanceof Started:
                $context->spawnNamed(
                    $this->tryCredit($this->to, +$this->amount),
                    'CreditAttempt'
                );
                break;
            case $message instanceof Ok:
                $parent = $context->parent();
                $fromBalance = $context->requestFuture($this->from, new GetBalance(), 2);
                $fromBalanceResult = $fromBalance->result()->value();
                $toBalance = $context->requestFuture($this->to, new GetBalance(), 2);
                $toBalanceResult = $toBalance->result()->value();
                $this->persistEvent(new ProtoBuf\AccountCredited());
                $completed = new ProtoBuf\TransferCompleted();
                $this->persistEvent(
                    $completed->setFromBalance((float)$fromBalanceResult)
                        ->setToBalance((float)$toBalanceResult)
                        ->setFrom($this->from->protobufPid())
                        ->setTo($this->to->protobufPid())
                );
                $context->send($parent, new SuccessResult([
                    'from' => $self->protobufPid()
                ]));
                $this->stopAll($context, $self);
                break;
            case $message instanceof Refused:
                $this->persistEvent(new ProtoBuf\CreditRefused());
                $context->spawnNamed(
                    $this->tryCredit($this->from, +$this->amount),
                    'RollbackDebit'
                );
                break;
            case $message instanceof Terminated:
                $this->persistEvent(new ProtoBuf\StatusUnknown());
                $this->stopAll($context, $self);
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
        $self = $context->self();
        switch (true) {
            case $message instanceof Started:
                $context->spawnNamed(
                    $this->tryCredit($this->from, +$this->amount),
                    'RollbackDebit'
                );
                break;
            case $message instanceof Ok:
                $parent = $context->parent();
                $this->persistEvent(new ProtoBuf\DebitRolledBack());
                $this->persistEvent(
                    new ProtoBuf\TransferFailed([
                        'reason' => sprintf('Unable to rollback debit to %s', $this->to->protobufPid()->getId())
                    ])
                );
                $context->send(
                    $parent,
                    new ProtoBuf\FailedAndInconsistent([
                        'from' => $self->protobufPid(),
                    ])
                );
                $this->stopAll($context, $self);
                break;
            case $message instanceof Refused:
            case $message instanceof Terminated:
                $parent = $context->parent();
                $self = $context->self();
                $this->persistEvent(
                    new ProtoBuf\TransferFailed([
                        'reason' => sprintf(
                            'Unable to rollback process. %s is owed %s',
                            $this->to->protobufPid()->getId(),
                            $this->amount
                        )
                    ])
                );
                $this->persistEvent(
                    new ProtoBuf\EscalateTransfer(
                        sprintf(
                            '%s is owed %s',
                            $this->to->protobufPid()->getId(),
                            $this->amount
                        )
                    )
                );
                $context->send(
                    $parent,
                    new ProtoBuf\FailedAndInconsistent([
                        'from' => $self->protobufPid(),
                    ])
                );
                $this->stopAll($context, $self);
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

    private function persistEvent(Message $event): void
    {
        $this->persistenceReceive($event);
        $this->applyEvent($event);
    }
}
