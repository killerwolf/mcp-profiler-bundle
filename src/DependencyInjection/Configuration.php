<?php

namespace MCP\ServerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('mcp_server');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('name')->defaultValue('MCP Server')->end()
                ->scalarNode('version')->defaultValue('1.0.0')->end()
                ->scalarNode('tools_directory')->defaultValue('%kernel.project_dir%/tools')->end()
                ->scalarNode('resources_directory')->defaultValue('%kernel.project_dir%/resources')->end()
            ->end()
        ;

        return $treeBuilder;
    }
}