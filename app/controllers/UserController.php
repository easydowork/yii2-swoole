<?php

namespace dacheng\app\controllers;

use dacheng\app\models\User;
use dacheng\app\models\UserIdentity;
use Swoole\Coroutine;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class UserController extends Controller
{
    /**
     * Fetch a user by id, using traditional ActiveRecord with 'asArray'.
     */
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

    /**
     * Fetch a user by id, using 'asArray'.
     */
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

    /**
     * Fetch a user by id, using raw query.
     */
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

    /**
     * Login by user id.
     *
     * It's simple and for demo purpose only.
     */
    public function actionLogin(int $id)
    {
        $identity = UserIdentity::findOne($id);

        if ($identity === null) {
            throw new NotFoundHttpException('User not found.');
        }

        Yii::$app->user->login($identity);

        return $this->asJson([
            'cid' => Coroutine::getCid(),
            'userId' => Yii::$app->user->getId(),
            'isGuest' => Yii::$app->user->getIsGuest(),
            'identity' => [
                'id' => (int) $identity->getId(),
                'name' => (string) $identity->name,
            ],
        ]);
    }

    /**
     * Fetch info of me, for verify login purpose.
     */
    public function actionMe()
    {
        $user = Yii::$app->user;
        $identity = $user->getIdentity();

        return $this->asJson([
            'cid' => Coroutine::getCid(),
            'isGuest' => $user->getIsGuest(),
            'identity' => $identity === null ? null : [
                'id' => (int) $identity->getId(),
                'name' => (string) $identity->name,
            ],
        ]);
    }
}
