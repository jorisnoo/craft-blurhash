<?php

namespace Noo\CraftBlurhash\services;

use Craft;
use craft\db\Query;
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
            $record->hasTransparency = $this->detectTransparency($asset, $localPath);
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

    /**
     * @return array{eligible: int, generated: int, missing: int, missingAssets: Asset[]}
     */
    public function getStats(): array
    {
        $plugin = Plugin::getInstance();

        $generatedIds = (new Query())
            ->select('assetId')
            ->from('{{%blurhash}}')
            ->where(['not', ['blurhash' => null]])
            ->column();

        $eligible = 0;
        $generated = 0;
        $missingAssets = [];

        foreach (Asset::find()->kind('image')->each() as $asset) {
            if (! $plugin->isProcessableImage($asset)) {
                continue;
            }

            $eligible++;

            if (in_array($asset->id, $generatedIds)) {
                $generated++;
            } else {
                $missingAssets[] = $asset;
            }
        }

        return [
            'eligible' => $eligible,
            'generated' => $generated,
            'missing' => $eligible - $generated,
            'missingAssets' => $missingAssets,
        ];
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
            $sample = $this->createThumbnail($localPath);
            $sampleWidth = imagesx($sample);
            $sampleHeight = imagesy($sample);

            $pixels = [];
            for ($y = 0; $y < $sampleHeight; ++$y) {
                $row = [];
                for ($x = 0; $x < $sampleWidth; ++$x) {
                    $index = imagecolorat($sample, $x, $y);
                    $colors = imagecolorsforindex($sample, $index);
                    $row[] = [$colors['red'], $colors['green'], $colors['blue']];
                }
                $pixels[] = $row;
            }
            imagedestroy($sample);

            $componentsX = $asset->width > $asset->height ? 6 : (int) ceil(6 * ($asset->width / $asset->height));
            $componentsY = $asset->height > $asset->width ? 6 : (int) ceil(6 * ($asset->height / $asset->width));

            return Blurhash::encode($pixels, $componentsX, $componentsY);
        } catch (\Throwable $e) {
            Craft::error("Failed to encode blurhash for asset {$asset->id}: {$e->getMessage()}", __METHOD__);

            return null;
        }
    }

    /**
     * Creates a small GD thumbnail for pixel extraction.
     *
     * Uses Imagick with a JPEG size hint when available to avoid
     * decompressing the full image into memory.
     *
     * @return \GdImage
     */
    private function createThumbnail(string $localPath): \GdImage
    {
        if (extension_loaded('imagick')) {
            $imagick = new \Imagick();
            $imagick->setOption('jpeg:size', (self::SAMPLE_SIZE * 2).'x'.(self::SAMPLE_SIZE * 2));
            $imagick->readImage($localPath);
            $imagick->thumbnailImage(self::SAMPLE_SIZE, self::SAMPLE_SIZE, true);
            $imagick->setImageFormat('png');
            $blob = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();

            return imagecreatefromstring($blob);
        }

        $source = imagecreatefromstring(file_get_contents($localPath));
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        $sampleWidth = (int) round(self::SAMPLE_SIZE * min(1, $srcWidth / $srcHeight));
        $sampleHeight = (int) round(self::SAMPLE_SIZE * min(1, $srcHeight / $srcWidth));

        $sample = imagecreatetruecolor($sampleWidth, $sampleHeight);
        imagecopyresized($sample, $source, 0, 0, 0, 0, $sampleWidth, $sampleHeight, $srcWidth, $srcHeight);
        imagedestroy($source);

        return $sample;
    }

    private function detectTransparency(Asset $asset, string $localPath): bool
    {
        if (! in_array($asset->mimeType, ['image/png', 'image/webp', 'image/gif'], true)) {
            return false;
        }

        try {
            return Craft::$app->getImages()->loadImage($localPath, true)->getIsTransparent() ?? false;
        } catch (\Throwable $e) {
            Craft::error("Failed to detect transparency: {$e->getMessage()}", __METHOD__);

            return false;
        }
    }
}
