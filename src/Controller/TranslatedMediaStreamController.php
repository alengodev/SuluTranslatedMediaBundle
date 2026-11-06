<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Controller;

use Sulu\Bundle\MediaBundle\Controller\MediaStreamController;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
 *
 * By default only the admin download route is streamed ("admin"): website URLs such as
 * /media/{id}/download/video.mp4 keep Sulu's redirect to the CDN, which serves Range requests
 * (206, required by Safari/iOS for <video>) and keeps the transfer off the application.
 */
class TranslatedMediaStreamController extends MediaStreamController
{
    /** Stream remote files only on the admin route ("Download master file"). */
    public const STREAM_REMOTE_ADMIN = 'admin';

    /** Stream remote files on every download route. */
    public const STREAM_REMOTE_ALL = 'all';

    /** Never stream remote files (Sulu default: redirect to the storage URL). */
    public const STREAM_REMOTE_NONE = 'none';

    /** Sulu route of "Download master file" (path configurable via sulu_media.routing.media_download_path_admin). */
    private const ADMIN_DOWNLOAD_ROUTE = 'sulu_media.website.media.download_admin';

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

    private string $streamRemote = self::STREAM_REMOTE_ADMIN;

    private ?RequestStack $requestStack = null;

    /**
     * Set by TranslatedMediaStreamControllerPass (alengo_translated_media.master_file_download.stream_remote).
     */
    public function setStreamRemote(string $streamRemote): void
    {
        if (!\in_array($streamRemote, [self::STREAM_REMOTE_ADMIN, self::STREAM_REMOTE_ALL, self::STREAM_REMOTE_NONE], true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid stream_remote value "%s", expected "admin", "all" or "none".', $streamRemote));
        }

        $this->streamRemote = $streamRemote;
    }

    public function setRequestStack(RequestStack $requestStack): void
    {
        $this->requestStack = $requestStack;
    }

    protected function getFileResponse(
        FileVersion $fileVersion,
        string $locale,
        string $dispositionType = ResponseHeaderBag::DISPOSITION_ATTACHMENT,
    ): Response {
        if (
            StorageInterface::TYPE_REMOTE !== $this->storage->getType($fileVersion->getStorageOptions())
            || !$this->shouldStreamRemote()
        ) {
            return parent::getFileResponse($fileVersion, $locale, $dispositionType);
        }

        return $this->createStreamedFileResponse($fileVersion, $dispositionType);
    }

    private function shouldStreamRemote(): bool
    {
        return match ($this->streamRemote) {
            self::STREAM_REMOTE_ALL => true,
            self::STREAM_REMOTE_NONE => false,
            default => self::ADMIN_DOWNLOAD_ROUTE === $this->requestStack?->getCurrentRequest()?->attributes->get('_route'),
        };
    }

    private function createStreamedFileResponse(
        FileVersion $fileVersion,
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
            $this->asciiFallbackFileName($fileName, $fileVersion->getExtension()),
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
        $response->headers->set('Content-Length', (string) $fileVersion->getSize());
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

    /**
     * ASCII fallback filename for the Content-Disposition header (the `filename=` part). Modern
     * browsers use the UTF-8 `filename*` part built from the real name, so this only needs to be a
     * safe ASCII stand-in: Symfony requires it to contain no non-ASCII characters, "%", "/" or "\".
     *
     * Deliberately self-contained (no Sulu path-cleanup service) so the controller does not bind to
     * Sulu-version-specific symbols; the service's interface and the property holding it were renamed
     * across the supported Sulu range.
     */
    private function asciiFallbackFileName(string $fileName, ?string $extension): string
    {
        $baseName = \pathinfo($fileName, \PATHINFO_FILENAME);
        $fallback = (string) \preg_replace('/[^A-Za-z0-9._-]/', '-', $baseName);

        if ('' === $fallback) {
            $fallback = 'download';
        }

        if ($extension) {
            $fallback .= '.' . $extension;
        }

        return $fallback;
    }
}
