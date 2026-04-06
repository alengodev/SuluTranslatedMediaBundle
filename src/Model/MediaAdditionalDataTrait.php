<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait MediaAdditionalDataTrait
{
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $verifyDownload = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $aiGenerated = null;

    public function getVerifyDownload(): ?bool
    {
        return $this->verifyDownload;
    }

    public function setVerifyDownload(?bool $verifyDownload): void
    {
        $this->verifyDownload = $verifyDownload;
    }

    public function isAiGenerated(): ?bool
    {
        return $this->aiGenerated;
    }

    public function setAiGenerated(?bool $aiGenerated): void
    {
        $this->aiGenerated = $aiGenerated;
    }
}
