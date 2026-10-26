<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Model;

use Alengo\SuluTranslatedMediaBundle\Entity\MediaTranslations;
use Doctrine\Common\Collections\Collection;

interface MediaTranslationsAwareInterface
{
    /**
     * @return Collection<int, MediaTranslations>
     */
    public function getMediaTranslations(): Collection;

    /**
     * Creates or updates the translation for the given locale with the dedicated
     * seoFilename and the flexible localized additional-data map.
     *
     * @param array<string, mixed> $additionalData
     */
    public function setMediaTranslation(?string $seoFilename, array $additionalData, ?string $locale): void;
}
