<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Integration;

use Alengo\SuluTranslatedMediaBundle\Media\FormatManager\MediaFormatCacheWarmer;
use Imagine\Gd\Imagine as GdImagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Imagine\Imagick\Imagine as ImagickImagine;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Sulu\Bundle\MediaBundle\Media\FormatManager\FormatManagerInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\Cropper\Cropper;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\Focus\Focus;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\ImagineImageConverter;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\MediaImageExtractorInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\Scaler\Scaler;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\TransformationPoolInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;

/**
 * Proves that the warmer's "decode once" strategy — which is the DEFAULT — produces byte-identical output to
 * Sulu's own {@see ImagineImageConverter::convert()}, i.e. that decoding the source a single time and cloning
 * it per format (and reusing one scaled image across extensions) yields exactly what the live image proxy
 * would. This is asserted for every Imagine adapter available locally (imagick and/or gd), since decode-once
 * is now on by default regardless of the configured adapter.
 *
 * Both pipelines are wired with the SAME imagine adapter and converter sub-services, so the test isolates
 * precisely the behaviour that differs: decode-per-call (proxy) vs decode-once + clone reuse (warmer).
 */
final class DecodeOnceEqualityTest extends TestCase
{
    private string $fixturePath = '';

    protected function setUp(): void
    {
        $adapters = $this->availableAdapters();
        if ([] === $adapters) {
            self::markTestSkipped('Neither the imagick nor the gd extension is available.');
        }

        // A small non-uniform source image, so a faulty clone/reuse would change the encoded bytes.
        // A PNG is lossless, so the adapter used to write it does not matter for the comparison.
        $imagine = \reset($adapters);
        $palette = new RGB();
        $image = $imagine->create(new Box(640, 480), $palette->color('#336699'));
        $image->draw()->rectangle(new Point(40, 40), new Point(420, 300), $palette->color('#ffcc00'), true);
        $image->draw()->ellipse(new Point(480, 360), new Box(180, 140), $palette->color('#cc3333'), true);

        $this->fixturePath = \sys_get_temp_dir() . '/alengo_warm_fixture_' . \getmypid() . '.png';
        $image->save($this->fixturePath);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->fixturePath && \is_file($this->fixturePath)) {
            \unlink($this->fixturePath);
        }
    }

    public function testDecodeOnceMatchesTheProxyConverterForEveryAvailableAdapter(): void
    {
        foreach ($this->availableAdapters() as $name => $imagine) {
            $extensions = $this->supportedOutputExtensions($imagine, ['jpg', 'webp', 'avif', 'png']);
            self::assertGreaterThanOrEqual(
                2,
                \count($extensions),
                "adapter '{$name}' supports too few output formats to test extension reuse",
            );

            $this->assertAdapterMatchesProxy($imagine, $name, ['200x200', '150x100'], $extensions);
        }
    }

    /**
     * @param list<string> $formatKeys
     * @param list<string> $extensions
     */
    private function assertAdapterMatchesProxy(ImagineInterface $imagine, string $name, array $formatKeys, array $extensions): void
    {
        $storage = $this->storage($this->fixturePath);
        $extractor = $this->imageExtractor();
        $transformationPool = $this->emptyTransformationPool();
        $focus = new Focus();
        $scaler = new Scaler();
        $cropper = new Cropper();
        $formats = $this->formats();

        // The reference implementation — exactly what the live proxy uses.
        $converter = new ImagineImageConverter(
            $imagine,
            $storage,
            $extractor,
            $transformationPool,
            $focus,
            $scaler,
            $cropper,
            $formats,
            ['image/*'],
            null,
        );

        $capturingCache = $this->capturingCache();

        // returnImage() must never be reached: that would mean the decode-once fast path silently fell back.
        $warmer = new MediaFormatCacheWarmer(
            $this->throwingFormatManager(),
            $capturingCache,
            $imagine,
            $storage,
            $extractor,
            $transformationPool,
            $focus,
            $scaler,
            $cropper,
            $formats,
            \sys_get_temp_dir(),
            10,
        );

        // A single call warms all formats/extensions: source decoded once, cloned per format, reused per ext.
        $result = $warmer->warmMedia(7, ['source'], $formatKeys, $extensions, false, false, true, $this->fileVersion());

        self::assertSame(0, $result['failed'], "{$name}: no rendition should fail");
        self::assertSame(\count($formatKeys) * \count($extensions), $result['generated'], "{$name}: generated count");

        foreach ($formatKeys as $formatKey) {
            foreach ($extensions as $extension) {
                $expected = $converter->convert($this->fileVersion(), $formatKey, $extension);
                $actual = $capturingCache->saved[$formatKey . '|source.' . $extension] ?? null;

                self::assertIsString($actual, "{$name}: missing decode-once output for {$formatKey}/{$extension}");
                self::assertSame(
                    \md5((string) $expected),
                    \md5($actual),
                    "{$name}: decode-once bytes differ from the proxy converter for {$formatKey}/{$extension}",
                );
            }
        }
    }

    /**
     * @return array<string, ImagineInterface>
     */
    private function availableAdapters(): array
    {
        $adapters = [];
        if (\extension_loaded('imagick')) {
            $adapters['imagick'] = new ImagickImagine();
        }
        if (\extension_loaded('gd')) {
            $adapters['gd'] = new GdImagine();
        }

        return $adapters;
    }

    /**
     * Probes which of the candidate extensions the adapter (and its underlying library build) can actually
     * encode, so the test never requests an unsupported format.
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private function supportedOutputExtensions(ImagineInterface $imagine, array $candidates): array
    {
        $probe = $imagine->create(new Box(4, 4), (new RGB())->color('#ffffff'));

        $supported = [];
        foreach ($candidates as $extension) {
            try {
                $probe->get($extension);
                $supported[] = $extension;
            } catch (\Throwable) {
                // unsupported by this library build — skip
            }
        }

        return $supported;
    }

    private function fileVersion(): FileVersion
    {
        $fileVersion = new FileVersion();
        $fileVersion->setName('source.png');
        $fileVersion->setVersion(1);
        $fileVersion->setMimeType('image/png');
        $fileVersion->setStorageOptions([]);

        return $fileVersion;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function formats(): array
    {
        return [
            '200x200' => [
                'key' => '200x200',
                'meta' => ['title' => []],
                'internal' => false,
                'scale' => ['x' => 200, 'y' => 200, 'mode' => ImageInterface::THUMBNAIL_OUTBOUND, 'forceRatio' => true, 'retina' => false],
                'transformations' => [],
                'options' => [],
            ],
            '150x100' => [
                'key' => '150x100',
                'meta' => ['title' => []],
                'internal' => false,
                'scale' => ['x' => 150, 'y' => 100, 'mode' => ImageInterface::THUMBNAIL_OUTBOUND, 'forceRatio' => true, 'retina' => false],
                'transformations' => [],
                'options' => [],
            ],
        ];
    }

    private function storage(string $fixturePath): StorageInterface
    {
        return new class($fixturePath) implements StorageInterface {
            public function __construct(private readonly string $path)
            {
            }

            public function load(array $storageOptions)
            {
                return \fopen($this->path, 'rb');
            }

            public function save(string $tempPath, string $fileName, array $storageOptions = []): array
            {
                throw new \LogicException('not used');
            }

            public function getPath(array $storageOptions): string
            {
                throw new \LogicException('not used');
            }

            public function getType(array $storageOptions): string
            {
                throw new \LogicException('not used');
            }

            public function move(array $sourceStorageOptions, array $targetStorageOptions): array
            {
                throw new \LogicException('not used');
            }

            public function remove(array $storageOptions): void
            {
                throw new \LogicException('not used');
            }
        };
    }

    private function imageExtractor(): MediaImageExtractorInterface
    {
        return new class implements MediaImageExtractorInterface {
            public function extract($resource, string $resourceMimeType)
            {
                return $resource;
            }
        };
    }

    private function emptyTransformationPool(): TransformationPoolInterface
    {
        return new class implements TransformationPoolInterface {
            public function get($name)
            {
                throw new \LogicException('no transformations configured in this test');
            }
        };
    }

    private function throwingFormatManager(): FormatManagerInterface
    {
        return new class implements FormatManagerInterface {
            public function returnImage($id, $formatKey, $imageFormat, ?int $version = null)
            {
                throw new \LogicException('FormatManager fallback must not be reached in the decode-once equality test');
            }

            public function getFormats($id, $fileName, $version, $subVersion, $mimeType)
            {
                return [];
            }

            public function getFormatDefinition($formatKey, $locale = null)
            {
                return null;
            }

            public function getFormatDefinitions($locale = null)
            {
                return [];
            }

            public function purge($idMedia, $fileName, $mimeType)
            {
                return true;
            }

            public function clearCache()
            {
                return true;
            }
        };
    }

    private function capturingCache(): FormatCacheInterface
    {
        return new class implements FormatCacheInterface {
            /** @var array<string, string> */
            public array $saved = [];

            public function save($content, $id, $fileName, $format)
            {
                $this->saved[$format . '|' . $fileName] = $content;

                return true;
            }

            public function purge($id, $fileName, $format)
            {
                return true;
            }

            public function getMediaUrl($id, $fileName, $format, $version, $subVersion)
            {
                return '';
            }

            public function analyzedMediaUrl($url)
            {
                return [];
            }

            public function clear()
            {
                return true;
            }
        };
    }
}
