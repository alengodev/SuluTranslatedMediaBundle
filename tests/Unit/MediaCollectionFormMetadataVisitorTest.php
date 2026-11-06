<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Admin\FormMetadata\MediaCollectionFormMetadataVisitor;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;

/**
 * The bundle's "media_collection" section is merged into the form root; the visitor moves it inside
 * Sulu's "media_details" section. It must land directly after the "taxonomies" section so the
 * Collection block renders below Taxonomies (Categories/Tags) in the media details form.
 */
final class MediaCollectionFormMetadataVisitorTest extends TestCase
{
    public function testCollectionSectionIsPlacedAfterTaxonomies(): void
    {
        $mediaDetails = new SectionMetadata('media_details');
        $mediaDetails->addItem(new SectionMetadata('license'));
        $mediaDetails->addItem(new SectionMetadata('taxonomies'));

        $form = new FormMetadata();
        $form->setKey('media_details');
        $form->addItem($mediaDetails);
        $form->addItem(new SectionMetadata('media_collection'));

        (new MediaCollectionFormMetadataVisitor())->visitFormMetadata($form, 'en');

        $items = $form->getItems();

        // Moved out of the form root …
        self::assertArrayNotHasKey('media_collection', $items);

        // … and placed last inside media_details, right after taxonomies.
        self::assertInstanceOf(SectionMetadata::class, $items['media_details']);
        self::assertSame(
            ['license', 'taxonomies', 'media_collection'],
            \array_keys($items['media_details']->getItems()),
        );
    }

    public function testNonMediaDetailsFormIsLeftUntouched(): void
    {
        $form = new FormMetadata();
        $form->setKey('something_else');
        $form->addItem(new SectionMetadata('media_collection'));

        (new MediaCollectionFormMetadataVisitor())->visitFormMetadata($form, 'en');

        self::assertArrayHasKey('media_collection', $form->getItems());
    }
}
