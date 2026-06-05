<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Command\WarmMediaFormatCacheCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Unit tests for the command's pure helper logic (filename building, option parsing, shard
 * partitioning, worker-summary parsing). The helpers are private; they are exercised via
 * reflection on a constructor-less instance so no container/DB is required.
 */
final class WarmMediaFormatCacheCommandLogicTest extends TestCase
{
    public function testBuildBaseFilenamesUsesRawOriginalBasename(): void
    {
        self::assertSame(['red-shoes'], $this->buildBaseFilenames('red-shoes.jpg', []));
        // The original basename is taken verbatim (pathinfo), NOT slugged.
        self::assertSame(['My Photo'], $this->buildBaseFilenames('My Photo.JPEG', []));
    }

    public function testBuildBaseFilenamesAppendsSluggedSeoNames(): void
    {
        self::assertSame(
            ['photo', 'red-shoes', 'summer-2024'],
            $this->buildBaseFilenames('photo.png', ['Red Shoes', 'Summer 2024']),
        );
    }

    public function testBuildBaseFilenamesDeduplicatesAgainstOriginalAndEachOther(): void
    {
        // SEO "Red Shoes" slugs to "red-shoes", which equals the original basename → collapsed.
        self::assertSame(['red-shoes'], $this->buildBaseFilenames('red-shoes.jpg', ['Red Shoes', 'red shoes']));
    }

    public function testBuildBaseFilenamesDropsEmptySeoSlugs(): void
    {
        self::assertSame(['p'], $this->buildBaseFilenames('p.jpg', ['', '   ']));
    }

    public function testParseExtensionsNormalisesJpegLowercasesAndDeduplicates(): void
    {
        self::assertSame(['jpg', 'webp', 'avif'], $this->invoke('parseExtensions', ['jpg,webp,avif']));
        self::assertSame(['jpg'], $this->invoke('parseExtensions', ['jpeg']));
        self::assertSame(['jpg', 'webp'], $this->invoke('parseExtensions', ['  JPG , JPEG, webp ']));
        self::assertSame(['jpg'], $this->invoke('parseExtensions', ['jpg,jpg']));
        self::assertSame([], $this->invoke('parseExtensions', [' , ']));
    }

    public function testParseShard(): void
    {
        self::assertNull($this->invoke('parseShard', [null]));
        self::assertNull($this->invoke('parseShard', ['']));
        self::assertSame(['index' => 0, 'count' => 3], $this->invoke('parseShard', ['0/3']));
        self::assertSame(['index' => 2, 'count' => 3], $this->invoke('parseShard', ['2/3']));
        self::assertNull($this->invoke('parseShard', ['3/3']), 'index must be < count');
        self::assertNull($this->invoke('parseShard', ['1/0']), 'count must be >= 1');
        self::assertNull($this->invoke('parseShard', ['-1/3']), 'index must be >= 0');
        self::assertNull($this->invoke('parseShard', ['garbage']));
    }

    public function testSliceShardPartitionsEveryItemExactlyOnce(): void
    {
        $items = [];
        for ($i = 0; $i < 17; ++$i) {
            $items[] = ['id' => $i, 'name' => "n$i", 'mimeType' => 'image/jpeg'];
        }

        $count = 4;
        $seen = [];
        $sizes = [];
        for ($index = 0; $index < $count; ++$index) {
            $slice = $this->invoke('sliceShard', [$items, $index, $count]);
            $sizes[] = \count($slice);
            foreach ($slice as $item) {
                $seen[] = $item['id'];
            }
        }

        \sort($seen);
        self::assertSame(\range(0, 16), $seen, 'every item must appear in exactly one shard, with no gaps or overlaps');
        self::assertSame(17, \array_sum($sizes));
        // Balanced to within one item.
        self::assertLessThanOrEqual(1, \max($sizes) - \min($sizes));
    }

    public function testParseWorkerSummaryReadsLastJsonLine(): void
    {
        $stdout = "PROG\nPROG\n{\"generated\":5,\"failed\":1,\"skippedExisting\":2}\n";
        self::assertSame(
            ['generated' => 5, 'failed' => 1, 'skippedExisting' => 2],
            $this->invoke('parseWorkerSummary', [$stdout]),
        );
    }

    public function testParseWorkerSummaryPrefersTheLastSummaryAndIgnoresTrailingNoise(): void
    {
        $stdout = "{\"generated\":1,\"failed\":0,\"skippedExisting\":0}\n"
            . "{\"generated\":9,\"failed\":0,\"skippedExisting\":3}\n"
            . "Done.\n";
        self::assertSame(
            ['generated' => 9, 'failed' => 0, 'skippedExisting' => 3],
            $this->invoke('parseWorkerSummary', [$stdout]),
        );
    }

    public function testParseWorkerSummaryReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->invoke('parseWorkerSummary', ["just some text\nno json here"]));
        self::assertNull($this->invoke('parseWorkerSummary', ['']));
    }

    public function testParseMediaIdsAndParseList(): void
    {
        self::assertSame([1, 42, 99], $this->invoke('parseMediaIds', ['1, 42 ,99']));
        self::assertSame([], $this->invoke('parseMediaIds', [null]));
        self::assertSame(['800x', '400x300'], $this->invoke('parseList', [' 800x , 400x300 ']));
        self::assertSame([], $this->invoke('parseList', ['  ']));
    }

    /**
     * @param list<string> $seoFilenames
     *
     * @return list<string>
     */
    private function buildBaseFilenames(string $original, array $seoFilenames): array
    {
        /** @var list<string> $result */
        $result = $this->invoke('buildBaseFilenames', [$original, $seoFilenames], $this->slugger());

        return $result;
    }

    /**
     * Invokes a private method of the command on a constructor-less instance.
     *
     * @param array<int, mixed> $args
     */
    private function invoke(string $method, array $args, ?SluggerInterface $slugger = null): mixed
    {
        $instance = (new \ReflectionClass(WarmMediaFormatCacheCommand::class))->newInstanceWithoutConstructor();

        if (null !== $slugger) {
            $property = new \ReflectionProperty(WarmMediaFormatCacheCommand::class, 'slugger');
            $property->setValue($instance, $slugger);
        }

        return (new \ReflectionMethod(WarmMediaFormatCacheCommand::class, $method))->invoke($instance, ...$args);
    }

    private function slugger(): SluggerInterface
    {
        return new AsciiSlugger();
    }
}
