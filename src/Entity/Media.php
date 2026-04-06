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
 * ORM 3.x: Parent associations are added via SuluEntityMetadataSubscriber.
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
