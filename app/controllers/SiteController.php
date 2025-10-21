<?php

namespace dacheng\app\controllers;

use Throwable;
use Yii;
use yii\base\Exception as YiiException;
use yii\web\Controller;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SiteController extends Controller
{
    public function actions()
    {
        return [];
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

    public function actionError()
    {
        $exception = Yii::$app->errorHandler->exception;

        if ($exception === null) {
            $exception = new NotFoundHttpException('Page not found.');
        }

        $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;
        $response = Yii::$app->response;
        $response->setStatusCode($statusCode);
        $statusText = $response->statusText ?: (Response::$httpStatuses[$statusCode] ?? 'Error');

        $data = [
            'name' => $this->resolveExceptionName($exception, $statusText),
            'message' => $exception->getMessage() ?: $statusText,
            'status' => $statusCode,
        ];

        if (YII_DEBUG) {
            $data['type'] = get_class($exception);
            $data['file'] = $exception->getFile();
            $data['line'] = $exception->getLine();
            $data['trace'] = explode(PHP_EOL, $exception->getTraceAsString());
        }

        return $this->asJson($data);
    }

    private function resolveExceptionName(Throwable $exception, string $statusText): string
    {
        if ($exception instanceof HttpException) {
            return $statusText;
        }

        if ($exception instanceof YiiException) {
            return $exception->getName();
        }

        return 'Error';
    }
}
