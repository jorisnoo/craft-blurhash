<?php

namespace Noo\CraftBlurhash\jobs;

use craft\elements\Asset;
use craft\queue\BaseJob;
use Noo\CraftBlurhash\Plugin;

class GenerateBlurhashesJob extends BaseJob
{
    public int $siteId;
    public bool $force = false;
    public int $afterId = 0;

    public function execute($queue): void
    {
        $query = Asset::find()->siteId($this->siteId)->kind(['image', 'video'])
            ->andWhere(['>', 'elements.id', $this->afterId])->orderBy(['elements.id' => SORT_ASC]);
        if (!$this->force) {
            Plugin::getInstance()->blurhash->withoutGeneratedHash($query);
        }

        // Keyset pages stay stable as earlier jobs fill in missing rows.
        $ids = $query->limit(500)->ids();
        foreach (array_chunk($ids, 50) as $batch) {
            if (!$queue->push(new ComputeBlurhashBatchJob([
                'assetIds' => $batch,
                'siteId' => $this->siteId,
                'force' => $this->force,
            ]))) {
                throw new \RuntimeException('Queue refused the blurhash batch.');
            }
        }
        if (count($ids) === 500 && !$queue->push(new self([
            'siteId' => $this->siteId,
            'force' => $this->force,
            'afterId' => (int)end($ids),
        ]))) {
            throw new \RuntimeException('Queue refused the next blurhash page.');
        }
    }

    protected function defaultDescription(): string
    {
        return $this->force ? 'Scheduling blurhash regeneration' : 'Scheduling missing blurhash generation';
    }
}
