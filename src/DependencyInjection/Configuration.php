<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\DependencyInjection;

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
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('The Media entity class that implements MediaTranslationsAwareInterface')
                ->end()
                ->arrayNode('admin')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('form_key')
                            ->defaultValue('media_additional_data')
                            ->cannotBeEmpty()
                        ->end()
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
            ->end()
        ;

        return $treeBuilder;
    }
}
