<?php

declare(strict_types=1);

namespace PhluxorSaga;

use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ActorInterface;
use Phluxor\ActorSystem\Ref;
use PhluxorSaga\Message;
use Random\RandomException;

class Account implements ActorInterface
{
    private float $balance = 10.0;

    /** @var array<string, mixed> */
    private array $processedMessages = [];

    public function __construct(
        private readonly float $serviceUptime,
        private readonly float $refusalProbability,
        private readonly float $busyProbability
    ) {
    }

    /**
     * @throws RandomException
     */
    public function receive(ContextInterface $context): void
    {
        $message = $context->message();
        switch (true) {
            case $message instanceof Message\Credit:
            case $message instanceof Message\Debit:
                $this->handleBalanceChange($context, $message);
                break;
            case $message instanceof Message\GetBalance:
                $context->respond($this->balance);
                break;
        }
    }

    /**
     * @throws RandomException
     */
    private function handleBalanceChange(
        ContextInterface $context,
        Message\ChangeBalance $message
    ): void {
        if ($this->alreadyProcessed($message->replyTo)) {
            $context->send($message->replyTo, $this->processedMessages[(string) $message->replyTo]);
            return;
        }

        if ($message instanceof Message\Debit && ($message->amount + $this->balance) < 0) {
            $context->send($message->replyTo, new Message\InsufficientFunds());
            return;
        }

        if ($this->refusePermanently()) {
            $this->processedMessages[(string) $message->replyTo] = new Message\Refused();
            $context->send($message->replyTo, new Message\Refused());
            return;
        }

        if ($this->isBusy()) {
            $context->send($message->replyTo, new Message\ServiceUnavailable());
            return;
        }

        if ($this->shouldFailBeforeProcessing()) {
            $context->send($message->replyTo, new Message\InternalServerError());
            return;
        }
        usleep(random_int(0, 150) * 100);
        $this->balance += $message->amount;
        $this->processedMessages[(string) $message->replyTo] = new Message\Ok();

        if ($this->shouldFailAfterProcessing()) {
            $context->send($message->replyTo, new Message\InternalServerError());
            return;
        }
        $context->send($message->replyTo, new Message\Ok());
    }

    /**
     * @return bool
     * @throws RandomException
     */
    private function isBusy(): bool
    {
        return random_int(0, 100) / 100.0 <= $this->busyProbability;
    }

    /**
     * @return bool
     * @throws RandomException
     */
    private function refusePermanently(): bool
    {
        return random_int(0, 100) / 100.0 <= $this->refusalProbability;
    }

    /**
     * @return bool
     * @throws RandomException
     */
    private function shouldFailBeforeProcessing(): bool
    {
        return random_int(0, 100) / 100.0 > $this->serviceUptime / 2;
    }

    /**
     * @return bool
     * @throws RandomException
     */
    private function shouldFailAfterProcessing(): bool
    {
        return random_int(0, 100) / 100.0 > $this->serviceUptime;
    }

    private function alreadyProcessed(Ref $replyTo): bool
    {
        return isset($this->processedMessages[(string) $replyTo]);
    }
}
