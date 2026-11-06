<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Controller\TranslatedMediaStreamController;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sulu's MediaStreamController redirects (302) master-file downloads of remote-stored media straight to
 * the storage/CDN URL, dropping the Content-Disposition header — so the browser renders the file inline
 * in a new window instead of downloading it, unlike local storage which streams it as an attachment.
 *
 * TranslatedMediaStreamController streams remote files through the app with the disposition headers a
 * local download gets, making "Download master file" behave the same regardless of storage backend.
 * By default ("admin") this applies only to the admin download route: website URLs keep the redirect
 * to the CDN (Range requests for videos, no transfer through the app).
 * Local storage is simply delegated to the parent (a one-line else branch) and needs no test here.
 */
final class TranslatedMediaStreamControllerTest extends TestCase
{
    private const ADMIN_ROUTE = 'sulu_media.website.media.download_admin';
    private const WEBSITE_ROUTE = 'sulu_media.website.media.download';
    private const CDN_URL = 'https://cdn.example.com/original.jpg';

    public function testRemoteStorageIsStreamedAsAttachmentInsteadOfRedirected(): void
    {
        $bytes = 'REMOTE-MASTER-FILE-BYTES';

        $storage = $this->remoteStorage($bytes);
        // Remote files are streamed through the app, not redirected to the CDN path.
        $storage->expects(self::never())->method('getPath');

        $controller = $this->controller($storage, null, self::ADMIN_ROUTE);

        $response = $this->invokeGetFileResponse(
            $controller,
            $this->fileVersion(['fileName' => 'original.jpg'], \strlen($bytes)),
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

    public function testWebsiteRouteKeepsRedirectToStorageByDefault(): void
    {
        $storage = $this->remoteStorage('BYTES');
        $storage->expects(self::never())->method('load');

        $response = $this->invokeGetFileResponse(
            $this->controller($storage, null, self::WEBSITE_ROUTE),
            $this->fileVersion(['fileName' => 'original.jpg'], 5),
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::CDN_URL, $response->getTargetUrl());
        self::assertSame(302, $response->getStatusCode());
    }

    public function testWithoutCurrentRequestDefaultModeRedirects(): void
    {
        $storage = $this->remoteStorage('BYTES');

        $response = $this->invokeGetFileResponse(
            $this->controller($storage, null, null),
            $this->fileVersion(['fileName' => 'original.jpg'], 5),
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testModeAllStreamsWebsiteRouteToo(): void
    {
        $response = $this->invokeGetFileResponse(
            $this->controller($this->remoteStorage('BYTES'), TranslatedMediaStreamController::STREAM_REMOTE_ALL, self::WEBSITE_ROUTE),
            $this->fileVersion(['fileName' => 'original.jpg'], 5),
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );

        self::assertInstanceOf(StreamedResponse::class, $response);
    }

    public function testModeNoneRedirectsAdminRouteToo(): void
    {
        $response = $this->invokeGetFileResponse(
            $this->controller($this->remoteStorage('BYTES'), TranslatedMediaStreamController::STREAM_REMOTE_NONE, self::ADMIN_ROUTE),
            $this->fileVersion(['fileName' => 'original.jpg'], 5),
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testInvalidStreamRemoteValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->controller($this->remoteStorage('BYTES'), 'sometimes', null);
    }

    /**
     * Remote storage mock: load() returns the bytes as a stream, getPath() the CDN URL used by Sulu's redirect.
     *
     * @return StorageInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function remoteStorage(string $bytes): StorageInterface
    {
        $stream = \fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        \fwrite($stream, $bytes);
        \rewind($stream);

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('getType')->willReturn(StorageInterface::TYPE_REMOTE);
        $storage->method('load')->willReturn($stream);
        $storage->method('getPath')->willReturn(self::CDN_URL);

        return $storage;
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
     * branches use. The constructor's path-cleanup parameter is typed against a Sulu interface whose
     * namespace differs between versions, and the remote paths do not depend on it.
     *
     * @param string|null $streamRemote null keeps the default ("admin")
     * @param string|null $route        _route of the current request; null = no current request
     */
    private function controller(StorageInterface $storage, ?string $streamRemote, ?string $route): TranslatedMediaStreamController
    {
        /** @var TranslatedMediaStreamController $controller */
        $controller = (new \ReflectionClass(TranslatedMediaStreamController::class))->newInstanceWithoutConstructor();

        $this->setProperty($controller, 'storage', $storage);
        $this->setProperty($controller, 'mediaManager', $this->createMock(MediaManagerInterface::class));

        if (null !== $streamRemote) {
            $controller->setStreamRemote($streamRemote);
        }

        $requestStack = new RequestStack();
        if (null !== $route) {
            $request = Request::create('/media/1/download/original.jpg');
            $request->attributes->set('_route', $route);
            $requestStack->push($request);
        }
        $controller->setRequestStack($requestStack);

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
