<?php

declare(strict_types=1);

namespace PhluxorSaga\Message;

use Phluxor\ActorSystem\Ref;

readonly abstract class ChangeBalance
{
    public function __construct(
        public float $amount,
        public Ref $replyTo
    ) {}
}
