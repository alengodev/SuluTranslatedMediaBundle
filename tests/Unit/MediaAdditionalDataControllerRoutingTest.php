<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Controller\Admin\MediaAdditionalDataController;
use Alengo\SuluTranslatedMediaBundle\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\MediaBundle\Entity\Media as SuluMedia;
use Symfony\Component\HttpFoundation\Request;

final class MediaAdditionalDataControllerRoutingTest extends TestCase
{
    private const UNLOCALIZED = ['verify_download'];
    private const LOCALIZED = ['title', 'description'];

    public function testGetSplitsUnlocalizedAndLocalizedKeysIntoFlatPayload(): void
    {
        $media = $this->mediaWithId(1);
        $media->setAdditionalData(['verify_download' => true]);
        $media->setMediaTranslation('seo-de', ['title' => 'Titel', 'description' => 'Beschreibung'], 'de');

        $controller = $this->controller($media);
        $response = $controller->getAction(Request::create('/x?locale=de', 'GET'), 1);

        /** @var array<string, mixed> $data */
        $data = \json_decode((string) $response->getContent(), true);

        self::assertSame('seo-de', $data['seoFilename']);
        self::assertSame('Titel', $data['title']);
        self::assertSame('Beschreibung', $data['description']);
        self::assertTrue($data['verify_download']);
    }

    public function testPutRoutesLocalizedKeysToTranslationAndUnlocalizedToMedia(): void
    {
        $media = $this->mediaWithId(1);
        $controller = $this->controller($media);

        $request = Request::create(
            '/x?locale=de',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) \json_encode([
                'seoFilename' => 'seo-de',
                'title' => 'Titel',
                'description' => 'Beschreibung',
                'verify_download' => true,
            ]),
        );

        $controller->putAction($request, 1);

        self::assertSame(['verify_download' => true], $media->getAdditionalData());
        $translation = $media->getMediaTranslations()->first();
        self::assertNotFalse($translation);
        self::assertSame('seo-de', $translation->getSeoFilename());
        self::assertSame(['title' => 'Titel', 'description' => 'Beschreibung'], $translation->getAdditionalData());
    }

    private function mediaWithId(int $id): Media
    {
        $media = new Media();
        // Sulu's Media::$id is a non-nullable typed property hydrated by Doctrine;
        // set it directly so getId() works on this unmanaged test fixture.
        $property = new \ReflectionProperty(SuluMedia::class, 'id');
        $property->setValue($media, $id);

        return $media;
    }

    private function controller(Media $media): MediaAdditionalDataController
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($media);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new MediaAdditionalDataController($entityManager, self::UNLOCALIZED, self::LOCALIZED);
    }
}
