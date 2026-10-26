<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Entity\Media;
use PHPUnit\Framework\TestCase;

final class AdditionalDataModelTest extends TestCase
{
    public function testAdditionalDataDefaultsToEmptyArray(): void
    {
        self::assertSame([], (new Media())->getAdditionalData());
    }

    public function testAdditionalDataIsStored(): void
    {
        $media = new Media();
        $media->setAdditionalData(['verify_download' => true]);

        self::assertSame(['verify_download' => true], $media->getAdditionalData());
    }

    public function testSetMediaTranslationCreatesTranslationWithSeoFilenameAndAdditionalData(): void
    {
        $media = new Media();
        $media->setMediaTranslation('seo-de', ['title' => 'Titel', 'description' => 'Beschreibung'], 'de');

        self::assertCount(1, $media->getMediaTranslations());
        $translation = $media->getMediaTranslations()->first();
        self::assertNotFalse($translation);
        self::assertSame('de', $translation->getLocale());
        self::assertSame('seo-de', $translation->getSeoFilename());
        self::assertSame(['title' => 'Titel', 'description' => 'Beschreibung'], $translation->getAdditionalData());
    }

    public function testSetMediaTranslationUpdatesExistingLocaleInsteadOfAdding(): void
    {
        $media = new Media();
        $media->setMediaTranslation('seo-de', ['title' => 'Alt'], 'de');
        $media->setMediaTranslation('seo-de-2', ['title' => 'Neu', 'description' => 'D'], 'de');

        self::assertCount(1, $media->getMediaTranslations());
        $translation = $media->getMediaTranslations()->first();
        self::assertNotFalse($translation);
        self::assertSame('seo-de-2', $translation->getSeoFilename());
        self::assertSame(['title' => 'Neu', 'description' => 'D'], $translation->getAdditionalData());
    }
}
