<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Controller\Admin;

use Alengo\SuluTranslatedMediaBundle\Model\MediaAdditionalDataInterface;
use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsAwareInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\HttpCacheBundle\Cache\CacheManagerInterface;
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Component\Security\SecuredControllerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MediaAdditionalDataController implements SecuredControllerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?CacheManagerInterface $cacheManager = null,
    ) {
    }

    public function getAction(Request $request, int $id): Response
    {
        $entity = $this->findMediaOrFail($id);

        return new JsonResponse($this->getDataForEntity($entity, $request->query->get('locale')));
    }

    public function putAction(Request $request, int $id): Response
    {
        $entity = $this->findMediaOrFail($id);
        $locale = $request->query->get('locale');

        $this->mapDataToEntity($request->toArray(), $entity, $locale);
        $this->entityManager->flush();

        $this->cacheManager?->invalidateReference('media', (string) $id);

        return new JsonResponse($this->getDataForEntity($entity, $locale));
    }

    /**
     * @return array<string, mixed>
     */
    private function getDataForEntity(MediaTranslationsAwareInterface $entity, ?string $locale): array
    {
        $translations = [];
        foreach ($entity->getMediaTranslations() as $translation) {
            $translations[$translation->getLocale()] = [
                'title' => $translation->getTitle(),
                'description' => $translation->getDescription(),
                'seoFilename' => $translation->getSeoFilename(),
            ];
        }

        $data = [
            'id' => $entity->getId(),
            'title' => $translations[$locale]['title'] ?? '',
            'description' => $translations[$locale]['description'] ?? '',
            'seoFilename' => $translations[$locale]['seoFilename'] ?? '',
        ];

        if ($entity instanceof MediaAdditionalDataInterface) {
            $data['verifyDownload'] = $entity->getVerifyDownload();
            $data['aiGenerated'] = $entity->isAiGenerated();
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapDataToEntity(array $data, MediaTranslationsAwareInterface $entity, ?string $locale): void
    {
        $entity->setMediaTranslation([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'seoFilename' => $data['seoFilename'] ?? null,
        ], $locale);

        if ($entity instanceof MediaAdditionalDataInterface) {
            $entity->setVerifyDownload($data['verifyDownload'] ?? null);
            $entity->setAiGenerated($data['aiGenerated'] ?? null);
        }
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
