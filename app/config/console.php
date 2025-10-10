<?php

use yii\helpers\ArrayHelper;

$swooleConfig = require __DIR__ . '/swoole.php';

$config = [
    'id' => 'yii2-swoole-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'controllerMap' => [],
    'components' => [
        'cache' => [
            'class' => \yii\caching\DummyCache::class,
        ],
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\\log\\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];

return ArrayHelper::merge($config, $swooleConfig);
