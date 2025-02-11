<?php

declare(strict_types=1);

namespace PhluxorSaga;

use DateInterval;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Props;
use Phluxor\ActorSystem\Ref;
use Phluxor\ActorSystem\SpawnResult;
use Phluxor\ActorSystem\Strategy\OneForOneStrategy;
use Phluxor\ActorSystem\Supervision\DefaultDecider;
use Phluxor\Persistence\ProviderInterface;

readonly class TransferFactory
{
    public function __construct(
        private ContextInterface $context,
        private float $availability,
        private int $retryAttempts,
        private ProviderInterface $provider,
    ) {
    }

    /**
     * @param string $actorName
     * @param Ref $fromAccount
     * @param Ref $toAccount
     * @param float $amount
     * @return SpawnResult
     */
    public function createTransfer(
        string $actorName,
        Ref $fromAccount,
        Ref $toAccount,
        float $amount,
    ): SpawnResult {
        $props = Props::fromProducer(
            fn() => new TransferProcess($fromAccount, $toAccount, $amount, $this->availability),
            Props::withReceiverMiddleware(
                new EventSourcedFactory($this->provider)
            ),
            Props::withSupervisor(
                new OneForOneStrategy(
                    $this->retryAttempts,
                    new DateInterval('PT10S'),
                    new DefaultDecider(),
                )
            )
        );
        return $this->context->spawnNamed($props, $actorName);
    }
}
