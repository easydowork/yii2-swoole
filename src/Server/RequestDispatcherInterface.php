<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Coroutine\Http\Server as SwooleCoroutineHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;

interface RequestDispatcherInterface
{
    public function dispatch(Request $request, Response $response, SwooleCoroutineHttpServer $server): void;
}
