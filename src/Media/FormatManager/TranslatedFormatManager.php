<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Media\FormatManager;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;
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
 */
class TranslatedFormatManager implements FormatManagerInterface
{
    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly FormatCacheInterface $formatCache,
        private readonly ImageConverterInterface $converter,
        private readonly bool $saveImage,
        private readonly array $responseHeaders,
        private array $formats,
        private readonly ?LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function returnImage($id, $formatKey, $fileName, ?int $version = null): Response
    {
        $setExpireHeaders = false;
        $responseContent = null;
        $mimeType = null;
        $status = 200;

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

            $responseContent = $this->converter->convert($fileVersion, $formatKey, $imageFormat);

            $setExpireHeaders = true;

            $finfo = new \finfo(\FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($responseContent);

            // Save with the (translated) URL filename so the web server can serve it directly next time
            if ($this->saveImage) {
                $this->formatCache->save(
                    $responseContent,
                    $media->getId(),
                    $this->replaceExtension($fileName, $imageFormat),
                    $formatKey,
                );
            }
        } catch (ImageProxyException $e) {
            $this->logger->debug($e->getMessage(), ['exception' => $e]);
            $responseContent = null;
            $status = 404;
            $mimeType = null;
        }

        return new Response($responseContent, $status, $this->getResponseHeaders($mimeType, $setExpireHeaders));
    }

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

    public function purge($idMedia, $fileName, $mimeType): void
    {
        $extensions = $this->converter->getSupportedOutputImageFormats($mimeType);
        foreach ($this->formats as $format) {
            foreach ($extensions as $extension) {
                $this->formatCache->purge($idMedia, $this->replaceExtension($fileName, $extension), $format['key']);
            }
        }
    }

    public function clearCache(): void
    {
        $this->formatCache->clear();
    }

    public function getFormatDefinition($formatKey, $locale = null)
    {
        if (!isset($this->formats[$formatKey])) {
            return null;
        }

        return $this->getFormatDefinitionWithMeta($this->formats[$formatKey], $locale);
    }

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

    protected function getResponseHeaders(?string $mimeType = '', bool $setExpireHeaders = false): array
    {
        $headers = [];

        if (!\in_array($mimeType, [null, '', '0'], true)) {
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

    private function getFormatDefinitionWithMeta(array $format, ?string $locale): array
    {
        $title = $format['key'];
        if (isset($format['meta']['title'][$locale])) {
            $title = $format['meta']['title'][$locale];
        }

        return [
            'key' => $format['key'],
            'title' => $title,
            'scale' => $format['scale'],
        ];
    }
}
