<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Controller\Admin;

use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsAwareInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\HttpCacheBundle\Cache\CacheManagerInterface;
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Component\Security\SecuredControllerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base controller for the media additional-data admin tab.
 *
 * Handles locale-aware title, description, and seoFilename via MediaTranslationsAwareInterface.
 * Extend this class and override getDataForEntity() / mapDataToEntity() to add project-specific fields.
 *
 * Example:
 *   protected function getDataForEntity(MediaTranslationsAwareInterface $entity, ?string $locale): array
 *   {
 *       return array_merge(parent::getDataForEntity($entity, $locale), [
 *           'myField' => $entity->getMyField(),
 *       ]);
 *   }
 */
abstract class AbstractMediaAdditionalDataController extends AbstractController implements SecuredControllerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'sulu_http_cache.cache_manager')]
        protected readonly ?CacheManagerInterface $cacheManager = null,
    ) {
    }

    protected function handleGet(Request $request, int $id): Response
    {
        $locale = $request->query->get('locale');
        $entity = $this->findMediaOrFail($id);

        return $this->json($this->getDataForEntity($entity, $locale));
    }

    protected function handlePut(Request $request, int $id): Response
    {
        $locale = $request->query->get('locale');
        $entity = $this->findMediaOrFail($id);

        $this->mapDataToEntity($request->toArray(), $entity, $locale);
        $this->entityManager->flush();

        $this->cacheManager?->invalidateReference('media', (string) $id);

        return $this->json($this->getDataForEntity($entity, $locale));
    }

    /**
     * Returns the data array for the admin form. Override to add project-specific fields.
     *
     * @return array<string, mixed>
     */
    protected function getDataForEntity(MediaTranslationsAwareInterface $entity, ?string $locale): array
    {
        $translations = [];
        foreach ($entity->getMediaTranslations() as $translation) {
            $translations[$translation->getLocale()] = [
                'title' => $translation->getTitle(),
                'description' => $translation->getDescription(),
                'seoFilename' => $translation->getSeoFilename(),
            ];
        }

        return [
            'id' => $entity->getId(),
            'title' => $translations[$locale]['title'] ?? '',
            'description' => $translations[$locale]['description'] ?? '',
            'seoFilename' => $translations[$locale]['seoFilename'] ?? '',
        ];
    }

    /**
     * Maps the request data onto the entity. Override to handle project-specific fields.
     *
     * @param array<string, mixed> $data
     */
    protected function mapDataToEntity(array $data, MediaTranslationsAwareInterface $entity, ?string $locale): void
    {
        $entity->setMediaTranslation([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'seoFilename' => $data['seoFilename'] ?? null,
        ], $locale);
    }

    private function findMediaOrFail(int $id): MediaTranslationsAwareInterface
    {
        $media = $this->entityManager->getRepository(MediaInterface::class)->find($id);

        if (!$media instanceof MediaTranslationsAwareInterface) {
            throw new NotFoundHttpException(\sprintf('Media with id "%d" not found or does not support translations.', $id));
        }

        return $media;
    }

    public function getSecurityContext(): string
    {
        return MediaAdmin::SECURITY_CONTEXT;
    }

    public function getLocale(Request $request): ?string
    {
        return $request->query->get('locale');
    }
}
