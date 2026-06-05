<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Command\WarmMediaFormatCacheCommand;
use Alengo\SuluTranslatedMediaBundle\Media\FormatManager\MediaFormatCacheWarmer;
use Doctrine\ORM\EntityManagerInterface;
use Imagine\Image\ImagineInterface;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Sulu\Bundle\MediaBundle\Media\FormatManager\FormatManagerInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\Cropper\CropperInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\Focus\FocusInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\MediaImageExtractorInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\Scaler\ScalerInterface;
use Sulu\Bundle\MediaBundle\Media\ImageConverter\TransformationPoolInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Locks in the command's option contract — most importantly that the decode-once strategy is enabled by
 * default and opt-out via --no-decode-once.
 */
final class WarmMediaFormatCacheCommandOptionsTest extends TestCase
{
    public function testDecodeOnceIsANegatableOptionThatDefaultsToOn(): void
    {
        $option = $this->command()->getDefinition()->getOption('decode-once');

        self::assertTrue($option->isNegatable(), 'decode-once must be negatable so --no-decode-once works');
        self::assertTrue($option->getDefault(), 'decode-once must default to ON');
    }

    public function testDefaultsOfTheOtherPerformanceOptions(): void
    {
        $definition = $this->command()->getDefinition();

        self::assertSame('1', $definition->getOption('parallel')->getDefault());
        self::assertSame('jpg,webp,avif', $definition->getOption('extensions')->getDefault());

        // --no-2x and --skip-existing are simple boolean flags (no value, not negatable).
        self::assertFalse($definition->getOption('no-2x')->acceptValue(), '--no-2x is a boolean flag');
        self::assertFalse($definition->getOption('skip-existing')->acceptValue(), '--skip-existing is a boolean flag');
    }

    private function command(): WarmMediaFormatCacheCommand
    {
        $warmer = new MediaFormatCacheWarmer(
            $this->createStub(FormatManagerInterface::class),
            $this->createStub(FormatCacheInterface::class),
            $this->createStub(ImagineInterface::class),
            $this->createStub(StorageInterface::class),
            $this->createStub(MediaImageExtractorInterface::class),
            $this->createStub(TransformationPoolInterface::class),
            $this->createStub(FocusInterface::class),
            $this->createStub(ScalerInterface::class),
            $this->createStub(CropperInterface::class),
            [],
            '/tmp',
            10,
        );

        return new WarmMediaFormatCacheCommand(
            $warmer,
            $this->createStub(EntityManagerInterface::class),
            new AsciiSlugger(),
            [],
            '/tmp',
        );
    }
}
