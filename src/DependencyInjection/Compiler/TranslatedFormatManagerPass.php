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
 * only the class is swapped, so no manual argument re-declaration is needed.
 */
class TranslatedFormatManagerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('sulu_media.format_manager')) {
            return;
        }

        $container->getDefinition('sulu_media.format_manager')
            ->setClass(TranslatedFormatManager::class);
    }
}
