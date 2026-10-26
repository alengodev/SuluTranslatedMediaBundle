# SuluTranslatedMediaBundle

SEO-friendly translated media filenames for [Sulu CMS](https://sulu.io/) 3.x.

Serves media files under locale-specific SEO filenames (e.g. `/uploads/red-shoes-de.jpg`) while keeping the original file stored under its original name. Includes a configurable "Additional Data" admin tab: a Sulu form defines the fields, and bundle config routes each field to per-media (unlocalized) or per-locale (localized) JSON storage. The per-locale `seoFilename` stays a dedicated, queryable field.

## Features

- **Translated filenames** — dedicated per-locale `seoFilename` in `me_media_translations`
- **Configurable additional data** — a Sulu form plus `localized_keys` / `unlocalized_keys` config routes each field to per-locale or per-media JSON storage
- **Built-in Media entity** — ready-to-use `Media` entity extending Sulu's base; no project entity required
- **TranslatedFormatManager** — replaces Sulu's default FormatManager via compiler pass
- **Twig functions** — `sulu_translated_media_url()` / `sulu_translated_media_urls()` with WebP support
- **Format cache warming** — pre-generates the format cache (jpg/webp/avif, x1 & x2) for all image media under their translated filenames
- **Progressive formats** (opt-in) — on-demand requests generate one rendition step at a time (jpg/png → webp → avif) instead of the expensive format right away
- **Admin tab** — "Additional Data" tab auto-registered in the Sulu Media admin
- **Zero-config** — `sulu_media.objects.media.model` and `sulu_admin` resources are auto-configured

## Requirements

- PHP 8.2+
- Sulu CMS 3.0 or 3.1
- Symfony 7.x or 8.x

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

The bundle also ships Doctrine migrations under `migrations/` (e.g. the move to the
flexible `additional_data` columns). Their path is **auto-registered** with
`doctrine_migrations` (like Sulu's own bundles), so they show up without any host
config:

```bash
bin/adminconsole doctrine:migrations:list      # includes Alengo\SuluTranslatedMediaBundle\Migrations\…
bin/adminconsole doctrine:migrations:migrate
```

> Use the migration (not `doctrine:schema:update`) on installs that hold data: it
> preserves it — carrying `ai_generated` into Sulu's `me_file_versions.origin` and
> packing `title`/`description`/`verify_download` into the new JSON columns.

That's it — no further configuration required.

## Additional data (configurable)

The "Additional Data" admin tab is driven by a Sulu form (`form_key`) plus two lists that route each submitted field to its storage:

- `localized_keys` — stored per locale in `me_media_translations.additional_data`
- `unlocalized_keys` — stored once per media in `me_media.additional_data`

```yaml
# config/packages/alengo_translated_media.yaml
alengo_translated_media:
    form_key: media_additional_data        # the Sulu form defining the fields
    localized_keys: []                     # default (none); e.g. [title, description]
    unlocalized_keys: []                   # default (none); e.g. [verify_download, information]
```

`seoFilename` is always a dedicated per-locale column (the URL builder and cache warmer query it), independent of these lists. The bundle's default form ships **only** `seoFilename`; projects add their own fields by shipping a form with the same `form_key` (Sulu merges forms by section) and listing each field's key under `localized_keys` or `unlocalized_keys`.

> **AI origin:** the former `aiGenerated` flag is gone — Sulu 3.1 provides a native `origin` field on `FileVersion` (values such as `ai_generated`). Set it via Sulu's own media form; the bundle no longer manages it.

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

## Progressive Formats

Image-heavy pages trigger many on-demand conversions on their first visit — and browsers request the
AVIF source of every `<picture>`, the most expensive encoding. With progressive formats enabled, each
request for a deferred format only generates the **cheapest rendition still missing** from the local
format cache and serves it with a short `Cache-Control`:

| Request | Cache contains | Generated & served | Cache-Control |
|---|---|---|---|
| `photo.avif` | — | `photo.jpg` (`image/jpeg`) | `public, max-age=60` |
| `photo.avif` | jpg | `photo.webp` (`image/webp`) | `public, max-age=60` |
| `photo.avif` | jpg, webp | `photo.avif` (`image/avif`) | Sulu response headers (immutable) |

Every request performs at most one encoding; once the file exists the web server serves it directly.
URLs in the HTML never change, so the page cache stays valid. PNG sources start with `png`; SVG/GIF
sources and requests for formats outside the chain (e.g. `.jpg`) are unaffected.

```yaml
# config/packages/alengo_translated_media.yaml
alengo_translated_media:
    progressive_formats:
        enabled: true
        chain: [webp, avif]     # default — generation order after the original format
        fallback_max_age: 60    # default — Cache-Control max-age for intermediate renditions
```

> A CDN in front of `/uploads/media` must respect the origin `Cache-Control`, otherwise an intermediate
> rendition stays cached at the edge.

## Provided Models

| Class | Purpose |
|---|---|
| `Entity\Media` | Concrete Doctrine entity (`me_media`) — use directly or extend |
| `Entity\MediaTranslations` | Locale rows in `me_media_translations` |
| `Model\MediaTranslationsAwareInterface` + `MediaTranslationsTrait` | Per-locale rows: dedicated `seoFilename` + flexible `additionalData` |
| `Model\AdditionalDataAwareInterface` + `AdditionalDataTrait` | Flexible `additionalData` JSON — unlocalized on `Media`, localized on `MediaTranslations` |

## Commands

### `alengo:translated-media:format-cache:warm`

Pre-generates ("warms") the local format cache (`public/uploads/media`) for every image media, so the frontend never has to generate a thumbnail on the first request. Because the `TranslatedFormatManager` stores each rendition under the *URL filename*, the command warms one cache entry per distinct base filename: the original filename (covers every locale without a SEO override) plus the slugged `seoFilename` of every translation. For each base filename it warms both the x1 variant (e.g. `800x600`) and the x2 / retina variant (`800x600@2x`), each in `jpg`, `webp` and `avif` (intersected with the formats Sulu can actually produce for the source mime type).

**Which formats are warmed:** the base format keys are read from the source YAML (`config/app/image-formats.yaml` by default). If it defines a `warm_cache_image_formats:` list, **only** those formats are warmed — keep this to the handful your frontend actually requests so you don't waste time encoding every registered format. If that key is absent the command falls back to the full `image_formats:` list. Example:

```yaml
# config/app/image-formats.yaml
image_formats:
    - '1920x1080'
    - '1280x800'
    - '800x600'
    # … every registered format

warm_cache_image_formats:   # the subset actually warmed
    - '1024x768'
    - '800x600'
    - '640x480'
    - '380x240'
```

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
| `--source` / `-s` | `config/app/image-formats.yaml` | Source YAML file with the base format keys, relative to project root (reads `warm_cache_image_formats:`, falling back to `image_formats:`) |
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
