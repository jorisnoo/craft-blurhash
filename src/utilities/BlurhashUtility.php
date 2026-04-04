<?php

namespace Noo\CraftBlurhash\utilities;

use craft\base\Utility;
use craft\db\Query;
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
        $plugin = Plugin::getInstance();

        $existingIds = (new Query())
            ->select('assetId')
            ->from('{{%blurhash}}')
            ->column();

        $allImages = Asset::find()
            ->kind('image')
            ->all();

        $eligibleAssets = array_filter($allImages, function (Asset $asset) use ($plugin) {
            return $plugin->isProcessableImage($asset);
        });

        $eligible = count($eligibleAssets);
        $generated = 0;
        $missingAssets = [];

        foreach ($eligibleAssets as $asset) {
            if (in_array($asset->id, $existingIds)) {
                $generated++;
            } else {
                $missingAssets[] = $asset;
            }
        }

        return \Craft::$app->getView()->renderTemplate('blurhash/_utilities/blurhash', [
            'eligible' => $eligible,
            'generated' => $generated,
            'missingAssets' => $missingAssets,
        ]);
    }
}
