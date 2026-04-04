<?php

namespace Noo\CraftBlurhash;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Utilities;
use Noo\CraftBlurhash\jobs\ComputeBlurhashJob;
use Noo\CraftBlurhash\services\BlurhashService;
use Noo\CraftBlurhash\twig\BlurhashExtension;
use Noo\CraftBlurhash\utilities\BlurhashUtility;
use yii\base\Event;

/**
 * @property-read BlurhashService $blurhash
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
        'image/bmp',
        'image/tiff',
    ];

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'blurhash' => BlurhashService::class,
        ]);

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'Noo\\CraftBlurhash\\console\\controllers';
        }

        Craft::$app->view->registerTwigExtension(new BlurhashExtension());

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = BlurhashUtility::class;
            }
        );

        Event::on(
            Asset::class,
            Asset::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (! $this->isProcessableImage($asset)) {
                    return;
                }

                Craft::$app->getQueue()->push(new ComputeBlurhashJob([
                    'assetId' => $asset->id,
                ]));
            }
        );
    }

    public function isProcessableImage(Asset $asset): bool
    {
        return in_array($asset->mimeType, self::ALLOWED_MIME_TYPES, true);
    }
}
