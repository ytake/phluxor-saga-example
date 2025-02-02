<?php

declare(strict_types=1);

namespace PhluxorSaga\Internal;

use Closure;

readonly class ForWithProgress
{

    public function __construct(
        private int $total,
        private int $everyNth,
        private bool $runBothOnEvery,
        private bool $runOnStart)
    {}

    /**
     * @param Closure(int): void  $everyNthAction
     * @param Closure(int, bool): void $everyAction
     * @return void
     */
    public function everyNth(Closure $everyNthAction, Closure $everyAction): void
    {
        for ($i = 1; $i <= $this->total; $i++) {
            $must = $this->mustRunNth($i);

            if ($must) {
                $everyNthAction($i);
            }

            if ($must && !$this->runBothOnEvery) {
                continue;
            }

            $everyAction($i, $must);
        }
    }

    /**
     * Determines if the current iteration meets the condition to run based on the given parameters.
     *
     * @param int $current The current iteration number.
     * @return bool Returns true if the condition to run is met; otherwise, false.
     */
    private function mustRunNth(int $current): bool
    {
        if ($current === 0) {
            return $this->runOnStart;
        }
        return $current % $this->everyNth === 0;
    }
}
