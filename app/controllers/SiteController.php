<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;

class SiteController extends Controller
{
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
        ];
    }

    /**
     * Displays homepage.
     */
    public function actionIndex()
    {
        return $this->asJson([
            'message' => 'Welcome to Yii2 Swoole HTTP Server!',
            'timestamp' => time(),
            'server' => 'Swoole ' . swoole_version(),
            'yii' => Yii::getVersion(),
        ]);
    }

    /**
     * Test action with parameters
     */
    public function actionTest()
    {
        $request = Yii::$app->request;
        
        return $this->asJson([
            'method' => $request->method,
            'path' => $request->pathInfo,
            'query' => $request->queryParams,
            'post' => $request->bodyParams,
            'headers' => $request->headers->toArray(),
            'cookies' => array_keys($request->cookies->toArray()),
        ]);
    }

    /**
     * Test cookie setting
     */
    public function actionSetCookie()
    {
        $response = Yii::$app->response;
        $response->cookies->add(new \yii\web\Cookie([
            'name' => 'test_cookie',
            'value' => 'cookie_value_' . time(),
            'expire' => time() + 3600,
        ]));

        return $this->asJson([
            'message' => 'Cookie set successfully',
        ]);
    }

    /**
     * Test cookie reading
     */
    public function actionGetCookie()
    {
        $request = Yii::$app->request;
        $cookieValue = $request->cookies->getValue('test_cookie', 'not set');

        return $this->asJson([
            'test_cookie' => $cookieValue,
            'all_cookies' => $request->cookies->toArray(),
        ]);
    }

    /**
     * Test coroutine with sleep
     */
    public function actionSleep()
    {
        $seconds = (int) Yii::$app->request->get('seconds', 1);
        $seconds = min($seconds, 5); // Max 5 seconds
        
        \Swoole\Coroutine::sleep($seconds);
        
        return $this->asJson([
            'message' => "Slept for {$seconds} seconds using coroutine",
            'timestamp' => time(),
        ]);
    }
}
