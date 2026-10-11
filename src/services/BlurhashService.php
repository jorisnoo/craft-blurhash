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
use yii\caching\TagDependency;

class BlurhashService extends Component
{
    private const SAMPLE_SIZE = 64;

    private const DECODE_SIZE = 64;

    private const MAX_THUMBNAIL_BYTES = 10 * 1024 * 1024;

    public const MISSING_ASSETS_LIMIT = 50;

    public const STATS_CACHE_TAG = 'blurhash-stats';

    private const QUEUED_CACHE_TTL = 600;

    /** @var array<int, BlurhashRecord|false> */
    private array $recordCache = [];

    /** @var array<int, true> */
    private array $queuedAssetIds = [];

    /** @var array<string, string> */
    private array $uriCache = [];

    public function computeAndStore(Asset $asset, bool $force = false): BlurhashRecord
    {
        $existing = $this->getRecord($asset);

        if (! $force && $existing !== null && ! $this->needsCompute($asset)) {
            return $existing;
        }

        $record = $existing ?? new BlurhashRecord();
        $record->assetId = $asset->id;
        $record->sourceDateModified = $asset->dateModified?->format('Y-m-d H:i:s');

        if ($prebuilt = $this->fetchBunnyStreamBlurhash($asset)) {
            $record->blurhash = $prebuilt;
            $record->hasTransparency = false;
            $this->saveRecord($record);

            return $this->recordCache[$asset->id] = $record;
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
                [$record->blurhash, $record->hasTransparency] = $this->analyze($asset, $localPath);
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

        $this->saveRecord($record);

        return $this->recordCache[$asset->id] = $record;
    }

    /**
     * Forgets records cached during this process. Long-running processes
     * (queue workers, console commands) should call this between units of work
     * so they never act on records changed by another process.
     */
    public function clearCache(): void
    {
        $this->recordCache = [];
        $this->queuedAssetIds = [];
    }

    private function saveRecord(BlurhashRecord $record): void
    {
        $record->save();
        TagDependency::invalidate(Craft::$app->getCache(), self::STATS_CACHE_TAG);
    }

    private function fetchBunnyStreamBlurhash(Asset $asset): ?string
    {
        if (! $asset->hasMethod('getBunnyStreamThumbnailBlurhash')) {
            return null;
        }

        try {
            return $asset->getBunnyStreamThumbnailBlurhash() ?: null;
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
        $maxBytes = self::MAX_THUMBNAIL_BYTES;

        try {
            $body = Craft::createGuzzleClient()
                ->get($url, [
                    'headers' => $this->thumbnailRequestHeaders(),
                    'timeout' => 10,
                    'stream' => true,
                    'on_headers' => static function ($response) use ($maxBytes) {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (int) $length > $maxBytes) {
                            throw new \RuntimeException("Content-Length {$length} exceeds the {$maxBytes} byte limit");
                        }
                    },
                ])
                ->getBody();

            $data = '';
            while (! $body->eof()) {
                $data .= $body->read(65536);

                if (strlen($data) > $maxBytes) {
                    $body->close();
                    Craft::warning("Thumbnail {$url} exceeds the {$maxBytes} byte limit", __METHOD__);

                    return null;
                }
            }
            $body->close();
        } catch (\Throwable $e) {
            Craft::warning("Failed to download thumbnail {$url}: {$e->getMessage()}", __METHOD__);
            return null;
        }

        if ($data === '') {
            Craft::warning("Thumbnail {$url} was empty", __METHOD__);
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'blurhash_');
        if ($path === false) {
            return null;
        }

        if (file_put_contents($path, $data) === false) {
            @unlink($path);
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
        return $this->resolve($asset)?->blurhash;
    }

    public function needsCompute(Asset $asset): bool
    {
        $record = $this->getRecord($asset);

        if ($record === null || $record->blurhash === null) {
            return true;
        }

        if ($asset->dateModified === null || $record->sourceDateModified === null) {
            return false;
        }

        return $asset->dateModified > new \DateTime($record->sourceDateModified);
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
     * Image and video assets that have no stored blurhash yet. Callers still
     * need to filter the results with Plugin::isProcessable().
     */
    public function findAssetsWithoutBlurhash(): AssetQuery
    {
        return Asset::find()
            ->kind(['image', 'video'])
            ->andWhere(['not', ['elements.id' => $this->generatedAssetIdsQuery()]]);
    }

    /**
     * Counts are computed in SQL for images; videos still need a per-asset
     * readiness check, so only video assets are loaded as elements.
     *
     * @return array{eligible: int, generated: int, missing: int, missingAssets: Asset[]}
     */
    public function getStats(?int $ignoreCreatedWithinSeconds = null, int $missingLimit = self::MISSING_ASSETS_LIMIT): array
    {
        $plugin = Plugin::getInstance();

        $cutoff = $ignoreCreatedWithinSeconds !== null
            ? (new \DateTimeImmutable())->modify("-{$ignoreCreatedWithinSeconds} seconds")
            : null;

        $imageQuery = $this->eligibleImageQuery($cutoff);
        $eligible = (int) (clone $imageQuery)->count();
        $missingImagesQuery = (clone $imageQuery)
            ->andWhere(['not', ['elements.id' => $this->generatedAssetIdsQuery()]]);
        $missing = (int) (clone $missingImagesQuery)->count();
        $missingAssets = $missingLimit > 0
            ? $missingImagesQuery->limit($missingLimit)->all()
            : [];

        $videoQuery = Asset::find()
            ->kind('video')
            ->andWhere(['not', ['assets.volumeId' => null]]);
        if ($cutoff) {
            $videoQuery->dateCreated('<= '.$cutoff->format(\DateTimeInterface::ATOM));
        }

        $generatedVideoIds = array_flip(
            (clone $videoQuery)->andWhere(['elements.id' => $this->generatedAssetIdsQuery()])->ids()
        );

        foreach ($videoQuery->each() as $asset) {
            /** @var Asset $asset */
            if (! $plugin->isProcessableBunnyStreamVideo($asset)) {
                continue;
            }

            $eligible++;

            if (isset($generatedVideoIds[$asset->id])) {
                continue;
            }

            $missing++;

            if (count($missingAssets) < $missingLimit) {
                $missingAssets[] = $asset;
            }
        }

        return [
            'eligible' => $eligible,
            'generated' => $eligible - $missing,
            'missing' => $missing,
            'missingAssets' => $missingAssets,
        ];
    }

    /**
     * The missing count, cached until the next blurhash is stored, so the CP
     * utility badge does not hit the asset tables on every page load.
     */
    public function getMissingCount(): int
    {
        return (int) Craft::$app->getCache()->getOrSet(
            'blurhash:missing-count',
            fn () => $this->getStats(missingLimit: 0)['missing'],
            3600,
            new TagDependency(['tags' => self::STATS_CACHE_TAG]),
        );
    }

    private function generatedAssetIdsQuery(): Query
    {
        return (new Query())
            ->select('assetId')
            ->from('{{%blurhash}}')
            ->where(['not', ['blurhash' => null]]);
    }

    /**
     * Image assets that Plugin::isProcessableImage() would accept. Craft
     * derives the MIME type from the filename extension unless it stored one
     * (Craft 5.8+), so this mirrors that in SQL.
     */
    private function eligibleImageQuery(?\DateTimeInterface $cutoff): AssetQuery
    {
        $extensions = array_values(array_filter(
            Assets::getFileKinds()[Asset::KIND_IMAGE]['extensions'] ?? [],
            fn (string $extension) => in_array(
                FileHelper::getMimeTypeByExtension("file.{$extension}"),
                Plugin::ALLOWED_MIME_TYPES,
                true,
            ),
        ));

        $byExtension = Db::parseParam(
            'assets.filename',
            array_map(fn (string $extension) => "*.{$extension}", $extensions),
            caseInsensitive: true,
        ) ?? '0=1';

        $mimeCondition = Craft::$app->getDb()->columnExists(Table::ASSETS, 'mimeType')
            ? ['or', ['assets.mimeType' => Plugin::ALLOWED_MIME_TYPES], ['and', ['assets.mimeType' => null], $byExtension]]
            : $byExtension;

        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->andWhere(['not', ['assets.volumeId' => null]])
            ->andWhere($mimeCondition);

        if ($cutoff) {
            $query->dateCreated('<= '.$cutoff->format(\DateTimeInterface::ATOM));
        }

        return $query;
    }

    public function averageColor(?string $blurhash): ?string
    {
        if ($blurhash === null) {
            return null;
        }

        $value = Base83::decode(substr($blurhash, 2, 4));

        return ColorValidator::normalizeColor('#'.sprintf('%06x', $value));
    }

    private function resolve(Asset $asset): ?BlurhashRecord
    {
        $record = $this->getRecord($asset);

        if (! $record && $this->shouldComputeOnDemand($asset)) {
            $this->queueCompute($asset);
        }

        return $record;
    }

    /**
     * Queues a background computation at most once per asset and request,
     * and skips assets another request queued recently.
     */
    private function queueCompute(Asset $asset): void
    {
        if (isset($this->queuedAssetIds[$asset->id])) {
            return;
        }

        $this->queuedAssetIds[$asset->id] = true;

        if (! Craft::$app->getCache()->add("blurhash:queued:{$asset->id}", true, self::QUEUED_CACHE_TTL)) {
            return;
        }

        Craft::$app->getQueue()->push(new ComputeBlurhashJob([
            'assetId' => $asset->id,
        ]));
    }

    private function shouldComputeOnDemand(Asset $asset): bool
    {
        return Plugin::getInstance()->getSettings()->computeOnDemand
            && Plugin::getInstance()->isProcessable($asset);
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

    /**
     * @return array{?string, bool}
     */
    private function analyze(Asset $asset, string $localPath): array
    {
        try {
            $sample = $this->createThumbnail($localPath);
        } catch (\Throwable $e) {
            Craft::error("Failed to create thumbnail for asset {$asset->id}: {$e->getMessage()}", __METHOD__);

            return [null, false];
        }

        try {
            return [
                $this->encode($asset, $localPath, $sample),
                $this->detectTransparency($asset, $sample),
            ];
        } finally {
            imagedestroy($sample);
        }
    }

    private function encode(Asset $asset, string $localPath, \GdImage $sample): ?string
    {
        try {
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

        $this->assertGdCanDecode($localPath);

        $source = imagecreatefromstring(file_get_contents($localPath));
        if ($source === false) {
            throw new \RuntimeException("GD could not decode {$localPath}");
        }

        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        $sampleWidth = max(1, (int) round(self::SAMPLE_SIZE * min(1, $srcWidth / $srcHeight)));
        $sampleHeight = max(1, (int) round(self::SAMPLE_SIZE * min(1, $srcHeight / $srcWidth)));

        $sample = imagecreatetruecolor($sampleWidth, $sampleHeight);
        imagealphablending($sample, false);
        imagesavealpha($sample, true);
        imagecopyresampled($sample, $source, 0, 0, 0, 0, $sampleWidth, $sampleHeight, $srcWidth, $srcHeight);
        imagedestroy($source);

        return $sample;
    }

    /**
     * GD has to decode the full image, which needs roughly 5 bytes per pixel.
     * Reads only the header to refuse images that would exhaust memory.
     */
    private function assertGdCanDecode(string $localPath): void
    {
        $size = @getimagesize($localPath);
        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            throw new \RuntimeException("Could not read image dimensions of {$localPath}");
        }

        $limit = $this->memoryLimitBytes();
        if ($limit === null) {
            return;
        }

        $required = $size[0] * $size[1] * 5;
        $available = $limit - memory_get_usage();

        if ($required > $available) {
            throw new \RuntimeException("Image of {$size[0]}x{$size[1]} pixels is too large to decode with GD within the memory limit");
        }
    }

    private function memoryLimitBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return null;
        }

        $bytes = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $bytes * 1024 ** 3,
            'm' => $bytes * 1024 ** 2,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    /**
     * Checks the downscaled sample for any non-opaque pixel.
     */
    private function detectTransparency(Asset $asset, \GdImage $sample): bool
    {
        if (! in_array($asset->mimeType, ['image/png', 'image/webp', 'image/gif'], true)) {
            return false;
        }

        return $this->sampleHasAlpha($sample);
    }

    private function sampleHasAlpha(\GdImage $sample): bool
    {
        $width = imagesx($sample);
        $height = imagesy($sample);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                if (imagecolorsforindex($sample, imagecolorat($sample, $x, $y))['alpha'] > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
