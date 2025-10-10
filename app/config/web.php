<?php

use yii\helpers\ArrayHelper;

$swooleConfig = require __DIR__ . '/swoole.php';

$config = [
    'id' => 'yii2-swoole-example',
    'basePath' => dirname(__DIR__),
    'bootstrap' => [],
    'components' => [
        'request' => [
            'cookieValidationKey' => 'test-secret-key',
            'enableCsrfValidation' => false, // Disable CSRF for API testing
        ],
        'cache' => [
            'class' => \yii\caching\DummyCache::class,
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'yii\\log\\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                '' => 'site/index',
                '<controller:\\w+>/<action:\\w+>' => '<controller>/<action>',
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];

$config = ArrayHelper::merge($config, $swooleConfig);

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment when optional packages are present
    if (class_exists('yii\\debug\\Module')) {
        $config['bootstrap'][] = 'debug';
        $config['modules']['debug'] = [
            'class' => 'yii\\debug\\Module',
        ];
    }

    if (class_exists('yii\\gii\\Module')) {
        $config['bootstrap'][] = 'gii';
        $config['modules']['gii'] = [
            'class' => 'yii\\gii\\Module',
        ];
    }
}

return $config;
