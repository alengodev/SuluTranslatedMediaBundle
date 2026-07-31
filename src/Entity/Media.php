<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Entity;

use Alengo\SuluTranslatedMediaBundle\Model\MediaAdditionalDataInterface;
use Alengo\SuluTranslatedMediaBundle\Model\MediaAdditionalDataTrait;
use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsAwareInterface;
use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsTrait;
use Doctrine\ORM\Mapping as ORM;
use Sulu\Bundle\MediaBundle\Entity\Media as SuluMedia;

/**
 * Sulu maps its base Media as a Doctrine mapped-superclass, so this concrete entity
 * inherits all parent field/association mappings automatically.
 */
#[ORM\Table(name: 'me_media')]
#[ORM\Entity]
class Media extends SuluMedia implements MediaTranslationsAwareInterface, MediaAdditionalDataInterface
{
    use MediaTranslationsTrait;
    use MediaAdditionalDataTrait;

    public function __construct()
    {
        parent::__construct();
        $this->initMediaTranslations();
    }
}
