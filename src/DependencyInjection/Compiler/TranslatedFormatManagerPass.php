<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\DependencyInjection\Compiler;

use Alengo\SuluTranslatedMediaBundle\Media\FormatManager\TranslatedFormatManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Replaces Sulu's default FormatManager with TranslatedFormatManager.
 *
 * The existing service definition and all its arguments are preserved —
 * only the class is swapped and the progressive-format arguments are appended.
 */
class TranslatedFormatManagerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('sulu_media.format_manager')) {
            return;
        }

        $definition = $container->getDefinition('sulu_media.format_manager')
            ->setClass(TranslatedFormatManager::class);

        // Progressive formats: chain + fallback max-age, plus the local format cache location so the manager
        // can check which renditions already exist.
        if ($container->hasParameter('alengo_translated_media.progressive_formats.chain')) {
            $definition
                ->setArgument('$progressiveChain', '%alengo_translated_media.progressive_formats.chain%')
                ->setArgument('$progressiveMaxAge', '%alengo_translated_media.progressive_formats.fallback_max_age%')
                ->setArgument('$formatCachePath', '%sulu_media.format_cache.path%')
                ->setArgument('$formatCacheSegments', '%sulu_media.format_cache.segments%');
        }
    }
}
