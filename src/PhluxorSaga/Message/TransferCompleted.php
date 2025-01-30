<?php

declare(strict_types=1);

namespace PhluxorSaga\Message;

use Phluxor\ActorSystem\Ref;

readonly class TransferCompleted
{
    public function __construct(
        public Ref $from,
        public float $fromBalance,
        public Ref $to,
        public float $toBalance
    ) {}
}
