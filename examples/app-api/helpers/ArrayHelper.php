<?php

declare(strict_types=1);

namespace app\helpers;

use yii\helpers\BaseArrayHelper;

class ArrayHelper extends BaseArrayHelper
{
    public static function test($data)
    {
        return $data;
    }
}

if (!class_exists('yii\\helpers\\ArrayHelper', false)) {
    class_alias(ArrayHelper::class, 'yii\\helpers\\ArrayHelper');
}
