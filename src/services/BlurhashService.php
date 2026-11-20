<?php

namespace Noo\CraftBlurhash\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\helpers\Assets;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\ImageTransforms;
use craft\validators\ColorValidator;
use kornrunner\Blurhash\Base83;
use kornrunner\Blurhash\Blurhash;
use Noo\CraftBlurhash\jobs\ComputeBlurhashJob;
use Noo\CraftBlurhash\models\BlurhashRecord;
use Noo\CraftBlurhash\Plugin;
use yii\base\Component;

class BlurhashService extends Component
{
    private const SAMPLE_SIZE = 64;

    private const DECODE_SIZE = 64;

    // Template filters usually read the same asset consecutively. Retain just
    // that lookup, not a record for every asset a console worker has visited.
    private ?int $lastAssetId = null;
    private ?BlurhashRecord $lastRecord = null;

    /** @var array<string, string> */
    private array $uriCache = [];

    public function computeAndStore(Asset $asset, bool $force = false): BlurhashRecord
    {
        $existing = BlurhashRecord::findOne(['assetId' => $asset->id]);

        if (!$force && $existing !== null && $this->isCurrent($existing, $asset)) {
            return $existing;
        }

        $record = $existing ?? new BlurhashRecord();
        $record->assetId = $asset->id;
        $record->sourceDateModified = Db::prepareDateForDb($asset->dateModified);

        if ($prebuilt = $this->fetchBunnyStreamBlurhash($asset)) {
            $record->blurhash = $prebuilt;
            $record->hasTransparency = false;

            return $this->store($record);
        }

        $tempPath = null;
        try {
            $tempPath = $this->fetchCloudinaryThumbnail($asset)
                ?? $this->fetchBunnyStreamThumbnail($asset);

            if ($tempPath === null && $asset->kind === Asset::KIND_VIDEO) {
                Craft::warning("No thumbnail available for video asset {$asset->id}; storing an empty blurhash", __METHOD__);
                $record->blurhash = null;
                $record->hasTransparency = false;
            } else {
                $localPath = $tempPath ?? ImageTransforms::getLocalImageSource($asset);
                $record->blurhash = $this->encode($asset, $localPath);
                $record->hasTransparency = $this->detectTransparency($asset, $localPath);
            }
        } catch (\Throwable $e) {
            Craft::error("Failed to get local image for asset {$asset->id}: {$e->getMessage()}", __METHOD__);
            $record->blurhash = null;
            $record->hasTransparency = false;
        } finally {
            if ($tempPath !== null && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        if ($record->blurhash === null && $existing !== null && $this->validHash($existing->getOldAttribute('blurhash'))) {
            // A failed refresh must not evict a usable placeholder.
            $existing->setAttributes($existing->getOldAttributes(), false);
            return $existing;
        }

        return $this->store($record);
    }

    private function store(BlurhashRecord $record): BlurhashRecord
    {
        $values = $record->getAttributes(['blurhash', 'hasTransparency', 'sourceDateModified']);
        Db::upsert(BlurhashRecord::tableName(), ['assetId' => $record->assetId] + $values, $values);
        $this->lastAssetId = null;

        return $this->getRecordById((int)$record->assetId);
    }

    private function fetchBunnyStreamBlurhash(Asset $asset): ?string
    {
        if (! $asset->hasMethod('getBunnyStreamThumbnailBlurhash')) {
            return null;
        }

        try {
            $hash = $asset->getBunnyStreamThumbnailBlurhash();
            return is_string($hash) && $this->validHash($hash) ? $hash : null;
        } catch (\Throwable $e) {
            Craft::error("Failed to read Bunny Stream blurhash for asset {$asset->id}: {$e->getMessage()}", __METHOD__);
            return null;
        }
    }

    private function fetchCloudinaryThumbnail(Asset $asset): ?string
    {
        if (! $asset->hasMethod('getCloudinaryUrl')) {
            return null;
        }

        $wantsAlpha = in_array($asset->mimeType, ['image/png', 'image/webp', 'image/gif'], true);

        try {
            $url = $asset->getCloudinaryUrl([
                'width' => 128,
                'crop' => 'limit',
                'quality' => 'auto:low',
                'format' => $wantsAlpha ? 'png' : 'jpg',
            ]);
        } catch (\Throwable $e) {
            Craft::error("Failed to build Cloudinary thumbnail URL for asset {$asset->id}: {$e->getMessage()}", __METHOD__);
            return null;
        }

        return $this->downloadToTempFile($url);
    }

    private function fetchBunnyStreamThumbnail(Asset $asset): ?string
    {
        if (! $asset->hasMethod('getBunnyStreamThumbnailUrl')) {
            return null;
        }

        try {
            $url = $asset->getBunnyStreamThumbnailUrl();
        } catch (\Throwable $e) {
            Craft::error("Failed to build Bunny Stream thumbnail URL for asset {$asset->id}: {$e->getMessage()}", __METHOD__);
            return null;
        }

        if (! $url) {
            return null;
        }

        return $this->downloadToTempFile($url);
    }

    private function downloadToTempFile(string $url): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'blurhash_');
        if ($path === false) {
            return null;
        }

        try {
            // Streamed to disk with a generous timeout: the URL can point at a
            // full-size original that the CDN can't resize, such as a file
            // stored in Cloudinary as raw.
            Craft::createGuzzleClient()->get($url, [
                'headers' => $this->thumbnailRequestHeaders(),
                'sink' => $path,
                'connect_timeout' => 10,
                'timeout' => 60,
            ]);
        } catch (\Throwable $e) {
            @unlink($path);
            Craft::warning("Failed to download thumbnail {$url}: {$e->getMessage()}", __METHOD__);
            return null;
        }

        clearstatcache(true, $path);
        if (filesize($path) === 0) {
            @unlink($path);
            Craft::warning("Thumbnail {$url} was empty", __METHOD__);
            return null;
        }

        return $path;
    }

