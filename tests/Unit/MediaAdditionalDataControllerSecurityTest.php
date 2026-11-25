<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Controller\Admin\MediaAdditionalDataController;
use Alengo\SuluTranslatedMediaBundle\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\CollectionType;
use Sulu\Bundle\MediaBundle\Entity\Media as SuluMedia;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Sulu's security listener only checks the "sulu.media.collections" context. Like Sulu's own MediaController, the
 * additional data (incl. SEO file name) must also respect the permissions of the media's collection and the system
 * collections context — otherwise an editor locked out of a collection could still read and change its media.
 */
final class MediaAdditionalDataControllerSecurityTest extends TestCase
{
    private const COLLECTION_ID = 7;

    public function testReadingNeedsViewOnTheCollection(): void
    {
        $checker = $this->createMock(SecurityCheckerInterface::class);
        $checker->expects(self::once())->method('checkPermission')
            ->with($this->collectionCondition('de'), PermissionTypes::VIEW)
            ->willReturn(true);

        $this->controller($this->media(), $checker)->getAction(Request::create('/x?locale=de'), 1);
    }

    public function testSavingNeedsEditOnTheCollection(): void
    {
        $checker = $this->createMock(SecurityCheckerInterface::class);
        $checker->expects(self::once())->method('checkPermission')
            ->with($this->collectionCondition('de'), PermissionTypes::EDIT)
            ->willReturn(true);

        $this->controller($this->media(), $checker)->putAction($this->putRequest(), 1);
    }

    public function testDeniedSaveChangesNothing(): void
    {
        $media = $this->media();
        $checker = $this->createStub(SecurityCheckerInterface::class);
        $checker->method('checkPermission')->willThrowException(new AccessDeniedException());
        $entityManager = $this->entityManager($media);
        $entityManager->expects(self::never())->method('flush');

        try {
            (new MediaAdditionalDataController($entityManager, ['verify_download'], ['title'], $checker))->putAction($this->putRequest(), 1);
            self::fail('Access must be denied.');
        } catch (AccessDeniedException) {
        }

        self::assertSame([], $media->getAdditionalData());
        self::assertCount(0, $media->getMediaTranslations());
    }

    public function testSystemCollectionsNeedTheirOwnPermission(): void
    {
        $checked = [];
        $checker = $this->createStub(SecurityCheckerInterface::class);
        $checker->method('checkPermission')->willReturnCallback(static function (mixed $subject, string $permission) use (&$checked): bool {
            $checked[] = [\is_string($subject) ? $subject : 'collection', $permission];

            return true;
        });

        $this->controller($this->media(SystemCollectionManagerInterface::COLLECTION_TYPE), $checker)->getAction(Request::create('/x?locale=de'), 1);

        self::assertSame([['sulu.media.system_collections', PermissionTypes::VIEW], ['collection', PermissionTypes::VIEW]], $checked);
    }

    private function collectionCondition(string $locale): SecurityCondition
    {
        return new SecurityCondition('sulu.media.collections', $locale, Collection::class, self::COLLECTION_ID);
    }

    private function putRequest(): Request
    {
        return Request::create('/x?locale=de', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: (string) \json_encode([
            'seoFilename' => 'seo-de',
            'title' => 'Titel',
            'verify_download' => true,
        ]));
    }

    private function media(string $collectionType = 'collection.default'): Media
    {
        $type = new CollectionType();
        $type->setKey($collectionType);
        $collection = new Collection();
        $collection->setType($type);
        (new \ReflectionProperty(Collection::class, 'id'))->setValue($collection, self::COLLECTION_ID);

        $media = new Media();
        (new \ReflectionProperty(SuluMedia::class, 'id'))->setValue($media, 1);
        $media->setCollection($collection);

        return $media;
    }

    private function controller(Media $media, SecurityCheckerInterface $checker): MediaAdditionalDataController
    {
        return new MediaAdditionalDataController($this->entityManager($media), ['verify_download'], ['title'], $checker);
    }

    private function entityManager(Media $media): EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($media);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return $entityManager;
    }
}
