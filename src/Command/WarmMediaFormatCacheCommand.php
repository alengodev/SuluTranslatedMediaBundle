<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Command;

use Alengo\SuluTranslatedMediaBundle\Entity\MediaTranslations;
use Alengo\SuluTranslatedMediaBundle\Media\FormatManager\MediaFormatCacheWarmer;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Pre-generates ("warms") the local Sulu media format cache (public/uploads/media)
 * for every image media, so the frontend never has to generate a thumbnail on the
 * first request.
 *
 * This command is aware of the translated media URL scheme: the {@see \Alengo\SuluTranslatedMediaBundle\Media\FormatManager\TranslatedFormatManager}
 * stores each rendition under the *URL filename*, which is the slugged, per-locale SEO
 * filename (and falls back to the original filename for locales without one). So for each
 * media the command warms one cache entry per distinct base filename:
 *   - the original filename (covers every locale without a SEO override), plus
 *   - the slugged SEO filename of every MediaTranslation that defines one.
 *
 * For each base filename it warms both the x1 variant (e.g. "800x600") and the x2 / retina
 * variant ("800x600@2x"), each in jpg, webp and avif (intersected with the formats Sulu can
 * actually produce for the source mime type).
 *
 * "jpeg" is accepted as an alias for "jpg" — Sulu normalises both to the "jpg" cache
 * extension, so there is no separate ".jpeg" cache file.
 *
 * Performance options:
 *   --parallel=N        spreads the media over N worker processes (near-linear speed-up on multi-core hosts)
 *   --no-decode-once    disables the default decode-once strategy (decode the source per rendition instead)
 *   --formats / --no-2x / --extensions   shrink the work matrix to only what the frontend actually requests
 *
 * Two optimisations are always/by-default on and handled by {@see MediaFormatCacheWarmer}: the de-duplicated
 * fan-out (convert each rendition once, write the identical bytes to every translated filename), and the
 * decode-once strategy (decode each source image once per media, reuse it across all formats/extensions,
 * falling back to the FormatManager for animated/SVG/edge cases).
 */
