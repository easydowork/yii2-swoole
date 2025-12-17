<?php

use yii\helpers\BaseArrayHelper;

$commonConfig = require __DIR__ . '/common.php';

$config = [
    'id' => 'yii2-swoole-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'controllerMap' => [],
    'components' => [
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'flushInterval' => 1,
            'targets' => [
                [
                    'class' => \Dacheng\Yii2\Swoole\Log\CoroutineFileTarget::class,
                    'levels' => YII_DEBUG ? ['error', 'warning', 'info'] : ['error', 'warning'],
                    'exportInterval' => 1,
                    'logFile' => '@runtime/logs/console.log',
                    'maxFileSize' => 10240, // 10MB
                    'maxLogFiles' => 5,
                    'enableRotation' => true,
                    'categories' => [],
                    'except' => [],
                    'logVars' => [],
                    'microtime' => true,
                ],
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];

return BaseArrayHelper::merge($commonConfig, $config);
