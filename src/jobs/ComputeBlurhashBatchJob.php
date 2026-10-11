<?php

namespace Noo\CraftBlurhash\jobs;

use craft\elements\Asset;
use craft\queue\BaseJob;
use Noo\CraftBlurhash\Plugin;

class ComputeBlurhashBatchJob extends BaseJob
{
    /** @var int[] */
    public array $assetIds = [];

    public bool $force = false;

    public function execute($queue): void
    {
        $total = count($this->assetIds);
        $blurhash = Plugin::getInstance()->blurhash;
        $blurhash->clearCache();

        foreach ($this->assetIds as $i => $assetId) {
            $this->setProgress($queue, $i / $total);

            $asset = Asset::find()->id($assetId)->one();

            if (! $asset) {
                continue;
            }

            $blurhash->computeAndStore($asset, $this->force);
        }
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->assetIds);

        return "Computing blurhash for {$count} assets";
    }
}