#[AsCommand(
    name: 'alengo:translated-media:format-cache:warm',
    description: 'Generate the local media format cache (jpg/webp/avif, x1 & x2, translated filenames) for all image media',
)]
class WarmMediaFormatCacheCommand extends Command
{
    /**
     * @param array<string, array<string, mixed>> $registeredFormats the resolved Sulu image formats (key => definition), incl. @2x keys
     */
    public function __construct(
        private readonly MediaFormatCacheWarmer $warmer,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly array $registeredFormats,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Source YAML file with the base format keys', 'config/app/image-formats.yaml')
            ->addOption('extensions', 'x', InputOption::VALUE_REQUIRED, 'Comma-separated output extensions to warm', 'jpg,webp,avif')
            ->addOption('media', 'm', InputOption::VALUE_REQUIRED, 'Restrict to a comma-separated list of media IDs')
            ->addOption('referenced-only', null, InputOption::VALUE_NEGATABLE, 'Only warm media referenced by content (Sulu re_references); default on, --no-referenced-only warms every image media', true)
            ->addOption('formats', null, InputOption::VALUE_REQUIRED, 'Restrict to a comma-separated list of base format keys (subset of the source file)')
            ->addOption('no-2x', null, InputOption::VALUE_NONE, 'Skip the @2x / retina variants')
            ->addOption('parallel', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel worker processes', '1')
            ->addOption('decode-once', null, InputOption::VALUE_NEGATABLE, 'Decode each source image once and reuse it across all formats/extensions (default: on; pass --no-decode-once to decode per rendition)', true)
            ->addOption('skip-existing', null, InputOption::VALUE_NONE, 'Skip renditions that already exist in the cache (faster re-runs)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be generated without writing any file')
            // Internal: marks a worker process spawned by --parallel and selects its media subset ("index/count").
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'Internal: worker shard as "index/count"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $skipExisting = (bool) $input->getOption('skip-existing');
        $decodeOnce = (bool) $input->getOption('decode-once');
        $noRetina = (bool) $input->getOption('no-2x');
        $referencedOnly = (bool) $input->getOption('referenced-only');
        $parallel = \max(1, (int) $input->getOption('parallel'));
        $shard = $this->parseShard($input->getOption('shard'));
        $isWorker = null !== $shard;

        // 1. Read base format keys from the YAML.
        $sourcePath = $this->projectDir . '/' . $input->getOption('source');
        if (!\file_exists($sourcePath)) {
            $io->error(\sprintf('Source file not found: %s', $sourcePath));

            return Command::FAILURE;
        }

        $parsed = Yaml::parseFile($sourcePath);
        // Prefer the project's explicit warm-cache subset ("warm_cache_image_formats"); fall back to the
        // full "image_formats" list for projects that don't define a subset.
        /** @var list<string> $baseKeys */
        $baseKeys = match (true) {
            \is_array($parsed) && \is_array($parsed['warm_cache_image_formats'] ?? null) => $parsed['warm_cache_image_formats'],
            \is_array($parsed) && \is_array($parsed['image_formats'] ?? null) => $parsed['image_formats'],
            default => [],
        };
        if ([] === $baseKeys) {
            $io->warning('No image formats found in source file (expected "warm_cache_image_formats" or "image_formats").');

            return Command::SUCCESS;
        }

        // Optionally restrict the base keys to a requested subset (--formats).
        $onlyFormats = $this->parseList($input->getOption('formats'));
        if ([] !== $onlyFormats) {
            $unknown = \array_values(\array_diff($onlyFormats, $baseKeys));
            if ([] !== $unknown && !$isWorker) {
                $io->warning(\sprintf('Ignoring %d unknown format key(s): %s', \count($unknown), \implode(', ', $unknown)));
            }
            $baseKeys = \array_values(\array_intersect($baseKeys, $onlyFormats));
            if ([] === $baseKeys) {
                $io->warning('No matching base format keys to warm.');

                return Command::SUCCESS;
            }
        }

        // 2. Expand each base key into [key] (and [key@2x] unless --no-2x), keeping only registered formats.
        /** @var list<string> $formatKeys */
        $formatKeys = [];
        /** @var list<string> $skipped */
        $skipped = [];
        foreach ($baseKeys as $baseKey) {
            $variants = $noRetina ? [$baseKey] : [$baseKey, $baseKey . '@2x'];
            foreach ($variants as $key) {
                if (isset($this->registeredFormats[$key])) {
                    $formatKeys[] = $key;
                } else {
                    $skipped[] = $key;
                }
            }
        }

        if ([] !== $skipped && !$isWorker) {
            $io->warning(\sprintf(
                'Skipping %d format key(s) not registered in config/image-formats.xml: %s',
                \count($skipped),
                \implode(', ', $skipped),
            ));
        }

        // 3. Normalise the requested output extensions (jpeg => jpg, dedup).
        $extensions = $this->parseExtensions((string) $input->getOption('extensions'));

        // 4. Collect the image media to warm. By default only media actually referenced by content
        //    (Sulu's re_references) are warmed; --no-referenced-only warms every image media. An explicit
        //    --media list always wins over the referenced-only default.
        $explicitIds = $this->parseMediaIds($input->getOption('media'));
        if ([] !== $explicitIds) {
            $mediaList = $this->fetchImageMedia($explicitIds);
            $mediaScope = 'explicit --media list';
        } elseif ($referencedOnly) {
            $referencedIds = $this->fetchReferencedMediaIds();
            if (null === $referencedIds) {
                if (!$isWorker) {
                    $io->warning('Reference table "re_references" not available (Sulu ReferenceBundle inactive?) — warming ALL media. Pass --no-referenced-only to make this explicit.');
                }
                $mediaList = $this->fetchImageMedia([]);
                $mediaScope = 'all (reference table unavailable)';
            } else {
                $mediaList = [] === $referencedIds ? [] : $this->fetchImageMedia($referencedIds);
                $mediaScope = \sprintf('referenced only (%d media referenced)', \count($referencedIds));
            }
        } else {
            $mediaList = $this->fetchImageMedia([]);
            $mediaScope = 'all media';
        }

        if ([] === $mediaList) {
            if ($isWorker) {
                $output->writeln((string) \json_encode(['generated' => 0, 'failed' => 0, 'skippedExisting' => 0]));

                return Command::SUCCESS;
            }
            $io->warning($referencedOnly && [] === $explicitIds
                ? 'No referenced image media found. References may not be indexed yet — refresh them, or pass --no-referenced-only to warm every image media.'
                : 'No image media found.');

            return Command::SUCCESS;
        }

        // 5. Parent dispatch: spread the work over N worker processes (skipped for dry-runs, which do no work).
        if ($parallel > 1 && !$isWorker && !$dryRun) {
            return $this->runParallel($io, $input, \count($mediaList), $parallel, $mediaScope);
        }

        // Worker: keep only this shard's slice of the media.
        if (null !== $shard) {
            $mediaList = $this->sliceShard($mediaList, $shard['index'], $shard['count']);
        }

        $seoFilenames = $this->fetchSeoFilenames($explicitIds);

        if (!$isWorker) {
            $io->section('Warming translated media format cache');
            $io->listing([
                \sprintf('Media:         %d', \count($mediaList)),
                \sprintf('Scope:         %s', $mediaScope),
                \sprintf('Format keys:   %d%s', \count($formatKeys), $noRetina ? '' : ' (incl. @2x)'),
                \sprintf('Extensions:    %s', \implode(', ', $extensions)),
                \sprintf('Strategy:      %s', $decodeOnce ? 'decode-once (reuse source)' : 'per-rendition (FormatManager)'),
                \sprintf('Mode:          %s', $dryRun ? 'DRY-RUN (no files written)' : 'write'),
                \sprintf('Skip existing: %s', $skipExisting ? 'yes' : 'no (overwrite)'),
            ]);
        }

        // Progress: a bar for interactive runs; a "PROG" token on STDERR per media for workers (parsed by the parent).
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $progressBar = $isWorker ? null : $io->createProgressBar(\count($mediaList));
        $progressBar?->start();
        $onMediaDone = $isWorker
            ? static function () use ($errOutput): void { $errOutput->write("PROG\n"); }
            : static function () use ($progressBar): void { $progressBar?->advance(); };

        $totals = $this->warmAll($mediaList, $seoFilenames, $formatKeys, $extensions, $skipExisting, $dryRun, $decodeOnce, $onMediaDone);

        $progressBar?->finish();

        // Worker: emit a machine-readable summary on STDOUT and stop here.
        if ($isWorker) {
            $output->writeln((string) \json_encode([
                'generated' => $totals['generated'],
                'failed' => $totals['failed'],
                'skippedExisting' => $totals['skippedExisting'],
            ]));

            return $totals['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $io->newLine(2);

        return $this->reportSummary($io, $totals, $dryRun);
    }

    /**
     * Warms every media in the list, delegating the per-media work to the {@see MediaFormatCacheWarmer} and
     * aggregating the counters. $onMediaDone is invoked once per processed media (progress reporting).
     *
     * @param list<array{id: int, name: string, mimeType: string}> $mediaList
     * @param array<int, list<string>>                              $seoFilenames
     * @param list<string>                                          $formatKeys
     * @param list<string>                                          $extensions
     *
     * @return array{generated: int, failed: int, skippedExisting: int, failures: list<string>}
     */
    private function warmAll(
        array $mediaList,
        array $seoFilenames,
        array $formatKeys,
        array $extensions,
        bool $skipExisting,
        bool $dryRun,
        bool $decodeOnce,
        callable $onMediaDone,
    ): array {
        $totals = ['generated' => 0, 'failed' => 0, 'skippedExisting' => 0, 'failures' => []];

        foreach ($mediaList as $media) {
            $supported = $this->supportedExtensions($media['mimeType']);
            $targetExtensions = \array_values(\array_intersect($extensions, $supported));
            $baseNames = $this->buildBaseFilenames($media['name'], $seoFilenames[$media['id']] ?? []);

            // The decode-once strategy needs the file version entity (storage options, focus point, crop).
            $fileVersion = null;
            if ($decodeOnce && !$dryRun && [] !== $targetExtensions) {
                $fileVersion = $this->loadCurrentFileVersion($media['id']);
            }

            $counts = $this->warmer->warmMedia(
                $media['id'],
                $baseNames,
                $formatKeys,
                $targetExtensions,
                $skipExisting,
                $dryRun,
                $decodeOnce,
                $fileVersion,
            );

            $totals['generated'] += $counts['generated'];
            $totals['failed'] += $counts['failed'];
            $totals['skippedExisting'] += $counts['skippedExisting'];
            foreach ($counts['failures'] as $failure) {
                $totals['failures'][] = $failure;
            }

            // Keep the identity map flat: both returnImage() (via findMediaByIdForRendering) and the
            // decode-once file-version loader pull managed entities into the EM on every media. Without this
            // they accumulate unboundedly over a large library and exhaust memory on a single-process run.
            $this->entityManager->clear();

            $onMediaDone();
        }

        return $totals;
    }

    /**
     * Spawns $parallel worker processes (one per media shard) and aggregates their summaries while rendering a
     * single global progress bar fed by the workers' per-media STDERR tokens.
     */
    private function runParallel(SymfonyStyle $io, InputInterface $input, int $total, int $parallel, string $mediaScope): int
    {
        $parallel = \max(1, \min($parallel, $total));

        $io->section('Warming translated media format cache (parallel)');
        $io->listing([
            \sprintf('Media:    %d', $total),
            \sprintf('Scope:    %s', $mediaScope),
            \sprintf('Workers:  %d', $parallel),
            \sprintf('Strategy: %s', $input->getOption('decode-once') ? 'decode-once (reuse source)' : 'per-rendition (FormatManager)'),
        ]);

        $console = $this->resolveConsoleBinary();

        /** @var array<int, Process> $processes */
        $processes = [];
        for ($shard = 0; $shard < $parallel; ++$shard) {
            $args = [
                \PHP_BINARY,
                $console,
                (string) $this->getName(),
                '--shard=' . $shard . '/' . $parallel,
                '--source=' . (string) $input->getOption('source'),
                '--extensions=' . (string) $input->getOption('extensions'),
                '--no-interaction',
            ];
            if (null !== $input->getOption('formats')) {
                $args[] = '--formats=' . (string) $input->getOption('formats');
            }
            if (null !== $input->getOption('media')) {
                $args[] = '--media=' . (string) $input->getOption('media');
            }
            if ($input->getOption('skip-existing')) {
                $args[] = '--skip-existing';
            }
            if ($input->getOption('no-2x')) {
                $args[] = '--no-2x';
            }
            $args[] = $input->getOption('decode-once') ? '--decode-once' : '--no-decode-once';
            $args[] = $input->getOption('referenced-only') ? '--referenced-only' : '--no-referenced-only';

            $process = new Process($args, $this->projectDir, null, null, null);
            $process->start();
            $processes[$shard] = $process;
        }

        $progressBar = $io->createProgressBar($total);
        $progressBar->start();

        do {
            $running = false;
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $running = true;
                }
                $this->advanceFromWorker($progressBar, $process->getIncrementalErrorOutput());
            }
            if ($running) {
                \usleep(100_000);
            }
        } while ($running);

        // Final drain of any buffered progress tokens.
        foreach ($processes as $process) {
            $this->advanceFromWorker($progressBar, $process->getIncrementalErrorOutput());
        }

        $progressBar->finish();
        $io->newLine(2);

        $totals = ['generated' => 0, 'failed' => 0, 'skippedExisting' => 0, 'failures' => []];
        foreach ($processes as $shard => $process) {
            $process->wait(); // no-op if already finished; guarantees the full STDOUT (summary line) is captured
            $summary = $this->parseWorkerSummary($process->getOutput());
            if (null === $summary) {
                $totals['failures'][] = \sprintf('Worker %d produced no summary (exit code %s).', $shard, (string) $process->getExitCode());

                continue;
            }
            $totals['generated'] += $summary['generated'];
            $totals['failed'] += $summary['failed'];
            $totals['skippedExisting'] += $summary['skippedExisting'];
            if (!$process->isSuccessful()) {
                $totals['failures'][] = \sprintf('Worker %d exited with code %s.', $shard, (string) $process->getExitCode());
            }
        }

        return $this->reportSummary($io, $totals, false);
    }

    /**
     * Resolves the console binary the parent was invoked with, so workers boot the identical Sulu context
     * (bin/console, bin/adminconsole, bin/websiteconsole, …). Falls back to the project's bin/console.
     */
    private function resolveConsoleBinary(): string
    {
        $candidates = [];
        if (isset($_SERVER['argv'][0]) && \is_string($_SERVER['argv'][0])) {
            $candidates[] = $_SERVER['argv'][0];
        }
        if (isset($_SERVER['SCRIPT_FILENAME']) && \is_string($_SERVER['SCRIPT_FILENAME'])) {
            $candidates[] = $_SERVER['SCRIPT_FILENAME'];
        }

        foreach ($candidates as $candidate) {
            if ('' === $candidate) {
                continue;
            }
            if (\is_file($candidate)) {
                return $candidate;
            }
            $absolute = $this->projectDir . '/' . \ltrim($candidate, '/');
            if (\is_file($absolute)) {
                return $absolute;
            }
        }

        return $this->projectDir . '/bin/console';
    }

    private function advanceFromWorker(ProgressBar $progressBar, string $stderrChunk): void
    {
        if ('' === $stderrChunk) {
            return;
        }
        $advance = \substr_count($stderrChunk, 'PROG');
        if ($advance > 0) {
            $progressBar->advance($advance);
        }
    }

    /**
     * @param array{generated: int, failed: int, skippedExisting: int, failures: list<string>} $totals
     */
    private function reportSummary(SymfonyStyle $io, array $totals, bool $dryRun): int
    {
        $skippedNote = $totals['skippedExisting'] > 0
            ? \sprintf(' (%d already cached, skipped)', $totals['skippedExisting'])
            : '';

        if ($dryRun) {
            $io->success(\sprintf('Dry-run: %d cache file(s) would be generated%s.', $totals['generated'], $skippedNote));

            return Command::SUCCESS;
        }

        if ([] !== $totals['failures']) {
            foreach (\array_slice($totals['failures'], 0, 20) as $failure) {
                $io->warning('Failed: ' . $failure);
            }
            if (\count($totals['failures']) > 20) {
                $io->warning(\sprintf('… and %d more failure(s).', \count($totals['failures']) - 20));
            }
        }

        if ($totals['failed'] > 0 || [] !== $totals['failures']) {
            $io->warning(\sprintf('Generated %d cache file(s)%s, %d failed.', $totals['generated'], $skippedNote, $totals['failed']));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Generated %d cache file(s)%s.', $totals['generated'], $skippedNote));

        return Command::SUCCESS;
    }

    /**
     * Builds the distinct base filenames (without extension) the frontend may request for
     * a media: the original filename (fallback for locales without a SEO name) plus the
     * slugged SEO filename of every translation.
     *
     * @param list<string> $seoFilenames
     *
     * @return list<string>
     */
    private function buildBaseFilenames(string $originalFileName, array $seoFilenames): array
    {
        $baseNames = [\pathinfo($originalFileName, \PATHINFO_FILENAME) => true];

        foreach ($seoFilenames as $seoFilename) {
            $slug = $this->slugger->slug($seoFilename)->lower()->toString();
            if ('' !== $slug) {
                $baseNames[$slug] = true;
            }
        }

        return \array_keys($baseNames);
    }

    /**
     * @return list<string>
     */
    private function parseExtensions(string $raw): array
    {
        $extensions = [];
        foreach (\explode(',', $raw) as $extension) {
            $extension = \strtolower(\trim($extension));
            if ('' === $extension) {
                continue;
            }
            if ('jpeg' === $extension) {
                $extension = 'jpg';
            }
            $extensions[$extension] = true;
        }

        return \array_keys($extensions);
    }

    /**
     * @return list<string>
     */
    private function parseList(?string $raw): array
    {
        if (null === $raw || '' === \trim($raw)) {
            return [];
        }

        return \array_values(\array_filter(\array_map(
            static fn (string $value): string => \trim($value),
            \explode(',', $raw),
        ), static fn (string $value): bool => '' !== $value));
    }

    /**
     * @return list<int>
     */
    private function parseMediaIds(?string $raw): array
    {
        if (null === $raw || '' === \trim($raw)) {
            return [];
        }

        return \array_values(\array_filter(\array_map(
            static fn (string $id): int => (int) \trim($id),
            \explode(',', $raw),
        )));
    }

    /**
     * Parses the internal worker shard option ("index/count").
     *
     * @return array{index: int, count: int}|null
     */
    private function parseShard(?string $raw): ?array
    {
        if (null === $raw || '' === \trim($raw)) {
            return null;
        }

        $parts = \explode('/', $raw, 2);
        $index = (int) $parts[0];
        $count = (int) ($parts[1] ?? 0);
        if ($count < 1 || $index < 0 || $index >= $count) {
            return null;
        }

        return ['index' => $index, 'count' => $count];
    }

    /**
     * Keeps every $count-th media starting at $index (balanced, gap-agnostic sharding by position).
     *
     * @param list<array{id: int, name: string, mimeType: string}> $mediaList
     *
     * @return list<array{id: int, name: string, mimeType: string}>
     */
    private function sliceShard(array $mediaList, int $index, int $count): array
    {
        $slice = [];
        foreach ($mediaList as $position => $media) {
            if ($position % $count === $index) {
                $slice[] = $media;
            }
        }

        return $slice;
    }

    /**
     * Extracts the worker's machine-readable summary (the last JSON line on its STDOUT).
     *
     * @return array{generated: int, failed: int, skippedExisting: int}|null
     */
    private function parseWorkerSummary(string $stdout): ?array
    {
        $lines = \preg_split('/\r?\n/', \trim($stdout)) ?: [];
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            $line = \trim($lines[$i]);
            if ('' === $line || '{' !== $line[0]) {
                continue;
            }
            /** @var mixed $data */
            $data = \json_decode($line, true);
            if (\is_array($data) && isset($data['generated'], $data['failed'], $data['skippedExisting'])) {
                return [
                    'generated' => (int) $data['generated'],
                    'failed' => (int) $data['failed'],
                    'skippedExisting' => (int) $data['skippedExisting'],
                ];
            }
        }

        return null;
    }

    /**
     * Returns the IDs of media referenced by content, read from Sulu's reference store
     * (re_references, resourceKey = "media"). Returns null when that table is unavailable (e.g. the
     * Sulu ReferenceBundle is not active), so the caller can fall back to warming all media.
     *
     * @return list<int>|null
     */
    private function fetchReferencedMediaIds(): ?array
    {
        $connection = $this->entityManager->getConnection();
        $table = $connection->quoteIdentifier('re_references');
        $resourceId = $connection->quoteIdentifier('resourceId');
        $resourceKey = $connection->quoteIdentifier('resourceKey');

        try {
            /** @var list<mixed> $rows */
            $rows = $connection->fetchFirstColumn(
                \sprintf('SELECT DISTINCT %s FROM %s WHERE %s = :resourceKey', $resourceId, $table, $resourceKey),
                ['resourceKey' => MediaInterface::RESOURCE_KEY],
            );
        } catch (\Throwable) {
            return null;
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Fetches the current file version (name + mime type) of every image media.
     *
     * @param list<int> $mediaIds optional restriction
     *
     * @return list<array{id: int, name: string, mimeType: string}>
     */
    private function fetchImageMedia(array $mediaIds): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(f.media) AS id', 'fv.name AS name', 'fv.mimeType AS mimeType')
            ->from(FileVersion::class, 'fv')
            ->join('fv.file', 'f')
            ->where('fv.version = f.version')
            ->andWhere('fv.mimeType LIKE :imageMime')
            ->setParameter('imageMime', 'image/%')
            ->orderBy('id', 'ASC');

        if ([] !== $mediaIds) {
            $qb->andWhere('IDENTITY(f.media) IN (:ids)')->setParameter('ids', $mediaIds);
        }

        /** @var list<array{id: int|string, name: string, mimeType: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return \array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'mimeType' => $row['mimeType'],
            ],
            $rows,
        );
    }

    /**
     * Fetches all non-empty SEO filenames, grouped by media id.
     *
     * @param list<int> $mediaIds optional restriction
     *
     * @return array<int, list<string>>
     */
    private function fetchSeoFilenames(array $mediaIds): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(t.media) AS id', 't.seoFilename AS seoFilename')
            ->from(MediaTranslations::class, 't')
            ->where('t.seoFilename IS NOT NULL')
            ->andWhere("t.seoFilename != ''");

        if ([] !== $mediaIds) {
            $qb->andWhere('IDENTITY(t.media) IN (:ids)')->setParameter('ids', $mediaIds);
        }

        /** @var list<array{id: int|string, seoFilename: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['id']][] = $row['seoFilename'];
        }

        return $grouped;
    }

    /**
     * Loads the current file version entity of a media (needed by the decode-once strategy).
     */
    private function loadCurrentFileVersion(int $mediaId): ?FileVersion
    {
        $fileVersion = $this->entityManager->createQueryBuilder()
            ->select('fv')
            ->from(FileVersion::class, 'fv')
            ->join('fv.file', 'f')
            ->where('IDENTITY(f.media) = :id')
            ->andWhere('fv.version = f.version')
            ->setParameter('id', $mediaId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $fileVersion instanceof FileVersion ? $fileVersion : null;
    }

    /**
     * @return list<string>
     */
    private function supportedExtensions(string $mimeType): array
    {
        // The FormatManager validates the requested extension against the converter's
        // supported list; mirror that here so we never request an unsupported format.
        return \str_starts_with($mimeType, 'image/')
            ? ['jpg', 'gif', 'png', 'webp', 'avif']
            : [];
    }
}