    /**
     * CDNs that restrict access by referrer reject server-side requests, which
     * arrive without one.
     *
     * @return array<string, string>
     */
    private function thumbnailRequestHeaders(): array
    {
        $referrer = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        return $referrer ? ['Referer' => $referrer] : [];
    }

    public function getBlurhash(Asset $asset): ?string
    {
        $hash = $this->resolve($asset)?->blurhash;
        return $this->validHash($hash) ? $hash : null;
    }

    public function needsCompute(Asset $asset): bool
    {
        $record = BlurhashRecord::findOne(['assetId' => $asset->id]);
        return $record === null || !$this->isCurrent($record, $asset);
    }

    private function isCurrent(BlurhashRecord $record, Asset $asset): bool
    {
        return $this->validHash($record->blurhash)
            && ($asset->dateModified === null || $record->sourceDateModified === Db::prepareDateForDb($asset->dateModified));
    }

    public function getHasTransparency(Asset $asset): bool
    {
        return (bool) ($this->resolve($asset)?->hasTransparency ?? false);
    }

    public function blurhashToUri(?string $blurhash): ?string
    {
        if (!$this->validHash($blurhash)) {
            return null;
        }

        return $this->uriCache[$blurhash] ??= $this->decodeToPngDataUri($blurhash);
    }

    /**
     * @return array{eligible: int, generated: int, missing: int, missingAssets: Asset[]}
     */
    public function getStats(?int $ignoreCreatedWithinSeconds = null, int $missingLimit = 50): array
    {
        $base = Asset::find()->andWhere(['not', ['assets.volumeId' => null]]);
        if ($ignoreCreatedWithinSeconds !== null) {
            $base->dateCreated('<= '.(new \DateTimeImmutable("-$ignoreCreatedWithinSeconds seconds"))->format(DATE_ATOM));
        }

        $extensions = [];
        foreach (Assets::getFileKinds()[Asset::KIND_IMAGE]['extensions'] as $extension) {
            if (in_array(FileHelper::getMimeTypeByExtension("file.$extension"), Plugin::ALLOWED_MIME_TYPES, true)) {
                $extensions[] = "*.$extension";
            }
        }
        $byExtension = Db::parseParam('assets.filename', $extensions, caseInsensitive: true) ?? '0=1';
        $mime = Craft::$app->getDb()->columnExists(Table::ASSETS, 'mimeType')
            ? ['or', ['assets.mimeType' => Plugin::ALLOWED_MIME_TYPES], ['and', ['assets.mimeType' => null], $byExtension]]
            : $byExtension;
        $images = (clone $base)->kind(Asset::KIND_IMAGE)->andWhere($mime);
        $eligible = (int)(clone $images)->count();
        $missingImages = $this->withoutGeneratedHash($images);
        $missing = (int)(clone $missingImages)->count();
        $generated = $eligible - $missing;
        $missingAssets = $missingLimit > 0 ? $missingImages->limit($missingLimit)->all() : [];

        // Video readiness is provided by an optional asset behavior, not a
        // Craft column. Partition in SQL rather than loading all generated IDs.
        foreach ([true, false] as $hasHash) {
            $videos = (clone $base)->kind(Asset::KIND_VIDEO)
                ->andWhere([$hasHash ? 'in' : 'not in', 'elements.id', $this->generatedIds()]);
            foreach ($videos->each() as $video) {
                if (!Plugin::getInstance()->isProcessableBunnyStreamVideo($video)) {
                    continue;
                }
                ++$eligible;
                if ($hasHash) {
                    ++$generated;
                } elseif (count($missingAssets) < $missingLimit) {
                    $missingAssets[] = $video;
                }
            }
        }

        return [
            'eligible' => $eligible,
            'generated' => $generated,
            'missing' => $eligible - $generated,
            'missingAssets' => $missingAssets,
        ];
    }

