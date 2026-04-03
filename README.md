# Blurhash for Craft CMS

A [Craft CMS](https://craftcms.com) plugin that automatically generates [blurhash](https://blurha.sh/) placeholders and detects transparency for image assets. Blurhashes are computed in background jobs and stored in a dedicated database table for fast retrieval.

## Features

- Automatic blurhash generation when assets are uploaded or updated
- Transparency detection for each image
- Twig filters and functions for template access
- Console command for bulk generation
- Data URI output for inline placeholder images
- Average color extraction from blurhashes

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require jorisnoo/craft-blurhash
./craft plugin/install craft-blurhash
```

## Usage

### Twig Filters

```twig
{# Get the blurhash string for an asset #}
{{ asset | blurhash }}

{# Check if an asset has transparency #}
{{ asset | hasTransparency }}
```

### Twig Functions

```twig
{# Convert a blurhash to a base64-encoded data URI (64x64 PNG) #}
{{ blurhashToUri(asset | blurhash) }}

{# Get the average color as a hex string #}
{{ averageColor(asset | blurhash) }}
```

### Example: Low-quality image placeholder

```twig
{% set hash = asset | blurhash %}
{% if hash %}
  <img
    src="{{ asset.getUrl() }}"
    style="background-image: url({{ blurhashToUri(hash) }}); background-size: cover;"
    loading="lazy"
  >
{% endif %}
```

## Console Commands

```bash
# Generate blurhashes for all image assets
php craft blurhash/generate

# Force regeneration (overwrite existing)
php craft blurhash/generate --force

# Generate for a specific asset
php craft blurhash/generate --assetId=123
```

## Supported Formats

JPEG, PNG, WebP, GIF, AVIF, BMP, and TIFF.

## License

[MIT](LICENSE.md)
