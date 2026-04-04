<?php

namespace Noo\CraftBlurhash\utilities;

use craft\base\Utility;
use craft\db\Query;
use craft\db\Table;
use Noo\CraftBlurhash\Plugin;

class BlurhashUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Blurhash';
    }

    public static function id(): string
    {
        return 'blurhash';
    }

    public static function icon(): ?string
    {
        return 'image';
    }

    public static function contentHtml(): string
    {
        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/avif',
            'image/bmp',
            'image/tiff',
        ];

        $eligible = (new Query())
            ->from(Table::ASSETS)
            ->innerJoin(Table::ELEMENTS, '[[elements.id]] = [[assets.id]]')
            ->where(['elements.dateDeleted' => null])
            ->andWhere(['in', 'assets.kind', ['image']])
            ->count();

        $generated = (new Query())
            ->from('{{%blurhash}}')
            ->innerJoin(Table::ELEMENTS, '[[elements.id]] = [[blurhash.assetId]]')
            ->where(['elements.dateDeleted' => null])
            ->count();

        return \Craft::$app->getView()->renderTemplate('craft-blurhash/_utilities/blurhash', [
            'eligible' => $eligible,
            'generated' => $generated,
        ]);
    }
}
