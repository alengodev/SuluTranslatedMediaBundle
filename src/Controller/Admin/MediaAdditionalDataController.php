<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Controller\Admin;

use Alengo\SuluTranslatedMediaBundle\Model\AdditionalDataAwareInterface;
use Alengo\SuluTranslatedMediaBundle\Model\MediaTranslationsAwareInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\HttpCacheBundle\Cache\CacheManagerInterface;
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Sulu\Component\Security\SecuredControllerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Additional data tab of media. Sulu's security listener checks the "sulu.media.collections" context; like Sulu's own
 * MediaController this controller additionally checks the permissions of the media's collection (object permissions)
 * and, for system collections, the "sulu.media.system_collections" context.
 */
class MediaAdditionalDataController implements SecuredControllerInterface
{
    /**
     * @param list<string> $unlocalizedKeys form field keys stored once per media (me_media.additional_data)
     * @param list<string> $localizedKeys   form field keys stored per locale (me_media_translations.additional_data)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $unlocalizedKeys,
        private readonly array $localizedKeys,
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly ?CacheManagerInterface $cacheManager = null,
    ) {
    }

    public function getAction(Request $request, int $id): Response
    {
        $locale = $request->query->get('locale');
        $entity = $this->findMediaOrFail($id, $locale, PermissionTypes::VIEW);

        return new JsonResponse($this->getDataForEntity($entity, $locale));
    }

    public function putAction(Request $request, int $id): Response
    {
        $locale = $request->query->get('locale');
        $entity = $this->findMediaOrFail($id, $locale, PermissionTypes::EDIT);

        $this->mapDataToEntity($request->toArray(), $entity, $locale);
        $this->entityManager->flush();

        $this->cacheManager?->invalidateReference('media', (string) $id);

        return new JsonResponse($this->getDataForEntity($entity, $locale));
    }

    /**
     * @return array<string, mixed>
     */
    private function getDataForEntity(MediaInterface&MediaTranslationsAwareInterface&AdditionalDataAwareInterface $entity, ?string $locale): array
    {
        $seoFilename = '';
        $localized = [];
        foreach ($entity->getMediaTranslations() as $translation) {
            if ($translation->getLocale() === $locale) {
                $seoFilename = $translation->getSeoFilename() ?? '';
                $localized = $translation->getAdditionalData();

                break;
            }
        }

        $data = [
            'id' => $entity->getId(),
            'seoFilename' => $seoFilename,
        ];

        foreach ($this->localizedKeys as $key) {
            $data[$key] = $localized[$key] ?? null;
        }

        $unlocalized = $entity->getAdditionalData();
        foreach ($this->unlocalizedKeys as $key) {
            $data[$key] = $unlocalized[$key] ?? null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapDataToEntity(array $data, MediaInterface&MediaTranslationsAwareInterface&AdditionalDataAwareInterface $entity, ?string $locale): void
    {
        $localized = [];
        foreach ($this->localizedKeys as $key) {
            if (\array_key_exists($key, $data)) {
                $localized[$key] = $data[$key];
            }
        }

        $unlocalized = $entity->getAdditionalData();
        foreach ($this->unlocalizedKeys as $key) {
            if (\array_key_exists($key, $data)) {
                $unlocalized[$key] = $data[$key];
            }
        }
        $entity->setAdditionalData($unlocalized);

        $seoFilename = \is_string($data['seoFilename'] ?? null) ? $data['seoFilename'] : null;
        $entity->setMediaTranslation($seoFilename, $localized, $locale);
    }

    private function findMediaOrFail(int $id, ?string $locale, string $permission): MediaInterface&MediaTranslationsAwareInterface&AdditionalDataAwareInterface
    {
        $media = $this->entityManager->getRepository(MediaInterface::class)->find($id);

        if (!$media instanceof MediaTranslationsAwareInterface || !$media instanceof AdditionalDataAwareInterface) {
            throw new NotFoundHttpException(\sprintf('Media with id "%d" not found or does not support additional data.', $id));
        }

        $collection = $media->getCollection();
        if (SystemCollectionManagerInterface::COLLECTION_TYPE === $collection->getType()->getKey()) {
            $this->securityChecker->checkPermission('sulu.media.system_collections', PermissionTypes::VIEW);
        }

        $this->securityChecker->checkPermission(
            new SecurityCondition($this->getSecurityContext(), $locale, Collection::class, $collection->getId()),
            $permission,
        );

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
