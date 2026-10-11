<?php

use Noo\CraftBlurhash\services\BlurhashService;

function callPrivate(object $object, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
}

function writePng(int $width, int $height, bool $transparentCorner): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 200, 100, 50, 0));

    if ($transparentCorner) {
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), imagecolorallocatealpha($image, 0, 0, 0, 127));
    }

    $path = tempnam(sys_get_temp_dir(), 'blurhash_test_');
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

it('detects transparency on the downscaled sample', function (bool $transparent) {
    $path = writePng(400, 200, $transparent);

    try {
        $service = new BlurhashService();
        $sample = callPrivate($service, 'createThumbnail', $path);

        expect(imagesx($sample))->toBe(64)
            ->and(imagesy($sample))->toBe(32)
            ->and(callPrivate($service, 'sampleHasAlpha', $sample))->toBe($transparent);
    } finally {
        @unlink($path);
    }
})->with([
    'with transparent area' => [true],
    'fully opaque' => [false],
])->skip(fn () => extension_loaded('imagick'), 'Imagick is preferred over the GD path');

it('parses the memory limit', function (string $limit, ?int $expected) {
    $previous = ini_get('memory_limit');
    ini_set('memory_limit', $limit);

    try {
        expect(callPrivate(new BlurhashService(), 'memoryLimitBytes'))->toBe($expected);
    } finally {
        ini_set('memory_limit', $previous);
    }
})->with([
    'unlimited' => ['-1', null],
    'megabytes' => ['512M', 512 * 1024 ** 2],
    'gigabytes' => ['2G', 2 * 1024 ** 3],
]);
