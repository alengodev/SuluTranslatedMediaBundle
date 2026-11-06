<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Controller;

use Sulu\Bundle\MediaBundle\Controller\MediaStreamController;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Makes "Download master file" behave the same for remote (S3/CDN) and local storage.
 *
 * Sulu's MediaStreamController streams locally stored master files as an attachment
 * (Content-Disposition: attachment), but for remote storage it only returns a bare 302
 * redirect to the storage/CDN URL, dropping the disposition entirely. With a public-URL
 * CDN that carries no Content-Disposition, the browser renders the file inline — and the
 * admin's target="_blank" link leaves it open in a new window instead of downloading.
 *
 * This controller streams remote master files through the app with the very same headers a
 * local download gets, honouring the disposition the parent already resolved, so the download
 * is consistent across storage backends. Local storage keeps using the parent's response.
 */
class TranslatedMediaStreamController extends MediaStreamController
{
    /**
     * MIME types a browser renders as a document on the application origin (stored XSS);
     * forced to "attachment" when they would otherwise be served inline. Mirrors Sulu core.
     *
     * @var string[]
     */
    private const DANGEROUS_INLINE_MIME_TYPES = [
        'text/html',
        'application/xhtml+xml',
        'text/xml',
        'application/xml',
    ];

    /**
     * Read-chunk size for copying the remote file to the client. The default stream chunk size
     * is 8 KB, which means a lot of small reads over the storage/HTTP connection for large master
     * files; 512 KB cuts the number of iterations/syscalls without any meaningful memory cost.
     */
    private const STREAM_CHUNK_SIZE = 524288;

    protected function getFileResponse(
        FileVersion $fileVersion,
        string $locale,
        string $dispositionType = ResponseHeaderBag::DISPOSITION_ATTACHMENT,
    ): Response {
        if (StorageInterface::TYPE_REMOTE !== $this->storage->getType($fileVersion->getStorageOptions())) {
            return parent::getFileResponse($fileVersion, $locale, $dispositionType);
        }

        return $this->createStreamedFileResponse($fileVersion, $locale, $dispositionType);
    }

    private function createStreamedFileResponse(
        FileVersion $fileVersion,
        string $locale,
        string $dispositionType,
    ): StreamedResponse {
        $fileName = $fileVersion->getName();
        $mimeType = $fileVersion->getMimeType();
        $storageOptions = $fileVersion->getStorageOptions();

        if (
            ResponseHeaderBag::DISPOSITION_INLINE === $dispositionType
            && $this->isDangerousInlineMimeType($mimeType)
        ) {
            $dispositionType = ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        }

        $storage = $this->storage;
        $response = new StreamedResponse(static function () use ($storage, $storageOptions): void {
            $stream = $storage->load($storageOptions);
            $output = \fopen('php://output', 'wb');
            if (false === $output) {
                return;
            }

            try {
                if (\is_resource($stream)) {
                    \stream_set_chunk_size($stream, self::STREAM_CHUNK_SIZE);
                }
                \stream_copy_to_stream($stream, $output);
            } finally {
                if (\is_resource($stream)) {
                    \fclose($stream);
                }
            }
        });

        $disposition = $response->headers->makeDisposition(
            $dispositionType,
            $fileName,
            $this->cleanUpFileName($fileName, $locale, $fileVersion->getExtension()),
        );

        $file = $fileVersion->getFile();
        if ($file && $fileVersion->getVersion() !== $file->getVersion()) {
            $latestFileVersion = $file->getLatestFileVersion();

            if (null !== $latestFileVersion) {
                $response->headers->set(
                    'Link',
                    \sprintf(
                        '<%s>; rel="canonical"',
                        $this->mediaManager->getUrl(
                            $file->getMedia()->getId(),
                            $latestFileVersion->getName(),
                            $latestFileVersion->getVersion(),
                        ),
                    ),
                );
                $response->headers->set('X-Robots-Tag', 'noindex, follow');
            }
        }

        $response->headers->set('Content-Type', !empty($mimeType) ? $mimeType : 'application/octet-stream');
        $response->headers->set('Content-Disposition', $disposition);

        $fileSize = $fileVersion->getSize();
        if (null !== $fileSize) {
            $response->headers->set('Content-Length', (string) $fileSize);
        }

        $response->headers->set('Last-Modified', $fileVersion->getCreated()->format('D, d M Y H:i:s \G\M\T'));
        $response->headers->set('Content-Security-Policy', 'sandbox');

        return $response;
    }

    private function isDangerousInlineMimeType(?string $mimeType): bool
    {
        if (empty($mimeType)) {
            return false;
        }

        $normalizedMimeType = \strtolower(\trim(\explode(';', $mimeType)[0]));

        return \in_array($normalizedMimeType, self::DANGEROUS_INLINE_MIME_TYPES, true);
    }

    private function cleanUpFileName(string $fileName, string $locale, ?string $extension): string
    {
        $pathInfo = \pathinfo($fileName);

        $cleanedFileName = $this->pathCleanupService()->cleanup($pathInfo['filename'], $locale);
        if ($extension) {
            $cleanedFileName .= '.' . $extension;
        }

        return $cleanedFileName;
    }

    /**
     * Sulu renamed the MediaStreamController's path-cleanup property between versions
     * (pathCleaner -> pathCleanup); read whichever one exists via reflection so the bundle
     * works across the supported Sulu range without binding to one Sulu version's symbols.
     *
     * The real Sulu service does not implement the local PathCleanupContract shim, so the
     * return type stays `object` and the contract is applied by phpdoc for static analysis only.
     *
     * @return PathCleanupContract
     */
    private function pathCleanupService(): object
    {
        $propertyName = \property_exists($this, 'pathCleanup') ? 'pathCleanup' : 'pathCleaner';

        /** @var PathCleanupContract $service */
        $service = (new \ReflectionProperty(MediaStreamController::class, $propertyName))->getValue($this);

        return $service;
    }
}
