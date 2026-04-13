<?php

namespace Noo\CraftBlurhash\utilities;

use craft\base\Utility;
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

    public static function badgeCount(): int
    {
        return Plugin::getInstance()->blurhash->getStats()['missing'];
    }

    public static function contentHtml(): string
    {
        $stats = Plugin::getInstance()->blurhash->getStats();

        return \Craft::$app->getView()->renderTemplate('blurhash/_utilities/blurhash', [
            'eligible' => $stats['eligible'],
            'generated' => $stats['generated'],
            'missingAssets' => $stats['missingAssets'],
        ]);
    }
}
