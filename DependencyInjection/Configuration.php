<?php

namespace Resomedia\EntityHistoryBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('resomedia_entity_history');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode->children()
            ->scalarNode('user_property')
                ->defaultValue('username')
            ->end()
            ->scalarNode('class_history')
                ->isRequired()
            ->end()
            ->arrayNode('entity')
                ->useAttributeAsKey('id')
                ->prototype('array')
                    ->children()
                        ->arrayNode('fields')->prototype('scalar')->end()->end()
                        ->arrayNode('ignore_fields')->prototype('scalar')->end()->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
