<?php

namespace Noo\CraftBlurhash\health;

use craft\db\Query;
use craft\elements\Asset;
use Noo\CraftBlurhash\Plugin;
use OhDear\HealthCheckResults\CheckResult;
use webhubworks\ohdear\health\checks\Check;

class MissingBlurhashCheck extends Check
{
    private int $warningThreshold = 0;

    private int $failThreshold = 50;

    public function warnWhenMissingCountIsAbove(int $count): self
    {
        $this->warningThreshold = $count;

        return $this;
    }

    public function failWhenMissingCountIsAbove(int $count): self
    {
        $this->failThreshold = $count;

        return $this;
    }

    public function run(): CheckResult
    {
        $plugin = Plugin::getInstance();

        $generatedIds = (new Query())
            ->select('assetId')
            ->from('{{%blurhash}}')
            ->where(['not', ['blurhash' => null]])
            ->column();

        $eligibleCount = 0;
        $missingCount = 0;

        foreach (Asset::find()->kind('image')->each() as $asset) {
            if (! $plugin->isProcessableImage($asset)) {
                continue;
            }

            $eligibleCount++;

            if (! in_array($asset->id, $generatedIds)) {
                $missingCount++;
            }
        }

        $result = new CheckResult(
            name: 'MissingBlurhash',
            label: 'Missing Blurhashes',
            shortSummary: "$missingCount/$eligibleCount missing",
            meta: [
                'eligible' => $eligibleCount,
                'generated' => $eligibleCount - $missingCount,
                'missing' => $missingCount,
            ],
        );

        if ($missingCount > $this->failThreshold) {
            return $result->status(CheckResult::STATUS_FAILED)
                ->notificationMessage("$missingCount assets are missing blurhashes.");
        }

        if ($missingCount > $this->warningThreshold) {
            return $result->status(CheckResult::STATUS_WARNING)
                ->notificationMessage("$missingCount assets are missing blurhashes.");
        }

        return $result->status(CheckResult::STATUS_OK)
            ->notificationMessage('All eligible assets have blurhashes.');
    }
}
