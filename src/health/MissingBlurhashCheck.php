<?php

namespace Noo\CraftBlurhash\health;

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
        $stats = Plugin::getInstance()->blurhash->getStats();
        $eligibleCount = $stats['eligible'];
        $missingCount = $stats['missing'];

        $result = new CheckResult(
            name: 'MissingBlurhash',
            label: 'Missing Blurhashes',
            shortSummary: "$missingCount/$eligibleCount missing",
            meta: [
                'eligible' => $eligibleCount,
                'generated' => $stats['generated'],
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
