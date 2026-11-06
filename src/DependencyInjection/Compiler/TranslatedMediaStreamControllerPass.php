<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\DependencyInjection\Compiler;

use Alengo\SuluTranslatedMediaBundle\Controller\TranslatedMediaStreamController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replaces Sulu's MediaStreamController with TranslatedMediaStreamController so that
 * "Download master file" streams remote (S3/CDN) master files as an attachment instead
 * of redirecting to the storage URL. The website download routes reference the controller
 * by service id (sulu_media.media_stream_controller::downloadAction), so swapping the class
 * is enough — the existing definition and all its constructor arguments are preserved.
 *
 * The streaming scope (`master_file_download.stream_remote`) and the request stack are set via
 * setters, so Sulu's constructor signature stays untouched across the supported Sulu versions.
 * With "none" the controller is not replaced at all (Sulu default behaviour).
 */
class TranslatedMediaStreamControllerPass implements CompilerPassInterface
{
    private const PARAMETER = 'alengo_translated_media.master_file_download.stream_remote';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('sulu_media.media_stream_controller')) {
            return;
        }

        $streamRemote = $container->hasParameter(self::PARAMETER) ? $container->getParameter(self::PARAMETER) : null;
        if (!\is_string($streamRemote)) {
            $streamRemote = TranslatedMediaStreamController::STREAM_REMOTE_ADMIN;
        }

        if (TranslatedMediaStreamController::STREAM_REMOTE_NONE === $streamRemote) {
            return;
        }

        $container->getDefinition('sulu_media.media_stream_controller')
            ->setClass(TranslatedMediaStreamController::class)
            ->addMethodCall('setStreamRemote', [$streamRemote])
            ->addMethodCall('setRequestStack', [new Reference('request_stack')]);
    }
}
