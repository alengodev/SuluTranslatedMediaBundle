<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Command;

use Alengo\SuluTranslatedMediaBundle\Entity\MediaTranslations;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Media\FormatManager\FormatManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Pre-generates ("warms") the local Sulu media format cache (public/uploads/media)
 * for every image media, so the frontend never has to generate a thumbnail on the
 * first request.
 *
 * This command is aware of the translated media URL scheme: the {@see TranslatedFormatManager}
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
 */
#[AsCommand(
    name: 'alengo:translated-media:format-cache:warm',
    description: 'Generate the local media format cache (jpg/webp/avif, x1 & x2, translated filenames) for all image media',
)]
class WarmMediaFormatCacheCommand extends Command
{
    /**
     * @param array<string, array<string, mixed>> $registeredFormats the resolved Sulu image formats (key => definition), incl. @2x keys
     * @param class-string                         $mediaClass        the bundle's Media entity class
     */
    public function __construct(
        private readonly FormatManagerInterface $formatManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly array $registeredFormats,
        private readonly string $mediaClass,
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be generated without writing any file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // 1. Read base format keys from the YAML and expand each into [key, key@2x].
        $sourcePath = $this->projectDir . '/' . $input->getOption('source');
        if (!\file_exists($sourcePath)) {
            $io->error(\sprintf('Source file not found: %s', $sourcePath));

            return Command::FAILURE;
        }

        $parsed = Yaml::parseFile($sourcePath);
        /** @var list<string> $baseKeys */
        $baseKeys = \is_array($parsed) && \is_array($parsed['image_formats'] ?? null) ? $parsed['image_formats'] : [];
        if ([] === $baseKeys) {
            $io->warning('No image formats found in source file.');

            return Command::SUCCESS;
        }

        /** @var list<string> $formatKeys */
        $formatKeys = [];
        /** @var list<string> $skipped */
        $skipped = [];
        foreach ($baseKeys as $baseKey) {
            foreach ([$baseKey, $baseKey . '@2x'] as $key) {
                if (isset($this->registeredFormats[$key])) {
                    $formatKeys[] = $key;
                } else {
                    $skipped[] = $key;
                }
            }
        }

        if ([] !== $skipped) {
            $io->warning(\sprintf(
                'Skipping %d format key(s) not registered in config/image-formats.xml: %s',
                \count($skipped),
                \implode(', ', $skipped),
            ));
        }

        // 2. Normalise the requested output extensions (jpeg => jpg, dedup).
        $extensions = $this->parseExtensions((string) $input->getOption('extensions'));

        // 3. Collect the image media (current file version only) + their SEO filenames.
        $mediaIds = $this->parseMediaIds($input->getOption('media'));
        $mediaList = $this->fetchImageMedia($mediaIds);
        if ([] === $mediaList) {
            $io->warning('No image media found.');

            return Command::SUCCESS;
        }
        $seoFilenames = $this->fetchSeoFilenames($mediaIds);

        $io->section('Warming translated media format cache');
        $io->listing([
            \sprintf('Media:        %d', \count($mediaList)),
            \sprintf('Format keys:  %d (incl. @2x)', \count($formatKeys)),
            \sprintf('Extensions:   %s', \implode(', ', $extensions)),
            \sprintf('Mode:         %s', $dryRun ? 'DRY-RUN (no files written)' : 'write'),
        ]);

        $generated = 0;
        $failed = 0;
        $progressBar = $io->createProgressBar(\count($mediaList));
        $progressBar->start();

        foreach ($mediaList as $media) {
            $supported = $this->formatManagerSupportedExtensions($media['mimeType']);
            $targetExtensions = \array_values(\array_intersect($extensions, $supported));
            $baseNames = $this->buildBaseFilenames($media['name'], $seoFilenames[$media['id']] ?? []);

            foreach ($baseNames as $baseName) {
                foreach ($formatKeys as $formatKey) {
                    foreach ($targetExtensions as $extension) {
                        $fileName = $baseName . '.' . $extension;

                        if ($dryRun) {
                            ++$generated;

                            continue;
                        }

                        // returnImage() converts and (save_image is true by default) writes
                        // the file into the local format cache under the URL filename —
                        // exactly like the proxy controller does on a live first-request.
                        $response = $this->formatManager->returnImage(
                            $media['id'],
                            $formatKey,
                            $fileName,
                        );

                        if (200 === $response->getStatusCode()) {
                            ++$generated;
                        } else {
                            ++$failed;
                            $io->warning(\sprintf(
                                'Failed (HTTP %d): media #%d, format "%s", file "%s"',
                                $response->getStatusCode(),
                                $media['id'],
                                $formatKey,
                                $fileName,
                            ));
                        }
                    }
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        if ($dryRun) {
            $io->success(\sprintf('Dry-run: %d cache file(s) would be generated.', $generated));

            return Command::SUCCESS;
        }

        if ($failed > 0) {
            $io->warning(\sprintf('Generated %d cache file(s), %d failed.', $generated, $failed));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Generated %d cache file(s).', $generated));

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
     * @return string[]
     */
    private function formatManagerSupportedExtensions(string $mimeType): array
    {
        // The FormatManager validates the requested extension against the converter's
        // supported list; mirror that here so we never request an unsupported format.
        return \str_starts_with($mimeType, 'image/')
            ? ['jpg', 'gif', 'png', 'webp', 'avif']
            : [];
    }
}
