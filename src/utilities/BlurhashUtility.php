<?php

namespace Noo\CraftBlurhash\utilities;

use craft\base\Utility;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
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
        $eligible = (new Query())
            ->from(Table::ASSETS)
            ->innerJoin(Table::ELEMENTS, '[[elements.id]] = [[assets.id]]')
            ->where(['elements.dateDeleted' => null])
            ->andWhere(['assets.kind' => 'image'])
            ->andWhere(['in', 'assets.mimeType', Plugin::ALLOWED_MIME_TYPES])
            ->count();

        $generated = (new Query())
            ->from('{{%blurhash}}')
            ->innerJoin(Table::ELEMENTS, '[[elements.id]] = [[blurhash.assetId]]')
            ->where(['elements.dateDeleted' => null])
            ->count();

        $existingIds = (new Query())
            ->select('assetId')
            ->from('{{%blurhash}}')
            ->column();

        $missingQuery = Asset::find()
            ->kind('image')
            ->limit(100);

        if ($existingIds) {
            $missingQuery->id(['not', ...$existingIds]);
        }

        $missingAssets = array_values(array_filter($missingQuery->all(), function (Asset $asset) {
            return Plugin::getInstance()->isProcessableImage($asset);
        }));

        return \Craft::$app->getView()->renderTemplate('blurhash/_utilities/blurhash', [
            'eligible' => $eligible,
            'generated' => $generated,
            'missingAssets' => $missingAssets,
        ]);
    }
}
