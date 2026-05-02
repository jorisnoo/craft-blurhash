<?php

namespace Noo\CraftBlurhash\console\controllers;

use craft\console\Controller;
use craft\elements\Asset;
use Noo\CraftBlurhash\Plugin;
use yii\console\ExitCode;

class GenerateController extends Controller
{
    public bool $force = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'index') {
            $options[] = 'force';
        }

        return $options;
    }

    public function actionIndex(?int $assetId = null): int
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->blurhash;

        $query = Asset::find()->kind(['image', 'video']);

        if ($assetId) {
            $query->id($assetId);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->stdout("No assets found.\n");

            return ExitCode::OK;
        }

        $this->stdout("Processing {$total} asset(s)...\n");

        $processed = 0;
        $skipped = 0;

        foreach ($query->each() as $asset) {
            /** @var Asset $asset */
            if (! $plugin->isProcessable($asset)) {
                $skipped++;

                continue;
            }

            if (! $this->force && $service->getBlurhash($asset) !== null) {
                $skipped++;

                continue;
            }

            $this->stdout("  [{$asset->id}] {$asset->filename}...");
            $service->computeAndStore($asset, $this->force);
            $this->stdout(" done\n");
            $processed++;
        }

        $this->stdout("\nFinished. Processed: {$processed}, Skipped: {$skipped}\n");

        return ExitCode::OK;
    }
}
