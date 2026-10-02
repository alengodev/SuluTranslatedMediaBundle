<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Media\FormatManager\TranslatedFormatManager;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaRepositoryInterface;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\ImageConverterInterface;

/**
 * Progressive formats: an avif/webp request generates only the cheapest missing rendition of the chain.
 */
final class TranslatedFormatManagerProgressiveTest extends TestCase
{
    private const MEDIA_ID = 42;
    private const FORMAT = '400x';

    /** Minimal valid image headers so finfo detects the MIME type of the converted bytes. */
    private const BYTES = [
        'jpg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00",
        'webp' => "RIFF\x24\x00\x00\x00WEBPVP8 ",
        'avif' => "\x00\x00\x00\x1CftypavifAAAA\x00\x00\x00\x00mif1miaf",
        'png' => "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR",
    ];

    private string $cacheDir;

    /** @var list<string> extensions the converter was asked for */
    private array $converted = [];

    protected function setUp(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/tfm-progressive-' . \bin2hex(\random_bytes(4));
        \mkdir($this->cacheDir, 0o777, true);
        $this->converted = [];
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->cacheDir));
    }

    public function testEmptyCacheServesOriginalWithShortCacheControl(): void
    {
        $response = $this->manager()->returnImage(self::MEDIA_ID, self::FORMAT, 'photo.avif');

        self::assertSame(['jpg'], $this->converted);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
        self::assertNotNull($response->getExpires());
        self::assertLessThanOrEqual(\time() + 61, $response->getExpires()->getTimestamp());
        self::assertFileExists($this->path('photo.jpg'));
        self::assertFileDoesNotExist($this->path('photo.avif'));
    }

    public function testExistingOriginalServesWebpNext(): void
    {
        $this->touch('photo.jpg');

        $response = $this->manager()->returnImage(self::MEDIA_ID, self::FORMAT, 'photo.avif');

        self::assertSame(['webp'], $this->converted);
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
        self::assertFileExists($this->path('photo.webp'));
    }

    public function testAllPreviousStepsExistGeneratesRequestedFormatWithLongCache(): void
    {
        $this->touch('photo.jpg');
        $this->touch('photo.webp');

        $response = $this->manager()->returnImage(self::MEDIA_ID, self::FORMAT, 'photo.avif');

        self::assertSame(['avif'], $this->converted);
        self::assertSame('image/avif', $response->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        self::assertFileExists($this->path('photo.avif'));
    }

    public function testWebpRequestOnlyNeedsOriginal(): void
    {
        $this->touch('photo.jpg');

        $this->manager()->returnImage(self::MEDIA_ID, self::FORMAT, 'photo.webp');

        self::assertSame(['webp'], $this->converted);
    }

    public function testPngSourceUsesPngAsFirstStep(): void
    {
        $this->manager(mimeType: 'image/png')->returnImage(self::MEDIA_ID, self::FORMAT, 'logo.avif');

        self::assertSame(['png'], $this->converted);
        self::assertFileExists($this->path('logo.png'));
    }

    public function testJpgRequestIsNotProgressive(): void
    {
        $this->manager()->returnImage(self::MEDIA_ID, self::FORMAT, 'photo.jpg');

        self::assertSame(['jpg'], $this->converted);
    }

    public function testDisabledChainKeepsDefaultBehaviour(): void
    {
        $response = $this->manager(chain: [])->returnImage(self::MEDIA_ID, self::FORMAT, 'photo.avif');

        self::assertSame(['avif'], $this->converted);
        self::assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function testUncacheableIntermediateFallsBackToRequestedFormat(): void
    {
        $response = $this->manager(saveFails: true)->returnImage(self::MEDIA_ID, self::FORMAT, 'photo.avif');

        self::assertSame(['jpg', 'avif'], $this->converted);
        self::assertSame('image/avif', $response->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * @param list<string> $chain
     */
    private function manager(array $chain = ['webp', 'avif'], string $mimeType = 'image/jpeg', bool $saveFails = false): TranslatedFormatManager
    {
        $fileVersion = $this->createMock(FileVersion::class);
        $fileVersion->method('getVersion')->willReturn(1);
        $fileVersion->method('getMimeType')->willReturn($mimeType);

        $file = $this->createMock(File::class);
        $file->method('getFileVersion')->willReturn($fileVersion);
        $file->method('getLatestFileVersion')->willReturn($fileVersion);

        $media = $this->createMock(MediaInterface::class);
        $media->method('getId')->willReturn(self::MEDIA_ID);
        $media->method('getFiles')->willReturn([$file]);

        $repository = $this->createMock(MediaRepositoryInterface::class);
        $repository->method('findMediaByIdForRendering')->willReturn($media);

        $converter = $this->createMock(ImageConverterInterface::class);
        $converter->method('getSupportedOutputImageFormats')->willReturn(
            'image/png' === $mimeType ? ['png', 'jpg', 'gif', 'webp', 'avif'] : ['jpg', 'gif', 'png', 'webp', 'avif'],
        );
        $converter->method('convert')->willReturnCallback(function (FileVersion $fv, string $format, string $extension): string {
            $this->converted[] = $extension;

            return self::BYTES[$extension];
        });

        $formatCache = $this->createMock(FormatCacheInterface::class);
        $formatCache->method('save')->willReturnCallback(function (string $content, int $id, string $fileName, string $format) use ($saveFails): bool {
            if ($saveFails) {
                return false;
            }
            $path = $this->path($fileName, $format, $id);
            @\mkdir(\dirname($path), 0o777, true);
            \file_put_contents($path, $content);

            return true;
        });

        return new TranslatedFormatManager(
            $repository,
            $formatCache,
            $converter,
            true,
            ['Cache-Control' => 'public, immutable, max-age=31536000'],
            [self::FORMAT => ['key' => self::FORMAT, 'scale' => ['x' => 400]]],
            null,
            $chain,
            60,
            $this->cacheDir,
            10,
        );
    }

    private function touch(string $fileName): void
    {
        $path = $this->path($fileName);
        @\mkdir(\dirname($path), 0o777, true);
        \file_put_contents($path, 'x');
    }

    private function path(string $fileName, string $format = self::FORMAT, int $id = self::MEDIA_ID): string
    {
        return $this->cacheDir . '/' . $format . '/' . \sprintf('%02d', $id % 10) . '/' . $id . '-' . $fileName;
    }
}
