<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Controller\TranslatedMediaStreamController;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Media\DispositionType\DispositionTypeResolver;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Sulu\Bundle\MediaBundle\Media\FormatManager\FormatManagerInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sulu's MediaStreamController redirects (302) master-file downloads of remote-stored media straight to
 * the storage/CDN URL, dropping the Content-Disposition header — so the browser renders the file inline
 * in a new window instead of downloading it, unlike local storage which streams it as an attachment.
 *
 * TranslatedMediaStreamController streams remote files through the app with the same disposition headers
 * local downloads get, making "Download master file" behave identically regardless of storage backend.
 */
final class TranslatedMediaStreamControllerTest extends TestCase
{
    public function testRemoteStorageIsStreamedAsAttachmentInsteadOfRedirected(): void
    {
        $bytes = 'REMOTE-MASTER-FILE-BYTES';

        $stream = \fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        \fwrite($stream, $bytes);
        \rewind($stream);

        $storageOptions = ['fileName' => 'original.jpg'];

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('getType')->with($storageOptions)->willReturn(StorageInterface::TYPE_REMOTE);
        $storage->method('load')->with($storageOptions)->willReturn($stream);
        $storage->expects(self::never())->method('getPath');

        $fileVersion = $this->fileVersion($storageOptions);

        $controller = $this->controller($storage);

        $response = $this->invokeGetFileResponse(
            $controller,
            $fileVersion,
            'de',
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );

        // StreamedResponse (through the app), never Sulu's bare RedirectResponse to the CDN.
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('original.jpg', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame('sandbox', $response->headers->get('Content-Security-Policy'));

        \ob_start();
        $response->sendContent();
        $sent = (string) \ob_get_clean();

        self::assertSame($bytes, $sent);
    }

    public function testLocalStorageStillUsesTheParentBinaryResponse(): void
    {
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'tms');
        self::assertIsString($tmpFile);
        \file_put_contents($tmpFile, 'LOCAL-BYTES');

        try {
            $storageOptions = ['fileName' => 'original.jpg'];

            $storage = $this->createMock(StorageInterface::class);
            $storage->method('getType')->with($storageOptions)->willReturn(StorageInterface::TYPE_LOCAL);
            $storage->method('getPath')->with($storageOptions)->willReturn($tmpFile);
            $storage->expects(self::never())->method('load');

            $fileVersion = $this->fileVersion($storageOptions);

            $controller = $this->controller($storage);

            $response = $this->invokeGetFileResponse(
                $controller,
                $fileVersion,
                'de',
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            );

            // Local storage keeps the parent's BinaryFileResponse, not our streamed response.
            self::assertInstanceOf(BinaryFileResponse::class, $response);
        } finally {
            @\unlink($tmpFile);
        }
    }

    /**
     * @param array<string, string|null> $storageOptions
     */
    private function fileVersion(array $storageOptions): FileVersion
    {
        $file = $this->createMock(File::class);
        $file->method('getVersion')->willReturn(1);

        $fileVersion = $this->createMock(FileVersion::class);
        $fileVersion->method('getStorageOptions')->willReturn($storageOptions);
        $fileVersion->method('getName')->willReturn('original.jpg');
        $fileVersion->method('getMimeType')->willReturn('image/jpeg');
        $fileVersion->method('getExtension')->willReturn('jpg');
        $fileVersion->method('getSize')->willReturn(\strlen('REMOTE-MASTER-FILE-BYTES'));
        $fileVersion->method('getCreated')->willReturn(new \DateTime('2020-01-01 00:00:00'));
        $fileVersion->method('getVersion')->willReturn(1);
        $fileVersion->method('getFile')->willReturn($file);

        return $fileVersion;
    }

    private function controller(StorageInterface $storage): TranslatedMediaStreamController
    {
        $pathCleanup = $this->createMock(\Sulu\Component\PHPCR\PathCleanupInterface::class);
        $pathCleanup->method('cleanup')->willReturnCallback(static fn (string $name): string => $name);

        return new TranslatedMediaStreamController(
            $this->createMock(DispositionTypeResolver::class),
            $this->createMock(MediaRepositoryInterface::class),
            $pathCleanup,
            $this->createMock(FormatManagerInterface::class),
            $this->createMock(FormatCacheInterface::class),
            $this->createMock(MediaManagerInterface::class),
            $storage,
            null,
        );
    }

    private function invokeGetFileResponse(
        TranslatedMediaStreamController $controller,
        FileVersion $fileVersion,
        string $locale,
        string $dispositionType,
    ): object {
        $method = new \ReflectionMethod($controller, 'getFileResponse');
        $method->setAccessible(true);

        /** @var object $response */
        $response = $method->invoke($controller, $fileVersion, $locale, $dispositionType);

        return $response;
    }
}
