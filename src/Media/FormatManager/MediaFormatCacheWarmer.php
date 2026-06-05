<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Media\FormatManager;

use Imagine\Exception\RuntimeException as ImagineRuntimeException;
use Imagine\Filter\Basic\Autorotate;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Palette\RGB;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\FormatOptions;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Sulu\Bundle\MediaBundle\Media\FormatManager\FormatManagerInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\Cropper\CropperInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\Focus\FocusInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\MediaImageExtractorInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\Scaler\ScalerInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\TransformationPoolInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;

/**
 * Warms the local media format cache for a single media's current file version.
 *
 * It encapsulates two performance optimisations the bare {@see FormatManagerInterface::returnImage()}
 * loop in the warm command cannot do on its own:
 *
 *  1. De-duplicated fan-out: the converted bytes of a rendition depend only on
 *     (file version, format key, output extension) — NOT on the (translated) URL filename, which only
 *     decides where the bytes are stored. So each rendition is produced ONCE and the identical bytes are
 *     written to every requested base filename (original + each slugged SEO name) via the format cache.
 *
 *  2. Optional "decode once" strategy ({@see self::warmMedia()} with $decodeOnce = true): the source image
 *     is loaded and decoded a single time per media, scaled once per format key, and then encoded into each
 *     requested extension — instead of Sulu re-loading and re-decoding the source for every single rendition.
 *     This mirrors {@see \Sulu\Bundle\MediaBundle\Media\ImageConverter\ImagineImageConverter::convert()}
 *     (sulu/sulu ~3.0) but is restricted to single-layer raster images; anything else (animated GIF/WebP,
 *     SVG, decode failure) transparently falls back to the regular FormatManager path so the cached bytes are
 *     always identical to what the live image proxy would produce.
 */
final class MediaFormatCacheWarmer
{
    /**
     * @param array<string, array<string, mixed>> $formats the resolved Sulu image formats (%sulu_media.image.formats%)
     */
    public function __construct(
        private readonly FormatManagerInterface $formatManager,
        private readonly FormatCacheInterface $formatCache,
        private readonly ImagineInterface $imagine,
        private readonly StorageInterface $storage,
        private readonly MediaImageExtractorInterface $mediaImageExtractor,
        private readonly TransformationPoolInterface $transformationPool,
        private readonly FocusInterface $focus,
        private readonly ScalerInterface $scaler,
        private readonly CropperInterface $cropper,
        private readonly array $formats,
        private readonly string $formatCachePath,
        private readonly int $formatCacheSegments,
    ) {
    }

