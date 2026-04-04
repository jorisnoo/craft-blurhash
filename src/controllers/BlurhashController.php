<?php

namespace Noo\CraftBlurhash\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\web\Controller;
use Noo\CraftBlurhash\jobs\ComputeBlurhashJob;
use Noo\CraftBlurhash\models\BlurhashRecord;
use Noo\CraftBlurhash\Plugin;
use yii\web\Response;

class BlurhashController extends Controller
{
    public function actionGenerateMissing(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:blurhash');

        $existingIds = (new Query())
            ->select('assetId')
            ->from('{{%blurhash}}')
            ->column();

        $assets = Asset::find()
            ->kind('image')
            ->id($existingIds ? ['not', ...$existingIds] : null)
            ->all();

        $count = 0;
        foreach ($assets as $asset) {
            if (Plugin::getInstance()->isProcessableImage($asset)) {
                Craft::$app->getQueue()->push(new ComputeBlurhashJob([
                    'assetId' => $asset->id,
                ]));
                $count++;
            }
        }

        Craft::$app->getSession()->setNotice("Queued $count assets for blurhash generation.");

        return $this->redirectToPostedUrl();
    }

    public function actionRegenerateAll(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:blurhash');

        BlurhashRecord::deleteAll();

        $assets = Asset::find()->kind('image')->all();

        $count = 0;
        foreach ($assets as $asset) {
            if (Plugin::getInstance()->isProcessableImage($asset)) {
                Craft::$app->getQueue()->push(new ComputeBlurhashJob([
                    'assetId' => $asset->id,
                ]));
                $count++;
            }
        }

        Craft::$app->getSession()->setNotice("Queued $count assets for blurhash regeneration.");

        return $this->redirectToPostedUrl();
    }
}
