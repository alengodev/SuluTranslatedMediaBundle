<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Model;

interface MediaAdditionalDataInterface
{
    public function getVerifyDownload(): ?bool;

    public function setVerifyDownload(?bool $verifyDownload): void;

    public function isAiGenerated(): ?bool;

    public function setAiGenerated(?bool $aiGenerated): void;
}
