<?php

$commonConfig = require __DIR__ . '/common.php';

$config = [
    'id' => 'yii2-swoole-example',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'request' => [
            'cookieValidationKey' => 'test-secret-key',
            'enableCsrfValidation' => false, // Disable CSRF for API testing
        ],
        'session' => [
            'class' => \Dacheng\Yii2\Swoole\Session\CoroutineSession::class,
            'redis' => 'redis',
            'keyPrefix' => 'phpsession:',
            'timeout' => (int)(getenv('YII_SESSION_TIMEOUT') ?: 1440),
        ],
        'user' => [
            'class' => \Dacheng\Yii2\Swoole\User\CoroutineUser::class,
            'identityClass' => \dacheng\app\models\UserIdentity::class,
            'enableAutoLogin' => false,
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'flushInterval' => 1,
            'targets' => [
                [
                    'class' => \Dacheng\Yii2\Swoole\Log\CoroutineFileTarget::class,
                    'levels' => ['error', 'warning', 'info'],
                    'exportInterval' => 1,
                    'logFile' => '@runtime/logs/app.log',
                    'channelSize' => (int)(getenv('YII_LOG_CHANNEL_SIZE') ?: 10000),
                    'pushTimeout' => 0.5,
                    'batchSize' => 1000, // Packets per batch write
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

$config = \yii\helpers\ArrayHelper::merge($commonConfig, $config);

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
