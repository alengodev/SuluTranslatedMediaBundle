# Flexible, form-configurable additional media data (Sulu 3.1)

Status: draft for approval · Date: 2026-10-26 · Branch: `3.x`

## Intent & why

The bundle currently hard-codes its "additional data" fields: `verify_download`
and `ai_generated` as boolean columns on `me_media`, and `title` / `description`
as columns on `me_media_translations`. Every project is stuck with those fields.

This change makes the additional-data fields **configurable per project** (a Sulu
form plus bundle config) and adapts the bundle to **Sulu 3.1**, which ships a
native `origin` field on `FileVersion` (`me_file_versions.origin`, `string(31)`,
default `'unknown'`). The bundle's own `ai_generated` flag is retired in favour of
Sulu's `origin`.

## Goals

- Two generic JSON stores routed by config:
  - `me_media.additional_data` — **unlocalized** keys (one value per media).
  - `me_media_translations.additional_data` — **localized** keys (per locale).
- Config lists which form field keys go to which store (`unlocalized_keys` /
  `localized_keys`) and which form drives the UI (`form_key`).
- Keep `seoFilename` a dedicated per-translation column (the URL builder and cache
  warmer query it — `Twig/TranslatedMediaExtension`, DQL on `t.seoFilename`).
- Retire `ai_generated`; carry its value into Sulu's `origin`.

## Non-goals / anti-goals

- No origin **UI** in the bundle and no new `origin` column — `origin` is Sulu's,
  we only *populate* it once via the migration.
- `seoFilename` must **not** move into `additional_data`.
- Must not break URL generation or cache warming.
- No backward-compat shim for the removed `MediaAdditionalDataInterface` (clean cut
  on the 3.x line; confirmed no host depends on it).

## Constraints

- Sulu 3.1, Symfony 7.4–8.1, Doctrine ORM 3 (current `3.x` branch).
- Host naming strategy is `underscore` ⇒ bundle columns are `additional_data`,
  `verify_download`, `ai_generated`, `media_id`, `seo_filename`.
- Sulu join path for the origin migration:
  `me_file_versions.idFiles → me_files.id`, `me_files.idMedia → me_media.id`.

## The decided "how"

### Config (`alengo_translated_media`)

```yaml
alengo_translated_media:
    form_key: media_additional_data          # promoted from admin.form_key
    unlocalized_keys:                        # -> me_media.additional_data
        - verify_download
    localized_keys:                          # -> me_media_translations.additional_data
        - title
        - description
```

- Default lists mirror today's fields so the shipped form keeps working. A host can
  add its own keys (e.g. `information`, `download_title`) and ship its own form.
- `seoFilename` is special-cased (dedicated column); it need not appear in either list.
- `admin.resource_key` / `admin.tab_title` unchanged.

### Entities / model

- New `AdditionalDataAwareInterface` (`getAdditionalData(): array`,
  `setAdditionalData(array): void`) + `AdditionalDataTrait` with
  `#[ORM\Column(type: Types::JSON, nullable: true)] private ?array $additionalData`.
- `Media` (me_media): use the new trait; **remove** `MediaAdditionalDataTrait` /
  `MediaAdditionalDataInterface` (verify_download, ai_generated).
- `MediaTranslations` (me_media_translations): use the new trait; **remove** `title`
  and `description` columns/accessors; keep `seoFilename`, `locale`, `media`.
- `MediaTranslationsTrait::setMediaTranslation()` generalized to `seoFilename` + a
  localized key→value map.

### Controller (`MediaAdditionalDataController`)

- Injected with the two key lists.
- Read: flat payload = `id` + `seoFilename` (locale's translation) + localized keys
  (translation's `additional_data`) + unlocalized keys (media's `additional_data`).
- Write: localized keys → the locale's translation `additional_data`; unlocalized
  keys → media `additional_data`; `seoFilename` → dedicated column; unknown keys ignored.

### Form (`Resources/config/forms/media_additional_data.xml`)

- Default form: `seoFilename` (dedicated), `title` / `description` (localized),
  `verify_download` (unlocalized toggler). The `aiGenerated` field is removed.

### DI (`TranslatedMediaExtension`)

- Read the new config; set parameters for `form_key`, `unlocalized_keys`,
  `localized_keys`; pass the two key lists into the controller.

### Migration (one combined migration, shipped in the bundle under `migrations/`)

Namespace `Alengo\SuluTranslatedMediaBundle\Migrations` (its own `migrations_paths`
entry, so it does not collide with the host's `DoctrineMigrations` path). Targets
MySQL/MariaDB; guards with `abortIf` on other platforms.

**up():**
1. `ALTER TABLE me_media ADD additional_data JSON DEFAULT NULL;`
2. `ALTER TABLE me_media_translations ADD additional_data JSON DEFAULT NULL;`
3. Carry `ai_generated` into Sulu `origin` (all file versions of a media whose
   `ai_generated = 1` get `origin = 'ai_generated'` — Sulu's own value, cf.
   `media_details.xml` `visibleCondition="origin == 'ai_generated' || origin == 'ai_modified'"`;
   others keep Sulu's default):
   `UPDATE me_file_versions fv JOIN me_files f ON fv.idFiles = f.id
    JOIN me_media m ON f.idMedia = m.id SET fv.origin = 'ai_generated' WHERE m.ai_generated = 1;`
4. `UPDATE me_media SET additional_data = JSON_OBJECT('verify_download', verify_download = 1)
    WHERE verify_download IS NOT NULL;`
5. `UPDATE me_media_translations SET additional_data = JSON_OBJECT('title', title, 'description', description)
    WHERE title IS NOT NULL OR description IS NOT NULL;`
6. `ALTER TABLE me_media DROP ai_generated, DROP verify_download;`
7. `ALTER TABLE me_media_translations DROP title, DROP description;`

**down():** re-add the dropped columns, unpack the JSON back into them, drop the two
`additional_data` columns. `origin` is left as-is (Sulu owns it; not reverted).

## My calls (builder-check)

- `ai_generated = 1 → origin = 'ai_generated'` (Sulu's own value) on **all** file
  versions of the media; `0`/`NULL` leaves `origin` untouched.
- Default `unlocalized_keys = [verify_download]`, `localized_keys = [title, description]`.
- One combined migration in `migrations/`, MySQL-targeted with a platform guard.
- JSON column type `json`, nullable; trait `AdditionalDataTrait`, interface
  `AdditionalDataAwareInterface`.

## Verification

- PHPStan level 8 + PHPUnit against the Sulu 3.1 reference install (session harness).
- Update tests that referenced removed fields; add coverage for the controller's
  key-routing. Migration is schema-only verified (host has ~no data; host runs it).
