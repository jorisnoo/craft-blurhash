<?php

namespace Noo\CraftBlurhash\twig;

use craft\elements\Asset;
use Noo\CraftBlurhash\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class BlurhashExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('blurhash', [$this, 'blurhash']),
            new TwigFilter('hasTransparency', [$this, 'hasTransparency']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('blurhashToUri', [$this, 'blurhashToUri']),
            new TwigFunction('averageColor', [$this, 'averageColor']),
        ];
    }

    public function blurhash(Asset $asset): ?string
    {
        return Plugin::getInstance()->blurhash->getBlurhash($asset);
    }

    public function hasTransparency(Asset $asset): bool
    {
        return Plugin::getInstance()->blurhash->getHasTransparency($asset);
    }

    public function blurhashToUri(string $blurhash): string
    {
        return Plugin::getInstance()->blurhash->blurhashToUri($blurhash);
    }

    public function averageColor(string $blurhash): string
    {
        return Plugin::getInstance()->blurhash->averageColor($blurhash);
    }
}
