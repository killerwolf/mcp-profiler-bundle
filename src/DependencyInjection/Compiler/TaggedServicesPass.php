<?php

namespace MCP\ServerBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class TaggedServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        // Find the MCPServerService and add all tagged tools
        if (!$container->has('MCP\ServerBundle\Service\MCPServerService')) {
            return;
        }
        
        $serverDefinition = $container->findDefinition('MCP\ServerBundle\Service\MCPServerService');
        
        // Find all services tagged as 'mcp_server.tool'
        $taggedTools = $container->findTaggedServiceIds('mcp_server.tool');
        $tools = [];
        foreach ($taggedTools as $id => $tags) {
            $tools[] = new Reference($id);
        }
        $serverDefinition->addMethodCall('setTools', [$tools]);
        
        // Find all services tagged as 'mcp_server.resource'
        $taggedResources = $container->findTaggedServiceIds('mcp_server.resource');
        $resources = [];
        foreach ($taggedResources as $id => $tags) {
            $resources[] = new Reference($id);
        }
        $serverDefinition->addMethodCall('setResources', [$resources]);
    }
} 