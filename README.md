# SuluTranslatedMediaBundle

SEO-friendly translated media filenames for [Sulu CMS](https://sulu.io/) 3.x.

Serves media files under locale-specific SEO filenames (e.g. `/uploads/red-shoes-de.jpg`) while keeping the original file stored under its original name. Includes an "Additional Data" admin tab with locale-aware title, description, and SEO filename fields — plus optional boolean flags (`verifyDownload`, `aiGenerated`).

## Features

- **Translated filenames** — per-locale `seoFilename`, `title`, `description` in `me_media_translations`
- **Built-in Media entity** — ready-to-use `Media` entity extending Sulu's base; no project entity required
- **TranslatedFormatManager** — replaces Sulu's default FormatManager via compiler pass
- **Twig functions** — `sulu_translated_media_url()` / `sulu_translated_media_urls()` with WebP support
- **Format cache warming** — pre-generates the format cache (jpg/webp/avif, x1 & x2) for all image media under their translated filenames
- **Admin tab** — "Additional Data" tab auto-registered in the Sulu Media admin
- **Zero-config** — `sulu_media.objects.media.model` and `sulu_admin` resources are auto-configured

## Requirements

- PHP 8.2+
- Sulu CMS ~3.0
- Symfony 7.x

## Installation

```bash
composer require alengo/sulu-translated-media-bundle
```

Register the bundle in `config/bundles.php`:

```php
Alengo\SuluTranslatedMediaBundle\TranslatedMediaBundle::class => ['all' => true],
```

Import the admin API routes in `config/routes/sulu_admin.yaml`:

```yaml
TranslatedMediaBundle:
    resource: "@TranslatedMediaBundle/Resources/config/routing_admin_api.yaml"
    prefix: /admin/api
```

Run a database migration or schema update to create the `me_media_translations` table:

```bash
bin/adminconsole doctrine:schema:update --force
```

That's it — no further configuration required.

## Twig Usage

```twig
{# Single URL with translated filename #}
{{ sulu_translated_media_url(media, '800x', app.request.locale) }}

{# With explicit format override #}
{{ sulu_translated_media_url(media, '800x', 'de', 'webp') }}

{# All format URLs (default + WebP) for use in <picture> / srcset #}
{% set urls = sulu_translated_media_urls(media, '800x', app.request.locale) %}
<picture>
    <source srcset="{{ urls.webp }}" type="image/webp">
    <img src="{{ urls.default }}">
</picture>
```

## Provided Models

| Class | Purpose |
|---|---|
| `Entity\Media` | Concrete Doctrine entity (`me_media`) — use directly or extend |
| `Entity\MediaTranslations` | Locale rows in `me_media_translations` |
| `Model\MediaTranslationsAwareInterface` + `MediaTranslationsTrait` | Locale fields: `title`, `description`, `seoFilename` |
| `Model\MediaAdditionalDataInterface` + `MediaAdditionalDataTrait` | Boolean flags: `verifyDownload`, `aiGenerated` |

## Commands

### `alengo:translated-media:format-cache:warm`

Pre-generates ("warms") the local format cache (`public/uploads/media`) for every image media, so the frontend never has to generate a thumbnail on the first request. Because the `TranslatedFormatManager` stores each rendition under the *URL filename*, the command warms one cache entry per distinct base filename: the original filename (covers every locale without a SEO override) plus the slugged `seoFilename` of every translation. For each base filename it warms both the x1 variant (e.g. `800x600`) and the x2 / retina variant (`800x600@2x`), each in `jpg`, `webp` and `avif` (intersected with the formats Sulu can actually produce for the source mime type).

**Media scope:** by default only media actually referenced by content (read from Sulu's `re_references` reference store, `resourceKey = "media"`) are warmed — uploaded-but-unused media are skipped. Pass `--no-referenced-only` to warm every image media; an explicit `--media` list always wins. If the reference table is missing the command falls back to all media (with a warning), and if it is empty it warns that references may not be indexed — so it never silently warms nothing.

```bash
bin/console alengo:translated-media:format-cache:warm                       # referenced media only (default)
bin/console alengo:translated-media:format-cache:warm --no-referenced-only  # every image media
bin/console alengo:translated-media:format-cache:warm --dry-run
bin/console alengo:translated-media:format-cache:warm --skip-existing
bin/console alengo:translated-media:format-cache:warm --media=1,42,99 --extensions=webp,avif
```

By default existing cache files are **overwritten** (re-encoded). Pass `--skip-existing` to leave already-cached renditions untouched and only generate the missing ones — much faster for incremental re-runs.

`jpeg` is accepted as an alias for `jpg` — Sulu normalises both to the `jpg` cache extension, so there is no separate `.jpeg` cache file.

| Option | Default | Description |
|---|---|---|
| `--source` / `-s` | `config/app/image-formats.yaml` | Source YAML file with the base format keys (relative to project root) |
| `--extensions` / `-x` | `jpg,webp,avif` | Comma-separated output extensions to warm |
| `--media` / `-m` | _(all)_ | Restrict to a comma-separated list of media IDs (overrides `--referenced-only`) |
| `--no-referenced-only` | | Warm **every** image media, not only those referenced by content (default: referenced only) |
| `--formats` | _(all)_ | Restrict to a comma-separated list of base format keys (a subset of the source file) |
| `--no-2x` | | Skip the `@2x` / retina variants (halves the work) |
| `--parallel` / `-j` | `1` | Number of parallel worker processes |
| `--no-decode-once` | | Disable the default decode-once strategy (decode the source once per rendition via the FormatManager) |
| `--skip-existing` | | Skip renditions that already exist in the cache (faster re-runs) |
| `--dry-run` | | List what would be generated without writing any file |

#### Performance

Warming the full matrix (every media × every format × `@2x` × `jpg`/`webp`/`avif`) is CPU-heavy — AVIF encoding in particular. Several levers shorten the wall-clock time:

- **De-duplicated fan-out (always on)** — the converted bytes of a rendition depend only on the source, format key and extension, *not* on the (translated) URL filename. Each rendition is therefore encoded **once** and the identical bytes are written to every translated filename (original + each SEO name). For multilingual media with several SEO filenames this alone removes the bulk of the redundant conversions.

- **`--parallel=N`** — image conversion is single-threaded per PHP process. Spreading the media over `N` worker processes gives a near-linear speed-up on multi-core hosts. A good starting point is the number of CPU cores:

  ```bash
  bin/console alengo:translated-media:format-cache:warm --parallel=8 --skip-existing
  ```

- **Warm AVIF separately / off-peak** — AVIF is 10–50× slower to encode than `jpg`/`webp`. Warm the cheap formats first so the site is fast immediately, then warm AVIF in the background:

  ```bash
  bin/console alengo:translated-media:format-cache:warm --extensions=jpg,webp --parallel=8
  bin/console alengo:translated-media:format-cache:warm --extensions=avif   --parallel=8 --skip-existing
  ```

  To trade a little AVIF quality for a lot of speed, lower the encoder effort in your Sulu image-format `options` (e.g. an `avif`/`heic` `speed`/`quality` option, depending on your ImageMagick/Imagine build).

- **`--formats` / `--no-2x`** — only warm what the frontend actually requests. Restricting to the handful of formats used in your templates and dropping retina variants shrinks the matrix directly.

- **Decode-once (default, on)** — each source image is decoded a single time per media and reused across all formats and extensions, instead of re-loading and re-decoding the source for every rendition. Restricted to single-layer raster images; animated GIF/WebP, SVG and any decode failure transparently fall back to Sulu's regular converter, so the cached bytes always match the live image proxy. The integration test asserts this byte-for-byte for both the `gd` and `imagick` adapters across `jpg`/`webp`/`avif` (see [Tests](#tests)). Pass `--no-decode-once` to decode per rendition everywhere (the previous, more conservative behaviour).

> `--parallel` runs each worker as a separate `bin/console` process over a slice of the media, aggregating progress into a single bar. It has no effect on `--dry-run` (which does no work).

## Tests

```bash
composer install
composer test          # or: vendor/bin/phpunit
```

- **`tests/Unit`** — pure command logic (translated filename building, option parsing, shard partitioning, worker-summary parsing). No database or container needed.
- **`tests/Integration`** — proves the default decode-once strategy produces **byte-identical** output to Sulu's `ImagineImageConverter` across multiple formats and extensions (`jpg`/`webp`/`avif`), for every locally available Imagine adapter (`gd` and/or `imagick`). Skipped automatically if neither extension is installed.
