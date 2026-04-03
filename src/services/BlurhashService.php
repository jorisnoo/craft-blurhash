<?php

namespace Noo\CraftBlurhash\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\ImageTransforms;
use craft\validators\ColorValidator;
use kornrunner\Blurhash\Base83;
use kornrunner\Blurhash\Blurhash;
use Noo\CraftBlurhash\models\BlurhashRecord;
use yii\base\Component;

class BlurhashService extends Component
{
    private const SAMPLE_SIZE = 64;

    private const DECODE_SIZE = 64;

    public function computeAndStore(Asset $asset): void
    {
        $blurhash = $this->encode($asset);
        $hasTransparency = $this->detectTransparency($asset);

        $record = BlurhashRecord::findOne(['assetId' => $asset->id]) ?? new BlurhashRecord();
        $record->assetId = $asset->id;
        $record->blurhash = $blurhash;
        $record->hasTransparency = $hasTransparency;
        $record->save();
    }

    public function getBlurhash(Asset $asset): ?string
    {
        return BlurhashRecord::findOne(['assetId' => $asset->id])?->blurhash;
    }

    public function getHasTransparency(Asset $asset): bool
    {
        return (bool) (BlurhashRecord::findOne(['assetId' => $asset->id])?->hasTransparency ?? false);
    }

    public function blurhashToUri(string $blurhash): string
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

    public function averageColor(string $blurhash): string
    {
        $value = Base83::decode(substr($blurhash, 2, 4));

        return ColorValidator::normalizeColor('#' . dechex($value));
    }

    private function encode(Asset $asset): ?string
    {
        try {
            $contents = $asset->getContents();

            if ($asset->getExtension() === 'heic') {
                $imagick = new \Imagick();
                $imagick->readImageBlob($contents);
                $imagick->setImageFormat('png');
                $contents = $imagick->getImageBlob();
            }

            $source = imagecreatefromstring($contents);
            if ($source === false) {
                return null;
            }

            $sampleWidth = (int) round(self::SAMPLE_SIZE * min(1, $asset->width / $asset->height));
            $sampleHeight = (int) round(self::SAMPLE_SIZE * min(1, $asset->height / $asset->width));

            $sample = imagecreatetruecolor($sampleWidth, $sampleHeight);
            imagecopyresized($sample, $source, 0, 0, 0, 0, $sampleWidth, $sampleHeight, $asset->width, $asset->height);
            imagedestroy($source);

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

    private function detectTransparency(Asset $asset): bool
    {
        try {
            $localCopy = ImageTransforms::getLocalImageSource($asset);

            return Craft::$app->getImages()->loadImage($localCopy, true)->getIsTransparent() ?? false;
        } catch (\Throwable $e) {
            Craft::error("Failed to detect transparency for asset {$asset->id}: {$e->getMessage()}", __METHOD__);

            return false;
        }
    }
}
