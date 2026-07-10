<?php

use kornrunner\Blurhash\Base83;
use Noo\CraftBlurhash\services\BlurhashService;

it('preserves leading zeroes in the average color', function (int $value, string $expected) {
    $blurhash = '00'.Base83::encode($value, 4);

    expect((new BlurhashService())->averageColor($blurhash))->toBe($expected);
})->with([
    'three significant hex digits' => [0x000abc, '#000abc'],
    'pure blue' => [0x0000ff, '#0000ff'],
    'five significant hex digits' => [0x0abcde, '#0abcde'],
]);
