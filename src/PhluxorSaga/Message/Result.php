<?php

declare(strict_types=1);

namespace PhluxorSaga\Message;

use Phluxor\ActorSystem\Ref;
readonly abstract class Result
{
    public function __construct(
        public Ref $sender
    ) {
    }
}
