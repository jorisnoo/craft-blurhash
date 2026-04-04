<?php

namespace Noo\CraftBlurhash\jobs;

use craft\elements\Asset;
use craft\queue\BaseJob;
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

            Plugin::getInstance()->blurhash->computeAndStore($asset);
        }
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->assetIds);

        return "Computing blurhash for {$count} assets";
    }
}
