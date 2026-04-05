<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Model;

use Alengo\SuluTranslatedMediaBundle\Entity\MediaTranslations;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

trait MediaTranslationsTrait
{
    /**
     * @var Collection<int, MediaTranslations>
     */
    #[ORM\OneToMany(targetEntity: MediaTranslations::class, mappedBy: 'media', cascade: ['persist', 'remove'])]
    private Collection $mediaTranslations;

    private function initMediaTranslations(): void
    {
        $this->mediaTranslations = new ArrayCollection();
    }

    /**
     * @return Collection<int, MediaTranslations>
     */
    public function getMediaTranslations(): Collection
    {
        return $this->mediaTranslations;
    }

    /**
     * @param array{title?: string|null, description?: string|null, seoFilename?: string|null}|null $data
     */
    public function setMediaTranslation(?array $data, ?string $locale): void
    {
        foreach ($this->mediaTranslations as $translation) {
            if ($translation->getLocale() === $locale) {
                $translation->setTitle($data['title'] ?? null);
                $translation->setDescription($data['description'] ?? null);
                $translation->setSeoFilename($data['seoFilename'] ?? null);

                return;
            }
        }

        $translation = new MediaTranslations();
        $translation->setLocale($locale);
        $translation->setTitle($data['title'] ?? null);
        $translation->setDescription($data['description'] ?? null);
        $translation->setSeoFilename($data['seoFilename'] ?? null);
        $translation->setMedia($this);

        $this->mediaTranslations->add($translation);
    }
}
