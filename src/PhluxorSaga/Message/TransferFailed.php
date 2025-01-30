<?php

declare(strict_types=1);

namespace PhluxorSaga\Message;

readonly class TransferFailed
{
    public function __construct(
        public string $reason
    ) {}
}