    /**
     * Warms every (format key × extension) rendition of one media, fanning the identical bytes out to all
     * base filenames.
     *
     * @param list<string>  $baseNames   base filenames WITHOUT extension (original + slugged SEO names)
     * @param list<string>  $formatKeys  resolved format keys to warm (incl. @2x)
     * @param list<string>  $extensions  output extensions, already intersected with the supported set
     * @param FileVersion|null $fileVersion the current file version entity — required to enable $decodeOnce
     *
     * @return array{generated: int, failed: int, skippedExisting: int, failures: list<string>}
     */
    public function warmMedia(
        int $mediaId,
        array $baseNames,
        array $formatKeys,
        array $extensions,
        bool $skipExisting,
        bool $dryRun,
        bool $decodeOnce,
        ?FileVersion $fileVersion = null,
    ): array {
        $counts = ['generated' => 0, 'failed' => 0, 'skippedExisting' => 0, 'failures' => []];

        if ([] === $extensions || [] === $baseNames || [] === $formatKeys) {
            return $counts;
        }

        // Decode the shared source image a single time (only when explicitly requested and actually writing).
        $source = null;
        if ($decodeOnce && !$dryRun && null !== $fileVersion) {
            $source = $this->prepareSharedSource($fileVersion);
        }

        foreach ($formatKeys as $formatKey) {
            // Determine, per extension, which base filenames still need writing (respecting --skip-existing).
            $needByExtension = [];
            $anyNeed = false;
            foreach ($extensions as $extension) {
                $need = [];
                foreach ($baseNames as $baseName) {
                    $fileName = $baseName . '.' . $extension;
                    if ($skipExisting && \is_file($this->cacheFilePath($mediaId, $formatKey, $fileName))) {
                        ++$counts['skippedExisting'];

                        continue;
                    }
                    $need[] = $fileName;
                }
                if ([] !== $need) {
                    $anyNeed = true;
                }
                $needByExtension[$extension] = $need;
            }

            if (!$anyNeed) {
                continue;
            }

            if ($dryRun) {
                foreach ($needByExtension as $need) {
                    $counts['generated'] += \count($need);
                }

                continue;
            }

            // Scale the shared source once for this format (decode-once path only; null => FormatManager path).
            $scaled = null !== $source && null !== $fileVersion
                ? $this->renderFormat($source, $formatKey, $fileVersion)
                : null;

            foreach ($needByExtension as $extension => $need) {
                if ([] === $need) {
                    continue;
                }

                $content = null;
                if (null !== $scaled) {
                    try {
                        $content = $scaled->get($extension, $this->encoderOptions($formatKey));
                    } catch (\Throwable) {
                        $content = null; // fall back to the FormatManager for this single rendition
                    }
                }

                if (null === $content) {
                    $content = $this->convertViaFormatManager($mediaId, $formatKey, $need[0]);
                }

                if (null === $content) {
                    $counts['failed'] += \count($need);
                    $counts['failures'][] = \sprintf('media #%d, format "%s", ext "%s"', $mediaId, $formatKey, (string) $extension);

                    continue;
                }

                foreach ($need as $fileName) {
                    $this->formatCache->save($content, $mediaId, $fileName, $formatKey);
                    ++$counts['generated'];
                }
            }
        }

        return $counts;
    }

    /**
     * Computes the local cache path for a rendition, mirroring Sulu's LocalFormatCache::getPath()
     * (path/<formatKey>/<segment>/<id>-<fileName>, segment = id %% segments, zero-padded).
     */
    public function cacheFilePath(int $id, string $formatKey, string $fileName): string
    {
        $segment = \sprintf('%0' . \strlen((string) $this->formatCacheSegments) . 'd', $id % $this->formatCacheSegments);

        return \rtrim($this->formatCachePath, '/') . '/' . $formatKey . '/' . $segment . '/' . $id . '-' . $fileName;
    }

    /**
     * Produces the rendition bytes through the regular (translated) FormatManager — the slow but always-correct
     * path. returnImage() also persists the first filename itself; the caller re-saves it for the remaining
     * base filenames (a harmless duplicate write of identical bytes).
     */
    private function convertViaFormatManager(int $mediaId, string $formatKey, string $fileName): ?string
    {
        $response = $this->formatManager->returnImage($mediaId, $formatKey, $fileName);
        if (200 !== $response->getStatusCode()) {
            return null;
        }

        $content = $response->getContent();

        return false === $content ? null : $content;
    }