    public function withoutGeneratedHash(AssetQuery $query): AssetQuery
    {
        return $query->andWhere(['not in', 'elements.id', $this->generatedIds()]);
    }

    private function generatedIds(): Query
    {
        return (new Query())->select('assetId')->from(BlurhashRecord::tableName())
            ->where(['not', ['blurhash' => null]]);
    }

    private function validHash(?string $hash): bool
    {
        if ($hash === null || strlen($hash) < 6 || strlen($hash) > 166
            || strspn($hash, '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~') !== strlen($hash)) {
            return false;
        }
        $size = Base83::decode($hash[0]);
        return $size <= 80 && strlen($hash) === 4 + 2 * (1 + $size % 9) * (1 + intdiv($size, 9))
            && Base83::decode(substr($hash, 2, 4)) <= 0xffffff;
    }

    public function averageColor(?string $blurhash): ?string
    {
        if (!$this->validHash($blurhash)) {
            return null;
        }

        $value = Base83::decode(substr($blurhash, 2, 4));

        return ColorValidator::normalizeColor('#'.sprintf('%06x', $value));
    }

    private function resolve(Asset $asset): ?BlurhashRecord
    {
        if (!$asset->id) {
            return null;
        }
        $record = $this->getRecordById($asset->id);

        if (($record === null || ($record->blurhash !== null && !$this->isCurrent($record, $asset)))
            && $this->shouldComputeOnDemand($asset)) {
            try {
                $this->queueCompute($asset);
            } catch (\Throwable $e) {
                Craft::error("Could not queue blurhash for asset {$asset->id}: {$e->getMessage()}", __METHOD__);
            }
        }

        return $record;
    }

    private function shouldComputeOnDemand(Asset $asset): bool
    {
        return Plugin::getInstance()->getSettings()->computeOnDemand
            && Plugin::getInstance()->isProcessable($asset);
    }

    public function queueCompute(Asset $asset, bool $force = false): void
    {
        // A replacement must not be lost behind an older in-flight job, even
        // when both files have the same modification time to the second.
        $cache = Craft::$app->getCache();
        $key = ['blurhash:queued', $asset->id, $asset->dateModified?->getTimestamp()];
        if (!$force && !$cache->add($key, true, 300)) {
            return;
        }
        try {
            if (!Craft::$app->getQueue()->push(new ComputeBlurhashJob(['assetId' => $asset->id, 'force' => $force]))) {
                throw new \RuntimeException('Queue refused the blurhash job.');
            }
        } catch (\Throwable $e) {
            if (!$force) {
                $cache->delete($key);
            }
            throw $e;
        }
    }

    private function getRecordById(int $assetId): ?BlurhashRecord
    {
        if ($this->lastAssetId !== $assetId) {
            $this->lastRecord = BlurhashRecord::findOne(['assetId' => $assetId]);
            $this->lastAssetId = $assetId;
        }
        return $this->lastRecord;
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

            [$sourceWidth, $sourceHeight] = $this->resolveSourceDimensions($asset, $localPath);

            $componentsX = $sourceWidth > $sourceHeight ? 6 : (int) ceil(6 * ($sourceWidth / $sourceHeight));
            $componentsY = $sourceHeight > $sourceWidth ? 6 : (int) ceil(6 * ($sourceHeight / $sourceWidth));

            return Blurhash::encode($pixels, $componentsX, $componentsY);
        } catch (\Throwable $e) {
            Craft::error("Failed to encode blurhash for asset {$asset->id}: {$e->getMessage()}", __METHOD__);

            return null;
        }
    }

    /**
     * @return array{int, int}
     */
    private function resolveSourceDimensions(Asset $asset, string $localPath): array
    {
        if ($asset->width > 0 && $asset->height > 0) {
            return [$asset->width, $asset->height];
        }

        $size = @getimagesize($localPath);
        if ($size !== false && $size[0] > 0 && $size[1] > 0) {
            return [$size[0], $size[1]];
        }

        return [1, 1];
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
