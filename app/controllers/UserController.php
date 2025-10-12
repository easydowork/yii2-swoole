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

    public function actionView2(int $id)
    {
        $row = User::find()
            ->where(['id' => $id])
            ->select(['id', 'name'])
            ->asArray()
            ->one();

        if ($row === null) {
            throw new NotFoundHttpException('User not found.');
        }

        return $this->asJson([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ]);
    }

    public function actionViewCommand(int $id)
    {
        $row = Yii::$app->db->createCommand(
            'SELECT id, name FROM {{%user}} WHERE id = :id',
            [':id' => $id]
        )->queryOne();

        if ($row === false) {
            throw new NotFoundHttpException('User not found.');
        }

        return $this->asJson([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ]);
    }
}
