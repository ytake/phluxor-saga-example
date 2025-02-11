<?php

declare(strict_types=1);

use Brick\Math\Exception\MathException;
use Phluxor\ActorSystem;

use PhluxorSaga\Runner;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

require_once 'vendor/autoload.php';

run(function () {
    go(
    /**
     * @throws MathException
     */
    function () {
        $system = ActorSystem::create();
        $system->getLogger()->info('Starting');
        $numberOfTransfers = 10;
        $intervalBetweenConsoleUpdates = 1;
        $uptime = 99.99;
        $retryAttempts = 3;
        $refusalProbability = 0.01;
        $busyProbability = 0.01;
        $props = ActorSystem\Props::fromProducer(
            fn() => new Runner(
                $numberOfTransfers,
                $intervalBetweenConsoleUpdates,
                $uptime,
                $refusalProbability,
                $busyProbability,
                $retryAttempts
            ),
            ActorSystem\Props::withSupervisor(
                new ActorSystem\Strategy\OneForOneStrategy(
                    $retryAttempts,
                    new DateInterval('PT10S'),
                    new ActorSystem\Supervision\DefaultDecider(),
                )
            ),
        );
        $system->root()->spawnNamed($props, 'runner');
    });
});
