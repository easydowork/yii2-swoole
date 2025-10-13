<?php

use yii\helpers\ArrayHelper;

$commonConfig = require __DIR__ . '/common.php';

$config = [
    'id' => 'yii2-swoole-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'controllerMap' => [],
    'components' => [
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\\log\\FileTarget',
                    'levels' => ['error', 'warning'],
                    'maxFileSize' => 102400,
                ],
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];

return ArrayHelper::merge($commonConfig, $config);
