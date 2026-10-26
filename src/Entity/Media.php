<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Entity;

use Alengo\SuluTranslatedMediaBundle\Model\AdditionalDataAwareInterface;
use Alengo\SuluTranslatedMediaBundle\Model\AdditionalDataTrait;
use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsAwareInterface;
use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsTrait;
use Doctrine\ORM\Mapping as ORM;
use Sulu\Bundle\MediaBundle\Entity\Media as SuluMedia;

/**
 * Sulu maps its base Media as a Doctrine mapped-superclass, so this concrete entity
 * inherits all parent field/association mappings automatically.
 *
 * The {@see AdditionalDataTrait} column on this entity holds the unlocalized
 * additional-data keys (per-media); localized keys live on {@see MediaTranslations}.
 */
#[ORM\Table(name: 'me_media')]
#[ORM\Entity]
class Media extends SuluMedia implements MediaTranslationsAwareInterface, AdditionalDataAwareInterface
{
    use MediaTranslationsTrait;
    use AdditionalDataTrait;

    public function __construct()
    {
        parent::__construct();
        $this->initMediaTranslations();
    }
}
