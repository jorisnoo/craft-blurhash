<?php

namespace Noo\CraftBlurhash\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use Noo\CraftBlurhash\models\BlurhashRecord;
use Noo\CraftBlurhash\Plugin;

class ComputeBlurhashBatchJob extends BaseJob
{
    /** @var int[] */
    public array $assetIds = [];

    public function execute($queue): void
    {
        $total = count($this->assetIds);

        foreach ($this->assetIds as $i => $assetId) {
            $this->setProgress($queue, $i / $total);

            $asset = Asset::find()->id($assetId)->one();

            if (! $asset) {
                continue;
            }

            try {
                Plugin::getInstance()->blurhash->computeAndStore($asset);
            } catch (\Throwable $e) {
                Craft::error("Failed to compute blurhash for asset #{$assetId}: {$e->getMessage()}", __METHOD__);

                $record = BlurhashRecord::findOne(['assetId' => $assetId]) ?? new BlurhashRecord();
                $record->assetId = $assetId;
                $record->blurhash = null;
                $record->hasTransparency = false;
                $record->save();
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->assetIds);

        return "Computing blurhash for {$count} assets";
    }
}
