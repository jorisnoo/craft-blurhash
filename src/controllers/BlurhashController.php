<?php

namespace Noo\CraftBlurhash\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use Noo\CraftBlurhash\jobs\ComputeBlurhashBatchJob;
use Noo\CraftBlurhash\Plugin;
use yii\web\Response;

class BlurhashController extends Controller
{
    private const BATCH_SIZE = 50;

    public function actionGenerateMissing(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:blurhash');

        $query = Plugin::getInstance()->blurhash->findAssetsWithoutBlurhash();

        $count = $this->pushBatchJobs($query);

        Craft::$app->getSession()->setNotice("Queued $count assets for blurhash generation.");

        return $this->redirectToPostedUrl();
    }

    public function actionRegenerateAll(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:blurhash');

        // Existing blurhashes stay in place and are overwritten as the jobs
        // run, so templates keep their placeholders in the meantime.
        $query = Asset::find()->kind(['image', 'video']);

        $count = $this->pushBatchJobs($query, force: true);

        Craft::$app->getSession()->setNotice("Queued $count assets for blurhash regeneration.");

        return $this->redirectToPostedUrl();
    }

    private function pushBatchJobs(\craft\elements\db\AssetQuery $query, bool $force = false): int
    {
        $batch = [];
        $count = 0;

        foreach ($query->each() as $asset) {
            if (! Plugin::getInstance()->isProcessable($asset)) {
                continue;
            }

            $batch[] = $asset->id;
            $count++;

            if (count($batch) >= self::BATCH_SIZE) {
                Craft::$app->getQueue()->push(new ComputeBlurhashBatchJob([
                    'assetIds' => $batch,
                    'force' => $force,
                ]));
                $batch = [];
            }
        }

        if (! empty($batch)) {
            Craft::$app->getQueue()->push(new ComputeBlurhashBatchJob([
                'assetIds' => $batch,
                'force' => $force,
            ]));
        }

        return $count;
    }
}
