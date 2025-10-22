<?php

declare(strict_types=1);

namespace app\models;

use yii\web\IdentityInterface;

class UserIdentity extends User implements IdentityInterface
{
    public static function findIdentity($id)
    {
        return static::findOne($id);
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        return (string) $this->id;
    }

    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === (string) $authKey;
    }
}
