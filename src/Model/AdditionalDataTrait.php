<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait AdditionalDataTrait
{
    /**
     * Flexible, form-configured key→value data. Which keys land here is decided by
     * the bundle config (unlocalized_keys on the media, localized_keys on the translation).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $additionalData = null;

    /**
     * @return array<string, mixed>
     */
    public function getAdditionalData(): array
    {
        return $this->additionalData ?? [];
    }

    /**
     * @param array<string, mixed> $additionalData
     */
    public function setAdditionalData(array $additionalData): void
    {
        $this->additionalData = $additionalData;
    }
}
