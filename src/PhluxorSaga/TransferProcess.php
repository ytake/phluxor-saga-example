<?php

declare(strict_types=1);

namespace PhluxorSaga;

use Phluxor\ActorSystem\Behavior;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Message\ReceiveFunction;
use Phluxor\ActorSystem\Message\Restarting;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\ActorSystem\Message\Stopped;
use Phluxor\ActorSystem\Message\Stopping;
use Phluxor\ActorSystem\Props;
use Phluxor\ActorSystem\ProtoBuf\Terminated;
use Phluxor\ActorSystem\Ref;
use Phluxor\Persistence\Message\OfferSnapshot;
use Phluxor\Persistence\Mixin;
use Phluxor\Persistence\PersistentInterface;
use PhluxorSaga\Message\UnknownResult;
use PhluxorSaga\ProtoBuf\AccountDebited;
use PhluxorSaga\Message\Credit;
use PhluxorSaga\Message\Debit;
use PhluxorSaga\Message\FailedButConsistentResult;
use PhluxorSaga\Message\Ok;
use PhluxorSaga\Message\Refused;
use PhluxorSaga\ProtoBuf\DebitRolledBack;
use PhluxorSaga\ProtoBuf\EscalateTransfer;
use PhluxorSaga\ProtoBuf\FailedAndInconsistent;
use PhluxorSaga\ProtoBuf\StatusUnknown;
use PhluxorSaga\ProtoBuf\TransferFailed;
use PhluxorSaga\ProtoBuf\TransferStarted;

class TransferProcess implements ActorInterface, PersistentInterface
{
    use Mixin;

    private bool $processCompleted = false;
    private bool $restarting = false;
    private bool $stopping = false;

    public function __construct(
        private Ref $from,
        private Ref $to,
        private float $amount,
        private PersistentInterface $persistence,
        private string $persistenceId,
        private float $availability,
        private Behavior $behavior = new Behavior()
    ) {}

    public function receive(ContextInterface $context): void
    {
        $message = $context->message();

        switch (true) {
            case $message instanceof Started:
                $this->behavior->become(new ReceiveFunction(
                    fn($context) => $this->starting($context)
                ));
                break;
            case $message instanceof OfferSnapshot:
                    // $message->snapshot();
                break;
            case $message instanceof Stopping:
                $this->stopping = true;
                break;
            case $message instanceof Restarting:
                $this->restarting = true;
                break;
                // case Stopped _ when !_processCompleted:
            case $message instanceof Stopped && !$this->processCompleted:
                $this->persistenceReceive(
                    new TransferFailed('Process stopped unexpectedly'));
                $this->persistenceReceive(
                    new EscalateTransfer('Unknown failure. Transfer Process crashed'));
                $context->send($context->parent(), new UnknownResult($context->self()));
                break;
            case $message instanceof Stopped && ($this->restarting || $this->stopping):
                break;
        }
    }

    private function tryCredit(Ref $targetActor, float $amount): Props
    {
        return Props::fromProducer(
            fn() => new AccountProxy(
                $targetActor,
                fn($sender) => new Credit($amount, $sender))
        );
    }

    private function tryDebit(Ref $targetActor, float $amount): Props
    {
        return Props::fromProducer(
            fn() => new AccountProxy(
                $targetActor,
                fn($sender) => new Debit($amount, $sender))
        );
    }

    private function starting(ContextInterface $context): void
    {
        if ($context->message() instanceof TransferStarted) {
            $context->spawnNamed($this->tryDebit($this->from, -$this->amount), 'DebitAttempt');
            $this->persistenceReceive(new TransferStarted());
        }
    }

    private function rollingBackDebit(ContextInterface $context): void
    {
        $message = $context->message();
        switch (true) {
            case $message instanceof Started:
                $context->spawnNamed(
                    $this->tryCredit($this->from, +$this->amount), 'RollbackDebit');
                break;
            case $message instanceof Ok:
                $this->persistenceReceive(new DebitRolledBack());
                $this->persistenceReceive(
                    new TransferFailed(
                        sprintf('Unable to rollback debit to %s', $this->to->protobufPid()->getId())
                    ));
                $context->send($context->parent(), new FailedAndInconsistent($context->self()));
                $this->stopAll($context);
                break;
            case $message instanceof Refused:
            case $message instanceof Terminated:
                $this->persistenceReceive(
                    new TransferFailed(
                        sprintf('Unable to rollback process. %s is owed %s',
                            $this->to->protobufPid()->getId(),
                            $this->amount
                        )
                    ));
                $this->persistenceReceive(
                    new EscalateTransfer(
                        sprintf('%s is owed %s',
                            $this->to->protobufPid()->getId(),
                            $this->amount
                        )
                    ));
                $context->send($context->parent(), new FailedAndInconsistent($context->self()));
                $this->stopAll($context);
                break;
        }
    }

    private function stopAll(ContextInterface $context): void
    {
        $context->stop($this->from);
        $context->stop($this->to);
        $context->stop($context->self());
    }
}
