<?php

namespace Noo\CraftBlurhash\controllers;

use Craft;
use craft\web\Controller;
use Noo\CraftBlurhash\jobs\GenerateBlurhashesJob;
use yii\web\Response;

class BlurhashController extends Controller
{
    public function actionGenerateMissing(): Response
    {
        return $this->queueGeneration(false);
    }

    public function actionRegenerateAll(): Response
    {
        return $this->queueGeneration(true);
    }

    private function queueGeneration(bool $force): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:blurhash');

        $job = new GenerateBlurhashesJob([
            'force' => $force,
            'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
        ]);
        if (!Craft::$app->getQueue()->push($job)) {
            throw new \RuntimeException('Queue refused the blurhash generation job.');
        }
        Craft::$app->getSession()->setNotice($force ? 'Queued blurhash regeneration.' : 'Queued generation of missing blurhashes.');

        return $this->redirectToPostedUrl();
    }
}
