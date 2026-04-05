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
     * @param array{title?: string|null, description?: string|null, seoFilename?: string|null}|null $data
     */
    public function setMediaTranslation(?array $data, ?string $locale): void;
}
