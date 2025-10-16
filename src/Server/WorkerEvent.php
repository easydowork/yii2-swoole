<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Swoole\Http\Server as SwooleHttpServer;
use yii\base\Event;

class WorkerEvent extends Event
{
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    public SwooleHttpServer $server;

    public int $workerId;
}
