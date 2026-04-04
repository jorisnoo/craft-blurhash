<?php

namespace Noo\CraftBlurhash\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use Noo\CraftBlurhash\models\BlurhashRecord;
use Noo\CraftBlurhash\Plugin;

class ComputeBlurhashJob extends BaseJob
{
    public int $assetId;

    public function execute($queue): void
    {
        $asset = Asset::find()->id($this->assetId)->one();

        if (! $asset) {
            return;
        }

        try {
            Plugin::getInstance()->blurhash->computeAndStore($asset);
        } catch (\Throwable $e) {
            Craft::error("Failed to compute blurhash for asset #{$this->assetId}: {$e->getMessage()}", __METHOD__);

            $record = BlurhashRecord::findOne(['assetId' => $this->assetId]) ?? new BlurhashRecord();
            $record->assetId = $this->assetId;
            $record->blurhash = null;
            $record->hasTransparency = false;
            $record->save();
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Computing blurhash for asset #{$this->assetId}";
    }
}
