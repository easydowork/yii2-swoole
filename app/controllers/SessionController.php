<?php

declare(strict_types=1);

namespace dacheng\app\controllers;

use Swoole\Coroutine;
use Yii;
use yii\web\Controller;

class SessionController extends Controller
{
    public function actionCounter()
    {
        $session = Yii::$app->session;
        $session->open();

        $counter = (int) $session->get('counter', 0);
        $counter++;
        $session->set('counter', $counter);

        return $this->asJson([
            'cid' => Coroutine::getCid(),
            'session_id' => $session->getId(),
            'counter' => $counter,
        ]);
    }

    public function actionReset()
    {
        $session = Yii::$app->session;
        $session->open();
        $session->remove('counter');

        return $this->asJson([
            'message' => 'Session counter cleared',
        ]);
    }
}
