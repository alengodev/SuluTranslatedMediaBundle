<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\DependencyInjection;

use Alengo\SuluTranslatedMediaBundle\Entity\Media;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('alengo_translated_media');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('media_class')
                    ->defaultValue(Media::class)
                    ->cannotBeEmpty()
                    ->info('The Media entity class — defaults to the bundle\'s built-in Media entity')
                ->end()
                ->scalarNode('form_key')
                    ->defaultValue('media_additional_data')
                    ->cannotBeEmpty()
                    ->info('The Sulu form that defines the additional-data fields')
                ->end()
                ->arrayNode('unlocalized_keys')
                    ->info('Form field keys stored once per media (me_media.additional_data)')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('localized_keys')
                    ->info('Form field keys stored per locale (me_media_translations.additional_data)')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('admin')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('resource_key')
                            ->defaultValue('media_additional_data')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('tab_title')
                            ->defaultValue('sulu_admin.app.additional_data')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('progressive_formats')
                    ->info('Generate modern formats step by step: each on-demand request produces only the cheapest missing rendition (original -> webp -> avif) and serves it briefly cached until the requested format exists')
                    ->canBeEnabled()
                    ->children()
                        ->arrayNode('chain')
                            ->info('Deferred output extensions, in generation order after the original format')
                            ->scalarPrototype()->end()
                            ->defaultValue(['webp', 'avif'])
                        ->end()
                        ->integerNode('fallback_max_age')
                            ->info('Cache-Control max-age (seconds) for intermediate renditions')
                            ->defaultValue(60)
                            ->min(0)
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
