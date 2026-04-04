<?php

namespace Noo\CraftBlurhash\jobs;

use craft\elements\Asset;
use craft\queue\BaseJob;
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

        Plugin::getInstance()->blurhash->computeAndStore($asset);
    }

    protected function defaultDescription(): ?string
    {
        return "Computing blurhash for asset #{$this->assetId}";
    }
}
