<?php

declare(strict_types=1);

namespace PhluxorSaga;

use Closure;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Context\ReceiverInterface;
use Phluxor\ActorSystem\Message\MessageEnvelope;
use Phluxor\ActorSystem\Message\ReceiverFunctionInterface;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\ActorSystem\Props\ReceiverMiddlewareInterface;
use Phluxor\Persistence\PersistentInterface;
use Phluxor\Persistence\ProviderInterface;

readonly class EventSourcedFactory implements ReceiverMiddlewareInterface
{
    /**
     * @param ProviderInterface $provider
     */
    public function __construct(
        private ProviderInterface $provider,
    ) {
    }

    public function __invoke(ReceiverFunctionInterface|Closure $next): ReceiverFunctionInterface
    {
        return new readonly class($this->provider, $next) implements ReceiverFunctionInterface {
            /**
             * @param ProviderInterface $provider
             * @param Closure(ReceiverInterface|ContextInterface, MessageEnvelope): void|ReceiverFunctionInterface $next
             */
            public function __construct(
                private ProviderInterface $provider,
                private ReceiverFunctionInterface|Closure $next
            ) {
            }

            public function __invoke(ContextInterface|ReceiverInterface $context, MessageEnvelope $messageEnvelope): void
            {
                $msg = $messageEnvelope->getMessage();
                $next = $this->next;
                if ($msg instanceof Started) {
                    $actor = $context->actor();
                    if ($actor instanceof PersistentInterface) {
                        $actor->init($this->provider, $context);
                    }
                }
                $next($context, $messageEnvelope);
            }
        };
    }
}
