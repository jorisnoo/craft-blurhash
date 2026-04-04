<?php

namespace Noo\CraftBlurhash\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\web\Controller;
use Noo\CraftBlurhash\jobs\ComputeBlurhashBatchJob;
use Noo\CraftBlurhash\models\BlurhashRecord;
use Noo\CraftBlurhash\Plugin;
use yii\web\Response;

class BlurhashController extends Controller
{
    private const BATCH_SIZE = 50;

    public function actionGenerateMissing(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:blurhash');

        $existingIds = (new Query())
            ->select('assetId')
            ->from('{{%blurhash}}')
            ->column();

        $query = Asset::find()
            ->kind('image')
            ->id($existingIds ? ['not', ...$existingIds] : null);

        $count = $this->pushBatchJobs($query);

        Craft::$app->getSession()->setNotice("Queued $count assets for blurhash generation.");

        return $this->redirectToPostedUrl();
    }

    public function actionRegenerateAll(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:blurhash');

        BlurhashRecord::deleteAll();

        $query = Asset::find()->kind('image');

        $count = $this->pushBatchJobs($query);

        Craft::$app->getSession()->setNotice("Queued $count assets for blurhash regeneration.");

        return $this->redirectToPostedUrl();
    }

    private function pushBatchJobs(\craft\elements\db\AssetQuery $query): int
    {
        $batch = [];
        $count = 0;

        foreach ($query->each() as $asset) {
            if (! Plugin::getInstance()->isProcessableImage($asset)) {
                continue;
            }

            $batch[] = $asset->id;
            $count++;

            if (count($batch) >= self::BATCH_SIZE) {
                Craft::$app->getQueue()->push(new ComputeBlurhashBatchJob([
                    'assetIds' => $batch,
                ]));
                $batch = [];
            }
        }

        if (! empty($batch)) {
            Craft::$app->getQueue()->push(new ComputeBlurhashBatchJob([
                'assetIds' => $batch,
            ]));
        }

        return $count;
    }
}
