# Blurhash for Craft CMS

A [Craft CMS](https://craftcms.com) plugin that generates [blurhash](https://blurha.sh/) placeholders and detects transparency for image assets.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require jorisnoo/craft-blurhash
php craft plugin/install blurhash
```

After installing, run the console command to generate blurhashes for existing assets:

```bash
php craft blurhash/generate
```

## How it works

Blurhash encoding and transparency detection are expensive operations that require downloading and processing each image. This plugin stores the results in a dedicated database table so they only need to be computed once per asset.

### When values are computed

- **On asset save** — When an image is uploaded or replaced, a queue job is pushed to compute the blurhash and transparency in the background.
- **On first template access** — By default, a missing placeholder queues background work. The current request gets `null` for blurhash and `false` for transparency; a later render sees the result after the worker finishes. A stale placeholder remains available while its replacement is computed.
- **On failure** — A failed first computation leaves an empty row so that page views do not continually retry it. Generate Missing retries empty rows; Regenerate All also refreshes existing values without deleting them first.

### Configuration

To disable queueing from templates, create `config/blurhash.php`:

```php
<?php
return ['computeOnDemand' => false]; // default: true
```

This does not disable processing on save, the console command, or the control panel utility. Run a Craft queue worker for background generation.

### Health check

With `webhubworks/craft-ohdear` installed, add this entry to `healthChecks` in `config/ohdear.php`:

```php
\Noo\CraftBlurhash\health\MissingBlurhashCheck::new()
    ->warnWhenMissingCountIsAbove(0)
    ->failWhenMissingCountIsAbove(50),
```

These are the defaults. The check counts eligible assets older than five minutes; the utility includes recent uploads and lists up to 50 missing assets.

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

JPEG, PNG, WebP, GIF, AVIF and BMP require GD with the applicable codecs. TIFF also requires the Imagick extension with TIFF support. Transparency detection for PNG, WebP and GIF requires Craft’s Imagick image driver; the GD driver reports `false`. The plugin uses GD to encode PNG data URIs even when Imagick is available.

## License

[MIT](LICENSE.md)

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
