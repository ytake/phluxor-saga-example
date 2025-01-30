<?php

declare(strict_types=1);
namespace PhluxorSaga\Message;

readonly class EscalateTransfer
{
    public function __construct(
        public string $message
    ) {}
}
