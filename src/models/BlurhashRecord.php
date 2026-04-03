<?php

namespace Noo\CraftBlurhash\models;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $assetId
 * @property ?string $blurhash
 * @property bool $hasTransparency
 */
class BlurhashRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%blurhash}}';
    }
}
