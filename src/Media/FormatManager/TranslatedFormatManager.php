<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Media\FormatManager;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;
use Sulu\Bundle\MediaBundle\Media\Exception\FormatNotFoundException;
use Sulu\Bundle\MediaBundle\Media\Exception\ImageProxyException;
use Sulu\Bundle\MediaBundle\Media\Exception\ImageProxyInvalidImageFormat;
use Sulu\Bundle\MediaBundle\Media\Exception\ImageProxyInvalidUrl;
use Sulu\Bundle\MediaBundle\Media\Exception\ImageProxyMediaNotFoundException;
use Sulu\Bundle\MediaBundle\Media\Exception\InvalidMimeTypeForPreviewException;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Sulu\Bundle\MediaBundle\Media\FormatManager\FormatManagerInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\ImageConverterInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Custom FormatManager that allows translated filenames in URLs.
 *
 * The default FormatManager validates that the URL filename matches the FileVersion name.
 * This class skips that validation to allow SEO-friendly translated URLs.
 *
 * Progressive formats (opt-in, see `alengo_translated_media.progressive_formats`): a request for a deferred
 * format (e.g. avif) only produces the cheapest rendition that is still missing from the local format cache
 * (original -> webp -> avif) and serves that one with a short Cache-Control. Every request therefore performs
 * at most one encoding, and the requested format exists after a few visits.
 *
 * @phpstan-type ImageFormatDefinition array{
 *     key: string,
 *     scale: array<string, mixed>,
 *     meta?: array{title?: array<string, string>},
 *     internal?: bool,
 *     options?: array<string, mixed>,
 *     transformations?: array<int, mixed>
 * }
 * @phpstan-type FormatDefinition array{internal: mixed, key: string, title: string, scale: mixed}
 */
