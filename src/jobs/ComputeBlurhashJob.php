<?php

namespace Noo\CraftBlurhash\jobs;

use Craft;
use craft\queue\BaseJob;
use Noo\CraftBlurhash\Plugin;

class ComputeBlurhashJob extends BaseJob
{
    public int $assetId;
    public bool $force = false;

    public function execute($queue): void
    {
        $asset = Craft::$app->getAssets()->getAssetById($this->assetId);

        if (!$asset || !Plugin::getInstance()->isProcessable($asset)) {
            return;
        }

        Plugin::getInstance()->blurhash->computeAndStore($asset, $this->force);
    }

    protected function defaultDescription(): ?string
    {
        return "Computing blurhash for asset #{$this->assetId}";
    }
}
