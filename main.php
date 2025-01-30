<?php

declare(strict_types=1);

use Brick\Math\Exception\MathException;
use Phluxor\ActorSystem;

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
        $system->shutdown();
    });
});
