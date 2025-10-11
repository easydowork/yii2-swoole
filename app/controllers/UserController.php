<?php

namespace app\controllers;

use app\models\User;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class UserController extends Controller
{
    public function actionView(int $id)
    {
        $user = User::findOne($id);

        if ($user === null) {
            throw new NotFoundHttpException('User not found.');
        }

        return $this->asJson([
            'id' => (int) $user->id,
            'name' => (string) $user->name,
        ]);
    }
}
