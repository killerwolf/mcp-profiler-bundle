<?php

namespace Killerwolf\MCPProfilerBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class TaggedServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        // Find the MCPServerService and add all tagged tools
        if (!$container->has('Killerwolf\MCPProfilerBundle\Service\MCPServerService')) {
            return;
        }
        
        $serverDefinition = $container->findDefinition('Killerwolf\MCPProfilerBundle\Service\MCPServerService');
        
        // Find all services tagged as 'mcp_profiler.tool'
        $taggedTools = $container->findTaggedServiceIds('mcp_profiler.tool');
        $tools = [];
        foreach ($taggedTools as $id => $tags) {
            $tools[] = new Reference($id);
        }
        $serverDefinition->addMethodCall('setTools', [$tools]);
        
        // Find all services tagged as 'mcp_profiler.resource'
        $taggedResources = $container->findTaggedServiceIds('mcp_profiler.resource');
        $resources = [];
        foreach ($taggedResources as $id => $tags) {
            $resources[] = new Reference($id);
        }
        $serverDefinition->addMethodCall('setResources', [$resources]);
    }
}