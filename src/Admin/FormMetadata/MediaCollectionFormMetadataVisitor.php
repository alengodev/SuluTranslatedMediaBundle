<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Admin\FormMetadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataVisitorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;

/**
 * Moves the bundle's collection section (merged into the root of Sulu's
 * media_details form, see Resources/config/forms/media_details.xml) into
 * Sulu's own "media_details" section, right after its "license" section.
 *
 * An XML form merge can only append to the form root; placing a section inside
 * a core section needs this visitor. If the core structure ever changes, the
 * section simply stays where the merge put it.
 */
final class MediaCollectionFormMetadataVisitor implements FormMetadataVisitorInterface
{
    private const FORM_KEY = 'media_details';
    private const TARGET_SECTION = 'media_details';
    private const ANCHOR_ITEM = 'license';
    private const COLLECTION_SECTION = 'media_collection';

    public function visitFormMetadata(FormMetadata $formMetadata, string $locale, array $metadataOptions = []): void
    {
        if (self::FORM_KEY !== $formMetadata->getKey()) {
            return;
        }

        $items = $formMetadata->getItems();
        $collectionSection = $items[self::COLLECTION_SECTION] ?? null;
        $targetSection = $items[self::TARGET_SECTION] ?? null;

        if (!$collectionSection instanceof SectionMetadata || !$targetSection instanceof SectionMetadata) {
            return;
        }

        $targetItems = $targetSection->getItems();
        if (!\array_key_exists(self::ANCHOR_ITEM, $targetItems)) {
            return;
        }

        $newTargetSection = $this->copySection($targetSection);
        foreach ($targetItems as $item) {
            $newTargetSection->addItem($item);
            if (self::ANCHOR_ITEM === $item->getName()) {
                $newTargetSection->addItem($collectionSection);
            }
        }

        unset($items[self::COLLECTION_SECTION]);
        $items[self::TARGET_SECTION] = $newTargetSection;
        $formMetadata->setItems($items);
    }

    /**
     * SectionMetadata has no way to reorder or remove items: clone it (keeps labels,
     * conditions, colspan, … independent of the Sulu version) and empty its items.
     */
    private function copySection(SectionMetadata $section): SectionMetadata
    {
        $copy = clone $section;
        $resetItems = \Closure::bind(function (): void {
            $this->items = [];
        }, $copy, SectionMetadata::class);
        $resetItems();

        return $copy;
    }
}
