<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Entity;

use Alengo\SuluTranslatedMediaBundle\Model\AdditionalDataAwareInterface;
use Alengo\SuluTranslatedMediaBundle\Model\AdditionalDataTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;

/**
 * Per-locale media data. `seoFilename` stays a dedicated column (the URL builder and
 * cache warmer query it); all other localized fields live in the flexible
 * {@see AdditionalDataTrait} column, configured via the bundle's localized_keys.
 */
#[ORM\Table(name: 'me_media_translations')]
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'assignment_unique', columns: ['media_id', 'locale'])]
class MediaTranslations implements AdditionalDataAwareInterface
{
    use AdditionalDataTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MediaInterface::class, inversedBy: 'mediaTranslations')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?MediaInterface $media = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private ?string $locale = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $seoFilename = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMedia(): ?MediaInterface
    {
        return $this->media;
    }

    public function setMedia(?MediaInterface $media): void
    {
        $this->media = $media;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    public function getSeoFilename(): ?string
    {
        return $this->seoFilename;
    }

    public function setSeoFilename(?string $seoFilename): void
    {
        $this->seoFilename = $seoFilename;
    }
}
