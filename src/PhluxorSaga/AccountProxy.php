<?php

declare(strict_types=1);

namespace PhluxorSaga;

use Closure;
use DateInterval;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Message\ReceiveTimeout;
use Phluxor\ActorSystem\Message\Started;
use Phluxor\ActorSystem\Ref;
use PhluxorSaga\Message\InsufficientFunds;
use PhluxorSaga\Message\InternalServerError;
use PhluxorSaga\Message\Ok;
use PhluxorSaga\Message\Refused;
use PhluxorSaga\Message\ServiceUnavailable;
use RuntimeException;

readonly class AccountProxy implements ActorInterface
{
    /**
     * @param Ref $target
     * @param Closure(Ref, mixed): mixed $createMessage
     */
    public function __construct(
        private Ref $target,
        private Closure $createMessage
    ) {
    }

    public function receive(ContextInterface $context): void
    {
        $message = $context->message();
        switch (true) {
            case $message instanceof Started:
                $context->send($this->target, ($this->createMessage)($context->self()));
                $context->setReceiveTimeout(new DateInterval('PT0.1S'));
                break;
            case $message instanceof Refused:
            case $message instanceof Ok:
                $context->cancelReceiveTimeout();
                $context->send($context->parent(), $message);
                break;
            case $message instanceof InsufficientFunds:
            case $message instanceof InternalServerError:
            case $message instanceof ReceiveTimeout:
            case $message instanceof ServiceUnavailable:
                throw new RuntimeException('Unexpected message');
        }
    }
}
