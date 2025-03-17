<?php

namespace MCP\ServerBundle\DependencyInjection;

use MCP\Server\Tool\Tool;
use MCP\Server\Resource\Resource;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Resource\Attribute\Resource as ResourceAttribute;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class MCPServerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../Resources/config')
        );
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('mcp_server.name', $config['name']);
        $container->setParameter('mcp_server.version', $config['version']);
        
        // Set profiler configuration parameters
        $container->setParameter('mcp_server.profiler.storage_path', $config['profiler']['storage_path']);
        $container->setParameter('mcp_server.profiler.enabled', $config['profiler']['enabled']);
        
        // Auto-configure tools and resources based on their attributes/base classes
        $container->registerForAutoconfiguration(Tool::class)
            ->addTag('mcp_server.tool');
            
        $container->registerForAutoconfiguration(Resource::class)
            ->addTag('mcp_server.resource');
            
        // Register attribute-based auto-configuration if PHP >= 8.0
        if (PHP_VERSION_ID >= 80000) {
            $container->registerAttributeForAutoconfiguration(
                ToolAttribute::class,
                function ($definition, $attribute) {
                    $definition->addTag('mcp_server.tool');
                }
            );
            
            $container->registerAttributeForAutoconfiguration(
                ResourceAttribute::class,
                function ($definition, $attribute) {
                    $definition->addTag('mcp_server.resource');
                }
            );
        }
    }
}