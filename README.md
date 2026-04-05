# SuluTranslatedMediaBundle

SEO-friendly translated media filenames for [Sulu CMS](https://sulu.io/) 3.x.

Allows serving media files under locale-specific SEO filenames (e.g. `/uploads/red-shoes-de.jpg`) while keeping the original file stored under its original name.

## Features

- **Translated filenames** — per-locale `seoFilename` stored in a separate `me_media_translations` table
- **TranslatedFormatManager** — replaces Sulu's default FormatManager via compiler pass (no manual service override needed)
- **Twig functions** — `sulu_translated_media_url()` and `sulu_translated_media_urls()` with WebP support
- **Admin tab** — configurable "Additional Data" tab in the Sulu Media admin
- **Extensible controller** — `AbstractMediaAdditionalDataController` for adding project-specific fields

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

## Configuration

Create `config/packages/alengo_translated_media.yaml`:

```yaml
alengo_translated_media:
    media_class: App\Entity\Media   # required — must implement MediaTranslationsAwareInterface

    # Admin tab (optional — these are the defaults)
    admin:
        form_key: media_additional_data
        resource_key: media_additional_data
        tab_title: sulu_admin.app.additional_data
```

## Media Entity Setup

Your `Media` entity must implement `MediaTranslationsAwareInterface`. Use the provided trait:

```php
use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsAwareInterface;
use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsTrait;
use Doctrine\ORM\Mapping as ORM;
use Sulu\Bundle\MediaBundle\Entity\Media as SuluMedia;

#[ORM\Table(name: 'me_media')]
#[ORM\Entity]
class Media extends SuluMedia implements MediaTranslationsAwareInterface
{
    use MediaTranslationsTrait;

    public function __construct()
    {
        parent::__construct();
        $this->initMediaTranslations();
    }
}
```

Run a database migration or schema update to create the `me_media_translations` table.

## Admin Form

Create `config/forms/media_additional_data.xml` in your project with at least the translation fields:

```xml
<?xml version="1.0" ?>
<form xmlns="http://schemas.sulu.io/template/template"
      xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:schemaLocation="http://schemas.sulu.io/template/template ...">

    <key>media_additional_data</key>

    <properties>
        <property name="seoFilename" type="text_line">
            <meta><title lang="en">SEO Filename</title></meta>
        </property>
        <property name="title" type="text_line">
            <meta><title lang="en">Title</title></meta>
        </property>
        <property name="description" type="text_area">
            <meta><title lang="en">Description</title></meta>
        </property>
    </properties>
</form>
```

## Admin Controller

Create a controller in your project that extends `AbstractMediaAdditionalDataController` and defines the routes:

```php
use Alengo\SuluTranslatedMediaBundle\Controller\Admin\AbstractMediaAdditionalDataController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MediaAdditionalDataController extends AbstractMediaAdditionalDataController
{
    #[Route('/admin/api/media-additional-data/{id}', methods: ['GET'])]
    public function getAction(Request $request, int $id): Response
    {
        return $this->handleGet($request, $id);
    }

    #[Route('/admin/api/media-additional-data/{id}', methods: ['PUT'])]
    public function put(Request $request, int $id): Response
    {
        return $this->handlePut($request, $id);
    }
}
```

### Adding project-specific fields

Override `getDataForEntity()` and `mapDataToEntity()` to add your own fields alongside the bundle's translation fields:

```php
class MediaAdditionalDataController extends AbstractMediaAdditionalDataController
{
    // ... routes as above ...

    protected function getDataForEntity(MediaTranslationsAwareInterface $entity, ?string $locale): array
    {
        return array_merge(parent::getDataForEntity($entity, $locale), [
            'myField' => $entity instanceof Media ? $entity->getMyField() : null,
        ]);
    }

    protected function mapDataToEntity(array $data, MediaTranslationsAwareInterface $entity, ?string $locale): void
    {
        parent::mapDataToEntity($data, $entity, $locale);

        if ($entity instanceof Media) {
            $entity->setMyField($data['myField'] ?? null);
        }
    }
}
```

## Twig Usage

```twig
{# Simple URL with translated filename #}
{{ sulu_translated_media_url(media, '800x', app.request.locale) }}

{# With explicit extension override #}
{{ sulu_translated_media_url(media, '800x', 'de', 'webp') }}

{# All URLs (default + webp) #}
{% set urls = sulu_translated_media_urls(media, '800x', app.request.locale) %}
<img src="{{ urls.default }}" srcset="{{ urls.webp }} (type: image/webp)">
```