class TranslatedFormatManager implements FormatManagerInterface
{
    /**
     * @param array<string, string>               $responseHeaders
     * @param array<string, ImageFormatDefinition> $formats
     * @param list<string>                         $progressiveChain deferred extensions in generation order; empty disables progressive formats
     */
    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly FormatCacheInterface $formatCache,
        private readonly ImageConverterInterface $converter,
        private readonly bool $saveImage,
        private readonly array $responseHeaders,
        private array $formats,
        private readonly ?LoggerInterface $logger = new NullLogger(),
        private readonly array $progressiveChain = [],
        private readonly int $progressiveMaxAge = 60,
        private readonly ?string $formatCachePath = null,
        private readonly int $formatCacheSegments = 10,
    ) {
    }

    public function returnImage($id, $formatKey, $fileName, ?int $version = null): Response
    {
        $setExpireHeaders = false;
        $responseContent = null;
        $mimeType = null;
        $status = 200;
        $isIntermediate = false;

        try {
            $info = \pathinfo($fileName);

            if (!isset($info['extension'])) {
                throw new ImageProxyInvalidUrl(\sprintf('No `extension` was found in the url "%s".', $fileName));
            }

            $imageFormat = $info['extension'];

            $media = $this->mediaRepository->findMediaByIdForRendering($id, $formatKey, $version);

            if (!$media) {
                throw new ImageProxyMediaNotFoundException(\sprintf('Media with id "%s" was not found.', $id));
            }

            $fileVersion = $this->getLatestFileVersion($media);
            $version ??= $fileVersion->getVersion();

            /** @var File|null $file */
            $file = $media->getFiles()[0] ?? null;
            $requestedFileVersion = $file?->getFileVersion($version);

            if (!$requestedFileVersion) {
                throw new ImageProxyMediaNotFoundException(\sprintf('Requested FileVersion "%s" for media with id "%s" was not found.', $version, $id));
            }

            // NOTE: Filename validation is intentionally skipped to allow translated/SEO URLs

            if ($fileVersion->getVersion() !== $requestedFileVersion->getVersion()) {
                $formats = $this->getFormats($id, $fileVersion->getName(), $fileVersion->getVersion(), $fileVersion->getSubVersion(), $fileVersion->getMimeType());

                $formatUrl = $formats[$formatKey . '.' . $imageFormat] ?? null;
                if (null === $formatUrl) {
                    throw new ImageProxyMediaNotFoundException(\sprintf('Image format "%s.%s" was not found for media with id "%s".', $formatKey, $imageFormat, $id));
                }

                return new RedirectResponse($formatUrl, Response::HTTP_MOVED_PERMANENTLY);
            }

            $supportedImageFormats = $this->converter->getSupportedOutputImageFormats($fileVersion->getMimeType());
            if ([] === $supportedImageFormats) {
                throw new InvalidMimeTypeForPreviewException($fileVersion->getMimeType() ?? '-null-');
            }

            if (!\in_array($imageFormat, $supportedImageFormats, true)) {
                throw new ImageProxyInvalidImageFormat(
                    \sprintf(
                        'Image format "%s" is not supported. Supported image formats are: "%s"',
                        $imageFormat,
                        \implode(', ', $supportedImageFormats),
                    ),
                );
            }

            $requestedFormat = $imageFormat;
            $step = $this->resolveProgressiveStep((int) $media->getId(), $formatKey, $fileName, $imageFormat, \array_values($supportedImageFormats));
            if (null !== $step) {
                $imageFormat = $step;
                $isIntermediate = true;
            }

            $responseContent = $this->convertAndSave($fileVersion, (int) $media->getId(), $formatKey, $fileName, $imageFormat, $saved);

            // An intermediate step that cannot be cached would be served forever instead of the requested format —
            // generate the requested format right away instead (e.g. filenames the filesystem refuses).
            if ($isIntermediate && !$saved) {
                $this->logger?->warning('Progressive format step "{step}" could not be cached for media #{id} ("{file}"); generating "{format}" directly.', [
                    'step' => $imageFormat,
                    'id' => $media->getId(),
                    'file' => $fileName,
                    'format' => $requestedFormat,
                ]);
                $imageFormat = $requestedFormat;
                $isIntermediate = false;
                $responseContent = $this->convertAndSave($fileVersion, (int) $media->getId(), $formatKey, $fileName, $imageFormat, $saved);
            }

            $setExpireHeaders = !$isIntermediate;

            $finfo = new \finfo(\FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($responseContent) ?: null;
        } catch (ImageProxyException $e) {
            $this->logger?->debug($e->getMessage(), ['exception' => $e]);
            $responseContent = null;
            $status = 404;
            $mimeType = null;
        }

        $headers = $this->getResponseHeaders($mimeType, $setExpireHeaders);
        if ($isIntermediate && 200 === $status) {
            $headers['Cache-Control'] = \sprintf('public, max-age=%d', $this->progressiveMaxAge);
            // An explicit Expires keeps Apache's mod_expires (ExpiresByType image/*) from overriding the short lifetime.
            $headers['Expires'] = \gmdate('D, d M Y H:i:s', \time() + $this->progressiveMaxAge) . ' GMT';
        }

        return new Response($responseContent, $status, $headers);
    }

    /**
     * Converts one rendition and — when enabled — saves it under the (translated) URL filename so the web server
     * can serve it directly next time.
     *
     * @param-out bool $saved
     */
    private function convertAndSave(FileVersion $fileVersion, int $mediaId, string $formatKey, string $fileName, string $extension, ?bool &$saved = null): string
    {
        // The converter is documented as returning an ImageInterface, but every implementation returns the
        // encoded image as a binary string (Imagine's ImageInterface::get()).
        /** @var string $content */
        $content = $this->converter->convert($fileVersion, $formatKey, $extension);

        $saved = $this->saveImage && false !== $this->formatCache->save(
            $content,
            $mediaId,
            $this->replaceExtension($fileName, $extension),
            $formatKey,
        );

        return $content;
    }

    /**
     * Returns the extension to generate instead of the requested one when an earlier step of the progressive
     * chain is still missing from the format cache, or null to generate the requested format itself.
     *
     * @param list<string> $supportedImageFormats first entry is the source's preferred (original) extension
     */
    private function resolveProgressiveStep(int $mediaId, string $formatKey, string $fileName, string $requested, array $supportedImageFormats): ?string
    {
        if (!$this->saveImage || null === $this->formatCachePath || [] === $this->progressiveChain) {
            return null;
        }

        $position = \array_search($requested, $this->progressiveChain, true);
        if (false === $position) {
            return null;
        }

        // Only raster sources — an SVG or (animated) GIF intermediate under an avif/webp URL is not wanted.
        $original = $supportedImageFormats[0] ?? null;
        if (!\in_array($original, ['jpg', 'png', 'webp'], true)) {
            return null;
        }

        $steps = \array_unique([$original, ...\array_slice($this->progressiveChain, 0, (int) $position + 1)]);

        foreach ($steps as $extension) {
            if ($extension === $requested) {
                return null;
            }

            if (!\in_array($extension, $supportedImageFormats, true)) {
                continue;
            }

            if (!\is_file($this->cacheFilePath($mediaId, $formatKey, $this->replaceExtension($fileName, $extension)))) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * Mirrors Sulu's LocalFormatCache::getPath(): <path>/<formatKey>/<segment>/<id>-<fileName>.
     */
    private function cacheFilePath(int $id, string $formatKey, string $fileName): string
    {
        $segment = \sprintf('%0' . \strlen((string) $this->formatCacheSegments) . 'd', $id % $this->formatCacheSegments);

        return \rtrim((string) $this->formatCachePath, '/') . '/' . $formatKey . '/' . $segment . '/' . $id . '-' . $fileName;
    }

    /**
     * @return array<string, string>
     */
    public function getFormats($id, $fileName, $version, $subVersion, $mimeType): array
    {
        $formats = [];

        $extensions = $this->converter->getSupportedOutputImageFormats($mimeType);
        if ([] === $extensions) {
            return [];
        }

        $originalExtension = $extensions[0];
        foreach ($this->formats as $format) {
            foreach ($extensions as $extension) {
                $formatUrl = $this->formatCache->getMediaUrl(
                    $id,
                    $this->replaceExtension($fileName, $extension),
                    $format['key'],
                    $version,
                    $subVersion,
                );

                $formats[$format['key'] . '.' . $extension] = $formatUrl;
                if ($originalExtension === $extension) {
                    $formats[$format['key']] = $formatUrl;
                }
            }
        }

        return $formats;
    }

    public function purge($idMedia, $fileName, $mimeType): bool
    {
        $extensions = $this->converter->getSupportedOutputImageFormats($mimeType);
        foreach ($this->formats as $format) {
            foreach ($extensions as $extension) {
                $this->formatCache->purge($idMedia, $this->replaceExtension($fileName, $extension), $format['key']);
            }
        }

        return true;
    }

    public function clearCache(): void
    {
        $this->formatCache->clear();
    }

    /**
     * @return FormatDefinition
     */
    public function getFormatDefinition($formatKey, $locale = null)
    {
        if (!isset($this->formats[$formatKey])) {
            throw new FormatNotFoundException($formatKey);
        }

        return $this->getFormatDefinitionWithMeta($this->formats[$formatKey], $locale);
    }

    /**
     * @return array<array-key, FormatDefinition>
     */
    public function getFormatDefinitions($locale = null): array
    {
        $formatDefinitions = [];
        foreach ($this->formats as $key => $format) {
            if (isset($format['internal']) && true === $format['internal']) {
                continue;
            }

            $formatDefinitions[$key] = $this->getFormatDefinitionWithMeta($format, $locale);
        }

        return $formatDefinitions;
    }

    private function getLatestFileVersion(MediaInterface $media): FileVersion
    {
        /** @var File|null $file */
        $file = $media->getFiles()[0] ?? null;

        if (!$file) {
            throw new ImageProxyMediaNotFoundException('Media has no file.');
        }

        $fileVersion = $file->getLatestFileVersion();

        if (!$fileVersion) {
            throw new ImageProxyMediaNotFoundException('Media file has no version.');
        }

        return $fileVersion;
    }

    /**
     * @return array<string, string>
     */
    protected function getResponseHeaders(?string $mimeType = '', bool $setExpireHeaders = false): array
    {
        $headers = [];

        if (null !== $mimeType && '' !== $mimeType && '0' !== $mimeType) {
            $headers['Content-Type'] = $mimeType;
        }

        if ($setExpireHeaders) {
            $headers = \array_merge($headers, $this->responseHeaders);
        }

        return $headers;
    }

    private function replaceExtension(string $filename, string $newExtension): string
    {
        $info = \pathinfo($filename);

        return $info['filename'] . '.' . $newExtension;
    }

    /**
     * @param ImageFormatDefinition $format
     *
     * @return FormatDefinition
     */
    private function getFormatDefinitionWithMeta(array $format, ?string $locale): array
    {
        $title = $format['key'];
        if (null !== $locale && isset($format['meta']['title'][$locale])) {
            $title = $format['meta']['title'][$locale];
        }

        return [
            'internal' => $format['internal'] ?? false,
            'key' => $format['key'],
            'title' => $title,
            'scale' => $format['scale'],
        ];
    }
}
