<?php

use yii\helpers\ArrayHelper;
use Dacheng\Yii2\Swoole\Db\CoroutineConnection;

$swooleConfig = require __DIR__ . '/swoole.php';

$config = [
    'id' => 'yii2-swoole-example',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'request' => [
            'cookieValidationKey' => 'test-secret-key',
            'enableCsrfValidation' => false, // Disable CSRF for API testing
        ],
        'cache' => [
            'class' => \yii\caching\ArrayCache::class,
            'serializer' => false,
        ],
        'db' => [
            'class' => CoroutineConnection::class,
            'dsn' => getenv('YII_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=yii2swoole',
            'username' => getenv('YII_DB_USERNAME') ?: 'root',
            'password' => getenv('YII_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'poolMaxActive' => (int) (getenv('YII_DB_POOL_MAX_ACTIVE') ?: 8),
            'poolMinActive' => (int) (getenv('YII_DB_POOL_MIN_ACTIVE') ?: 2),
            'poolWaitTimeout' => (float) (getenv('YII_DB_POOL_WAIT_TIMEOUT') ?: 5.0),
            // cache schema can improve performance a lot (60%)
            'enableSchemaCache' => true,
            'schemaCacheDuration' => (int) (getenv('YII_DB_SCHEMA_CACHE_DURATION') ?: 3600),
            'schemaCache' => 'cache',
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'flushInterval' => 1,
            'targets' => [
                [
                    'class' => 'yii\\log\\FileTarget',
                    'levels' => ['error', 'warning'],
                    'exportInterval' => 1,
                    'logFile' => '@runtime/logs/app.log',
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
