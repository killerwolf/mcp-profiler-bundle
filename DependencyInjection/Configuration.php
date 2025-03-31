<?php

namespace Killerwolf\MCPProfilerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration class for the bundle.
 * Note: Configuration is optional as all parameters have default values.
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('killerwolf_mcp_profiler');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('name')->defaultValue('Symfony MCP Profiler Bundle')->end()
                ->scalarNode('version')->defaultValue('1.0.0')->end()
            ->end()
        ;

        return $treeBuilder;
    }
}