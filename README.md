# SuluTranslatedMediaBundle

SEO-friendly translated media filenames for [Sulu CMS](https://sulu.io/) 3.x.

Serves media files under locale-specific SEO filenames (e.g. `/uploads/red-shoes-de.jpg`) while keeping the original file stored under its original name. Includes an "Additional Data" admin tab with locale-aware title, description, and SEO filename fields — plus optional boolean flags (`verifyDownload`, `aiGenerated`).

## Features

- **Translated filenames** — per-locale `seoFilename`, `title`, `description` in `me_media_translations`
- **Built-in Media entity** — ready-to-use `Media` entity extending Sulu's base; no project entity required
- **TranslatedFormatManager** — replaces Sulu's default FormatManager via compiler pass
- **Twig functions** — `sulu_translated_media_url()` / `sulu_translated_media_urls()` with WebP support
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
