<?php

declare(strict_types=1);

namespace dacheng\app\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 */
class User extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%user}}';
    }

    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 255],
        ];
    }
}
