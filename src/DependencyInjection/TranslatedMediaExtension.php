<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\DependencyInjection;

use Alengo\SuluTranslatedMediaBundle\Admin\MediaAdmin;
use Alengo\SuluTranslatedMediaBundle\Controller\Admin\MediaAdditionalDataController;
use Alengo\SuluTranslatedMediaBundle\Twig\TranslatedMediaExtension as TwigExtension;
use Sulu\Bundle\MediaBundle\Media\FormatCache\FormatCacheInterface;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

class TranslatedMediaExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        // Resolve values from project config (fall back to defaults)
        $configs = $container->getExtensionConfig($this->getAlias());
        $mediaClass = \Alengo\SuluTranslatedMediaBundle\Entity\Media::class;
        $resourceKey = 'media_additional_data';
        foreach ($configs as $c) {
            if (isset($c['media_class'])) {
                $mediaClass = $c['media_class'];
            }
            if (isset($c['admin']['resource_key'])) {
                $resourceKey = $c['admin']['resource_key'];
            }
        }

        // Auto-configure Sulu to use the bundle's Media entity
        $container->prependExtensionConfig('sulu_media', [
            'objects' => ['media' => ['model' => $mediaClass]],
        ]);

        // Register bundle's forms directory (project's directory is added later and overrides)
        $container->prependExtensionConfig('sulu_admin', [
            'forms' => [
                'directories' => [\dirname(__DIR__) . '/Resources/config/forms'],
            ],
            'resources' => [
                $resourceKey => [
                    'routes' => [
                        'detail' => 'alengo_translated_media_get_media_additional_data',
                    ],
                ],
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('alengo_translated_media.media_class', $config['media_class']);
        $container->setParameter('alengo_translated_media.admin.form_key', $config['admin']['form_key']);
        $container->setParameter('alengo_translated_media.admin.resource_key', $config['admin']['resource_key']);
        $container->setParameter('alengo_translated_media.admin.tab_title', $config['admin']['tab_title']);

        // FormatCacheInterface autowiring alias
        $container->setAlias(FormatCacheInterface::class, new Alias('sulu_media.format_cache', true));

        // Twig extension
        $twigDef = new Definition(TwigExtension::class);
        $twigDef->addArgument(new Reference('doctrine.orm.entity_manager'));
        $twigDef->addArgument(new Reference('sulu_media.format_cache'));
        $twigDef->addArgument(new Reference('slugger'));
        $twigDef->addArgument($config['media_class']);
        $twigDef->addArgument(new Reference('sulu_http_cache.reference_store', ContainerBuilder::NULL_ON_INVALID_REFERENCE));
        $twigDef->addArgument(['webp' => 'image/webp']);
        $twigDef->addTag('twig.extension');
        $container->setDefinition(TwigExtension::class, $twigDef);

        // Admin tab
        $adminDef = new Definition(MediaAdmin::class);
        $adminDef->addArgument(new Reference('sulu_admin.view_builder_factory'));
        $adminDef->addArgument($config['admin']['form_key']);
        $adminDef->addArgument($config['admin']['resource_key']);
        $adminDef->addArgument($config['admin']['tab_title']);
        $adminDef->addTag('sulu.admin');
        $adminDef->addTag('sulu.context', ['context' => 'admin']);
        $container->setDefinition(MediaAdmin::class, $adminDef);

        // Controller
        $controllerDef = new Definition(MediaAdditionalDataController::class);
        $controllerDef->addArgument(new Reference('doctrine.orm.entity_manager'));
        $controllerDef->addArgument(new Reference('sulu_http_cache.cache_manager', ContainerBuilder::NULL_ON_INVALID_REFERENCE));
        $controllerDef->setPublic(true);
        $container->setDefinition(MediaAdditionalDataController::class, $controllerDef);
    }

    public function getAlias(): string
    {
        return 'alengo_translated_media';
    }
}
