<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Twig;

use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsAwareInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\MediaBundle\Api\Media as ApiMedia;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Sulu\Bundle\WebsiteBundle\ReferenceStore\ReferenceStoreInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for generating media URLs with SEO filenames from MediaTranslations.
 *
 * Usage:
 *   {{ sulu_translated_media_url(media, '800x') }}
 *   {{ sulu_translated_media_url(media, '800x', 'de') }}
 *   {{ sulu_translated_media_url(media, '800x', 'de', 'webp') }}
 *
 * Get all URLs including additional types (e.g., webp):
 *   {% set urls = sulu_translated_media_urls(media, '800x', 'de') %}
 *   {{ urls.default }}
 *   {{ urls.webp }}
 */
class TranslatedMediaExtension extends AbstractExtension
{
    /** @var array<string, string|null> Request-scoped cache of the resolved SEO base filename, keyed by "id:locale". */
    private array $seoFilenameCache = [];

    /**
     * @param class-string $mediaClass                        The Media entity class (must implement MediaTranslationsAwareInterface)
     * @param array<string, string> $defaultAdditionalTypes   e.g. ['webp' => 'image/webp']
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FormatCacheInterface $formatCache,
        private readonly SluggerInterface $slugger,
        private readonly string $mediaClass,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
        private readonly array $defaultAdditionalTypes = ['webp' => 'image/webp'],
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sulu_translated_media_url', $this->getTranslatedMediaUrl(...)),
            new TwigFunction('sulu_translated_media_urls', $this->getTranslatedMediaUrls(...)),
        ];
    }

    /**
     * Get media URL with translated SEO filename.
     *
     * @param ApiMedia|array<string, mixed>|null $media
     */
    public function getTranslatedMediaUrl(
        ApiMedia|array|null $media,
        string $format,
        ?string $locale = null,
        ?string $extension = null,
    ): ?string {
        $mediaData = $this->extractMediaData($media, $locale);
        if (null === $mediaData) {
            return null;
        }

        // In Sulu 2.6 each resource has its own reference store, so add() takes only the id
        // (the injected store is sulu_media.reference_store.media).
        $this->referenceStore?->add((string) $mediaData['id']);

        $translatedFileName = $this->getTranslatedFileName(
            $mediaData['id'],
            $mediaData['originalFileName'],
            $mediaData['locale'],
            $extension,
        );

        return $this->formatCache->getMediaUrl(
            $mediaData['id'],
            $translatedFileName,
            $format,
            $mediaData['version'],
            $mediaData['subVersion'],
        );
    }

    /**
     * Get all media URLs including additional types (webp, etc.).
     *
     * @param ApiMedia|array<string, mixed>|null $media
     *
     * @return array<string, string|null>
     */
    public function getTranslatedMediaUrls(
        ApiMedia|array|null $media,
        string $format,
        ?string $locale = null,
    ): array {
        $urls = [
            'default' => $this->getTranslatedMediaUrl($media, $format, $locale),
        ];

        foreach (\array_keys($this->defaultAdditionalTypes) as $type) {
            $urls[$type] = $this->getTranslatedMediaUrl($media, $format, $locale, $type);
        }

        return $urls;
    }

    /**
     * @param ApiMedia|array<string, mixed>|null $media
     *
     * @return array{id: int, originalFileName: string, version: int, subVersion: int, locale: ?string}|null
     */
    private function extractMediaData(ApiMedia|array|null $media, ?string $locale): ?array
    {
        if (null === $media) {
            return null;
        }

        if ($media instanceof ApiMedia) {
            $id = $media->getId();
            $originalFileName = $media->getName();
            $version = $media->getVersion();
            $subVersion = $media->getSubVersion();
            $locale ??= $media->getLocale();
        } else {
            // Legacy/array form (e.g. webspaceSettings fallback) — values are untyped.
            $idRaw = $media['id'] ?? null;
            $nameRaw = $media['name'] ?? $media['fileName'] ?? null;
            $versionRaw = $media['version'] ?? null;
            $subVersionRaw = $media['subVersion'] ?? null;

            $id = \is_numeric($idRaw) ? (int) $idRaw : null;
            $originalFileName = \is_string($nameRaw) ? $nameRaw : null;
            $version = \is_numeric($versionRaw) ? (int) $versionRaw : 1;
            $subVersion = \is_numeric($subVersionRaw) ? (int) $subVersionRaw : 0;
        }

        if (null === $id || null === $originalFileName || '' === $originalFileName) {
            return null;
        }

        return [
            'id' => $id,
            'originalFileName' => $originalFileName,
            'version' => $version,
            'subVersion' => $subVersion,
            'locale' => $locale,
        ];
    }

    private function getTranslatedFileName(int $id, string $originalFileName, ?string $locale, ?string $overrideExtension = null): string
    {
        $extension = $overrideExtension ?? $this->normalizeOutputExtension(\pathinfo($originalFileName, \PATHINFO_EXTENSION));
        $fallback = \pathinfo($originalFileName, \PATHINFO_FILENAME);

        if (null === $locale) {
            return $fallback . '.' . $extension;
        }

        // The SEO base filename does not depend on the extension/format, so the
        // ~15-25 srcset/source variants built for one image reuse a single
        // EntityManager::find()+translation lookup instead of repeating it per
        // variant. Cache is request-scoped (Twig extension instance).
        $key = $id . ':' . $locale;
        if (!\array_key_exists($key, $this->seoFilenameCache)) {
            $this->seoFilenameCache[$key] = $this->resolveSeoFilename($id, $locale);
        }

        return ($this->seoFilenameCache[$key] ?? $fallback) . '.' . $extension;
    }

    /**
     * Normalize an original file extension to the extension Sulu actually serves
     * the rendition under.
     *
     * Sulu's ImagineImageConverter::getSupportedOutputImageFormats() whitelists the
     * extensions a format URL may use and defaults everything to "jpg" except
     * png/gif/webp/avif/svg. So a file uploaded as ".jpeg" (or ".JPG", ".jpe", ...)
     * is only ever served as ".jpg" — requesting ".jpeg" returns 404. Mirror that
     * mapping here so the generated fallback `<img>`/`<source>` URLs resolve.
     */
    private function normalizeOutputExtension(string $extension): string
    {
        $extension = \strtolower($extension);

        return \in_array($extension, ['png', 'gif', 'webp', 'avif', 'svg'], true)
            ? $extension
            : 'jpg';
    }

    /**
     * Resolves the slugged SEO filename (without extension) for a media id in a
     * given locale, or null if the media has no SEO filename for that locale.
     */
    private function resolveSeoFilename(int $id, string $locale): ?string
    {
        $media = $this->entityManager->find($this->mediaClass, $id);
        if (!$media instanceof MediaTranslationsAwareInterface) {
            return null;
        }

        foreach ($media->getMediaTranslations() as $translation) {
            if ($translation->getLocale() === $locale && $translation->getSeoFilename()) {
                return $this->slugger->slug($translation->getSeoFilename())->lower()->toString();
            }
        }

        return null;
    }
}
