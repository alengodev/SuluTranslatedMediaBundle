<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Twig;

use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsAwareInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Bundle\MediaBundle\Api\Media as ApiMedia;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
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
     * @param ApiMedia|array|null $media
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

        $this->referenceStore?->add((string) $mediaData['id'], 'media');

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
     * @param ApiMedia|array|null $media
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
        } elseif (\is_array($media)) {
            $id = $media['id'] ?? null;
            $originalFileName = $media['name'] ?? $media['fileName'] ?? null;
            $version = $media['version'] ?? 1;
            $subVersion = $media['subVersion'] ?? 0;
        } else {
            return null;
        }

        if (null === $id || null === $originalFileName) {
            return null;
        }

        return [
            'id' => (int) $id,
            'originalFileName' => $originalFileName,
            'version' => $version,
            'subVersion' => $subVersion,
            'locale' => $locale,
        ];
    }

    private function getTranslatedFileName(int $id, string $originalFileName, ?string $locale, ?string $overrideExtension = null): string
    {
        $originalExtension = \pathinfo($originalFileName, \PATHINFO_EXTENSION);
        $extension = $overrideExtension ?? $originalExtension;

        if (null === $locale) {
            return \pathinfo($originalFileName, \PATHINFO_FILENAME) . '.' . $extension;
        }

        $media = $this->entityManager->find($this->mediaClass, $id);
        if (!$media instanceof MediaTranslationsAwareInterface) {
            return \pathinfo($originalFileName, \PATHINFO_FILENAME) . '.' . $extension;
        }

        foreach ($media->getMediaTranslations() as $translation) {
            if ($translation->getLocale() === $locale && $translation->getSeoFilename()) {
                $seoFilename = $this->slugger->slug($translation->getSeoFilename())->lower()->toString();

                return $seoFilename . '.' . $extension;
            }
        }

        return \pathinfo($originalFileName, \PATHINFO_FILENAME) . '.' . $extension;
    }
}
