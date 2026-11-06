<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\DependencyInjection\Compiler;

use Alengo\SuluTranslatedMediaBundle\Controller\TranslatedMediaStreamController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Replaces Sulu's MediaStreamController with TranslatedMediaStreamController so that
 * "Download master file" streams remote (S3/CDN) master files as an attachment instead
 * of redirecting to the storage URL. The website download routes reference the controller
 * by service id (sulu_media.media_stream_controller::downloadAction), so swapping the class
 * is enough — the existing definition and all its arguments are preserved.
 */
class TranslatedMediaStreamControllerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('sulu_media.media_stream_controller')) {
            return;
        }

        $container->getDefinition('sulu_media.media_stream_controller')
            ->setClass(TranslatedMediaStreamController::class);
    }
}
