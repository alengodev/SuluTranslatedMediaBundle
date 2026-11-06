<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Controller\TranslatedMediaStreamController;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sulu's MediaStreamController redirects (302) master-file downloads of remote-stored media straight to
 * the storage/CDN URL, dropping the Content-Disposition header — so the browser renders the file inline
 * in a new window instead of downloading it, unlike local storage which streams it as an attachment.
 *
 * TranslatedMediaStreamController streams remote files through the app with the disposition headers a
 * local download gets, making "Download master file" behave the same regardless of storage backend.
 * Local storage is simply delegated to the parent (a one-line else branch) and needs no test here.
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
        $storage->method('getType')->willReturn(StorageInterface::TYPE_REMOTE);
        $storage->method('load')->willReturn($stream);
        // Remote files are streamed through the app, not redirected to the CDN path.
        $storage->expects(self::never())->method('getPath');

        $controller = $this->controller($storage);

        $response = $this->invokeGetFileResponse(
            $controller,
            $this->fileVersion($storageOptions, \strlen($bytes)),
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );

        // StreamedResponse through the app, never Sulu's bare RedirectResponse to the CDN.
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

    /**
     * @param array<string, string|null> $storageOptions
     */
    private function fileVersion(array $storageOptions, int $size): FileVersion
    {
        $file = $this->createMock(File::class);
        $file->method('getVersion')->willReturn(1);

        $fileVersion = $this->createMock(FileVersion::class);
        $fileVersion->method('getStorageOptions')->willReturn($storageOptions);
        $fileVersion->method('getName')->willReturn('original.jpg');
        $fileVersion->method('getMimeType')->willReturn('image/jpeg');
        $fileVersion->method('getExtension')->willReturn('jpg');
        $fileVersion->method('getSize')->willReturn($size);
        $fileVersion->method('getCreated')->willReturn(new \DateTimeImmutable('2020-01-01 00:00:00'));
        // Same version as the file → no canonical-link lookup via the media manager.
        $fileVersion->method('getVersion')->willReturn(1);
        $fileVersion->method('getFile')->willReturn($file);

        return $fileVersion;
    }

    /**
     * Instantiate without the parent constructor and inject only the properties getFileResponse's remote
     * branch uses. The constructor's path-cleanup parameter is typed against a Sulu interface whose
     * namespace differs between versions, and the remote streaming path no longer depends on it.
     */
    private function controller(StorageInterface $storage): TranslatedMediaStreamController
    {
        /** @var TranslatedMediaStreamController $controller */
        $controller = (new \ReflectionClass(TranslatedMediaStreamController::class))->newInstanceWithoutConstructor();

        $this->setProperty($controller, 'storage', $storage);
        $this->setProperty($controller, 'mediaManager', $this->createMock(MediaManagerInterface::class));

        return $controller;
    }

    private function setProperty(object $object, string $name, object $value): void
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function invokeGetFileResponse(
        TranslatedMediaStreamController $controller,
        FileVersion $fileVersion,
        string $dispositionType,
    ): object {
        $method = new \ReflectionMethod($controller, 'getFileResponse');
        $method->setAccessible(true);

        /** @var object $response */
        $response = $method->invoke($controller, $fileVersion, 'de', $dispositionType);

        return $response;
    }
}
