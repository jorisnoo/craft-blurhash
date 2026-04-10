<?php

namespace Noo\CraftBlurhash\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\ImageTransforms;
use craft\validators\ColorValidator;
use kornrunner\Blurhash\Base83;
use kornrunner\Blurhash\Blurhash;
use Noo\CraftBlurhash\models\BlurhashRecord;
use Noo\CraftBlurhash\Plugin;
use yii\base\Component;

class BlurhashService extends Component
{
    private const SAMPLE_SIZE = 64;

    private const DECODE_SIZE = 64;

    /** @var array<int, BlurhashRecord|false> */
    private array $recordCache = [];

    /** @var array<string, string> */
    private array $uriCache = [];

    public function computeAndStore(Asset $asset): BlurhashRecord
    {
        $record = $this->getRecord($asset) ?? new BlurhashRecord();
        $record->assetId = $asset->id;

        try {
            $localPath = ImageTransforms::getLocalImageSource($asset);
            $record->blurhash = $this->encode($asset, $localPath);
            $record->hasTransparency = $this->detectTransparency($localPath);
        } catch (\Throwable $e) {
            Craft::error("Failed to get local image for asset {$asset->id}: {$e->getMessage()}", __METHOD__);
            $record->blurhash = null;
            $record->hasTransparency = false;
        }

        $record->save();

        return $this->recordCache[$asset->id] = $record;
    }

    public function getBlurhash(Asset $asset): ?string
    {
        return $this->resolve($asset)?->blurhash;
    }

    public function getHasTransparency(Asset $asset): bool
    {
        return (bool) ($this->resolve($asset)?->hasTransparency ?? false);
    }

    public function blurhashToUri(?string $blurhash): ?string
    {
        if ($blurhash === null) {
            return null;
        }

        return $this->uriCache[$blurhash] ??= $this->decodeToPngDataUri($blurhash);
    }

    public function averageColor(?string $blurhash): ?string
    {
        if ($blurhash === null) {
            return null;
        }

        $value = Base83::decode(substr($blurhash, 2, 4));

        return ColorValidator::normalizeColor('#'.dechex($value));
    }

    private function resolve(Asset $asset): ?BlurhashRecord
    {
        $record = $this->getRecord($asset);

        if (! $record && $this->shouldComputeOnDemand($asset)) {
            $record = $this->computeAndStore($asset);
        }

        return $record;
    }

    private function shouldComputeOnDemand(Asset $asset): bool
    {
        return Plugin::getInstance()->getSettings()->computeOnDemand
            && Plugin::getInstance()->isProcessableImage($asset);
    }

    private function getRecord(Asset $asset): ?BlurhashRecord
    {
        if (! array_key_exists($asset->id, $this->recordCache)) {
            $this->recordCache[$asset->id] = BlurhashRecord::findOne(['assetId' => $asset->id]) ?: false;
        }

        return $this->recordCache[$asset->id] ?: null;
    }

    private function decodeToPngDataUri(string $blurhash): string
    {
        $pixels = Blurhash::decode($blurhash, self::DECODE_SIZE, self::DECODE_SIZE);

        $image = imagecreatetruecolor(self::DECODE_SIZE, self::DECODE_SIZE);
        for ($y = 0; $y < self::DECODE_SIZE; ++$y) {
            for ($x = 0; $x < self::DECODE_SIZE; ++$x) {
                [$r, $g, $b] = $pixels[$y][$x];
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $r, $g, $b));
            }
        }

        ob_start();
        imagepng($image);
        $data = ob_get_clean();
        imagedestroy($image);

        return sprintf('data:image/png;base64,%s', base64_encode($data));
    }

    private function encode(Asset $asset, string $localPath): ?string
    {
        try {
            $image = Craft::$app->getImages()->loadImage($localPath);
            $image->scaleToFit(self::SAMPLE_SIZE, self::SAMPLE_SIZE);

            $resource = imagecreatefromstring($image->getImagineImage()->get('png'));
            $sampleWidth = imagesx($resource);
            $sampleHeight = imagesy($resource);

            $pixels = [];
            for ($y = 0; $y < $sampleHeight; ++$y) {
                $row = [];
                for ($x = 0; $x < $sampleWidth; ++$x) {
                    $index = imagecolorat($resource, $x, $y);
                    $colors = imagecolorsforindex($resource, $index);
                    $row[] = [$colors['red'], $colors['green'], $colors['blue']];
                }
                $pixels[] = $row;
            }
            imagedestroy($resource);

            $componentsX = $asset->width > $asset->height ? 6 : (int) ceil(6 * ($asset->width / $asset->height));
            $componentsY = $asset->height > $asset->width ? 6 : (int) ceil(6 * ($asset->height / $asset->width));

            return Blurhash::encode($pixels, $componentsX, $componentsY);
        } catch (\Throwable $e) {
            Craft::error("Failed to encode blurhash for asset {$asset->id}: {$e->getMessage()}", __METHOD__);

            return null;
        }
    }

    private function detectTransparency(string $localPath): bool
    {
        try {
            return Craft::$app->getImages()->loadImage($localPath, true)->getIsTransparent() ?? false;
        } catch (\Throwable $e) {
            Craft::error("Failed to detect transparency: {$e->getMessage()}", __METHOD__);

            return false;
        }
    }
}
