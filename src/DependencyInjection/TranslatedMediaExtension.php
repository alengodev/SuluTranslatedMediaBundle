<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\DependencyInjection;

use Alengo\SuluTranslatedMediaBundle\Admin\MediaAdmin;
use Alengo\SuluTranslatedMediaBundle\Twig\TranslatedMediaExtension as TwigExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

class TranslatedMediaExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('alengo_translated_media.media_class', $config['media_class']);
        $container->setParameter('alengo_translated_media.admin.form_key', $config['admin']['form_key']);
        $container->setParameter('alengo_translated_media.admin.resource_key', $config['admin']['resource_key']);
        $container->setParameter('alengo_translated_media.admin.tab_title', $config['admin']['tab_title']);

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
    }

    public function getAlias(): string
    {
        return 'alengo_translated_media';
    }
}
