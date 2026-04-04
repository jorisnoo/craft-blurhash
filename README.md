# Blurhash for Craft CMS

A [Craft CMS](https://craftcms.com) plugin that generates [blurhash](https://blurha.sh/) placeholders and detects transparency for image assets.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require jorisnoo/craft-blurhash
php craft plugin/install craft-blurhash
```

After installing, run the console command to generate blurhashes for existing assets:

```bash
php craft blurhash/generate
```

## How it works

Blurhash encoding and transparency detection are expensive operations that require downloading and processing each image. This plugin stores the results in a dedicated database table so they only need to be computed once per asset.

### When values are computed

- **On asset save** — When an image is uploaded or replaced, a queue job is pushed to compute the blurhash and transparency in the background.
- **On first template access** — If a template requests a blurhash for an asset that hasn't been processed yet, a queue job is automatically queued. The current request gets a fallback value (`null` for blurhash, `false` for transparency), and the computed value is available on the next render.
- **On failure** — If computation fails (corrupt file, unavailable source), an empty record is saved to prevent the job from being re-queued on every page view. Re-uploading or modifying the asset triggers a fresh computation.

### Why not use Craft's cache?

Running `craft clear-caches/all` during deployment would evict all cached blurhashes, forcing expensive recomputation of every asset. By storing values in a database table, they survive cache clears and deployments.

## Usage

### Twig Filters

```twig
{# Get the blurhash string for an asset #}
{{ asset|blurhash }}

{# Check if an asset has transparency #}
{{ asset|hasTransparency }}
```

### Twig Functions

```twig
{# Convert a blurhash to a base64-encoded data URI (64x64 PNG) #}
{{ blurhashToUri(asset|blurhash) }}

{# Get the average color as a hex string #}
{{ averageColor(asset|blurhash) }}
```

### Example: Lazy-loaded image with blurhash placeholder

```twig
{% set hash = asset|blurhash %}
{% set bgColor = (hash ? averageColor(hash) : null) ?? '#a8a8a8' %}

<div
  style="background-color: {{ bgColor }};{% if hash %} background-image: url({{ blurhashToUri(hash) }}); background-size: cover;{% endif %}"
>
  <img src="{{ asset.getUrl() }}" loading="lazy">
</div>
```

## Console Commands

```bash
# Generate blurhashes for all unprocessed image assets
php craft blurhash/generate

# Force regeneration of all image assets
php craft blurhash/generate --force

# Generate for a specific asset
php craft blurhash/generate 123
```

## Supported Formats

JPEG, PNG, WebP, GIF, AVIF, BMP, and TIFF.

## License

[MIT](LICENSE.md)