    /**
     * Loads + decodes the source image once and applies the source-only steps (RGB, autorotate). Returns null
     * — signalling "use the FormatManager path" — for SVGs, animated/multi-layer images and any failure, so the
     * decode-once fast path is only ever taken for plain single-layer raster images.
     */
    private function prepareSharedSource(FileVersion $fileVersion): ?ImageInterface
    {
        $mimeType = $fileVersion->getMimeType() ?? '';
        if ('image/svg+xml' === $mimeType || 'image/svg' === $mimeType) {
            return null;
        }

        try {
            $resource = $this->mediaImageExtractor->extract(
                $this->storage->load($fileVersion->getStorageOptions()),
                '' !== $mimeType ? $mimeType : '-null-',
            );

            $image = $this->imagine->read($resource);
            $image = $this->toRGB($image);
            $image = $this->autorotate($image);

            try {
                if (\count($image->layers()) > 1) {
                    return null; // animated image — cloning/scaling layers is left to the FormatManager
                }
            } catch (ImagineRuntimeException) {
                // adapters without layer support behave as single-layer images
            }

            return $image;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Applies the per-format steps (crop/focus/scale/transformations/strip/interlace) on a CLONE of the shared
     * source, so the source stays pristine for the next format. Mirrors ImagineImageConverter::convert() for
     * the single-layer case. Returns null on any problem to trigger the FormatManager fallback.
     */
    private function renderFormat(ImageInterface $source, string $formatKey, FileVersion $fileVersion): ?ImageInterface
    {
        if (!isset($this->formats[$formatKey])) {
            return null;
        }
        $format = $this->formats[$formatKey];

        try {
            $image = clone $source;

            $cropParameters = $this->getCropParameters(
                $image,
                $fileVersion->getFormatOptions()->get($formatKey),
                $format,
            );

            if (null !== $cropParameters) {
                $image = $this->cropper->crop(
                    $image,
                    $cropParameters['x'],
                    $cropParameters['y'],
                    $cropParameters['width'],
                    $cropParameters['height'],
                );
            } elseif (isset($format['scale']) && ImageInterface::THUMBNAIL_INSET !== $format['scale']['mode']) {
                $image = $this->applyFocus($image, $fileVersion, $format['scale']);
            }

            if (isset($format['scale'])) {
                $image = $this->scaler->scale(
                    $image,
                    $format['scale']['x'],
                    $format['scale']['y'],
                    $format['scale']['mode'],
                    $format['scale']['forceRatio'],
                    $format['scale']['retina'],
                );
            }

            if (isset($format['transformations'])) {
                foreach ($format['transformations'] as $transformation) {
                    if (!isset($transformation['effect'])) {
                        return null;
                    }
                    $image = $this->transformationPool->get($transformation['effect'])->execute(
                        $image,
                        $transformation['parameters'],
                    );
                }
            }

            $image->strip();

            try {
                if (1 === \count($image->layers())) {
                    $image->interlace(ImageInterface::INTERLACE_PLANE);
                }
            } catch (ImagineRuntimeException) {
                // ignore — some imagine adapters do not implement interlacing
            }

            return $image;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function encoderOptions(string $formatKey): array
    {
        // Single-layer images never trigger Sulu's animated gif/webp options, so the encoder options are just
        // the format's configured imagine options.
        $options = $this->formats[$formatKey]['options'] ?? [];

        return \is_array($options) ? $options : [];
    }

    /**
     * @param array<string, mixed> $format
     *
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function getCropParameters(ImageInterface $image, ?FormatOptions $formatOptions, array $format): ?array
    {
        if (null === $formatOptions) {
            return null;
        }

        $parameters = [
            'x' => $formatOptions->getCropX(),
            'y' => $formatOptions->getCropY(),
            'width' => $formatOptions->getCropWidth(),
            'height' => $formatOptions->getCropHeight(),
        ];

        if ($this->cropper->isValid(
            $image,
            $parameters['x'],
            $parameters['y'],
            $parameters['width'],
            $parameters['height'],
            $format,
        )) {
            return $parameters;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $scale
     */
    private function applyFocus(ImageInterface $image, FileVersion $fileVersion, array $scale): ImageInterface
    {
        $focusX = $fileVersion->getFocusPointX();
        $focusY = $fileVersion->getFocusPointY();
        if (null === $focusX || null === $focusY) {
            return $image;
        }

        return $this->focus->focus($image, $focusX, $focusY, $scale['x'], $scale['y']);
    }

    private function toRGB(ImageInterface $image): ImageInterface
    {
        if ('cmyk' === $image->palette()->name()) {
            $image->usePalette(new RGB());
        }

        return $image;
    }

    private function autorotate(ImageInterface $image): ImageInterface
    {
        return (new Autorotate())->apply($image);
    }
}
