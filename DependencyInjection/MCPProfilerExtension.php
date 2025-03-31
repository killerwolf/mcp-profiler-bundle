<?php

namespace Killerwolf\MCPProfilerBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class MCPProfilerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        // Load services configuration if it exists
        try {
            $loader = new YamlFileLoader(
                $container,
                new FileLocator(__DIR__ . '/../Resources/config')
            );
            $loader->load('services.yaml');
        } catch (\Exception $e) {
            // Services file might not exist yet, which is fine
        }

        // Set default parameters directly if no configuration is provided
        if (empty($configs)) {
            // Use the default values from Configuration class
            $container->setParameter('mcp_profiler.name', 'Symfony MCP Profiler Bundle');
            $container->setParameter('mcp_profiler.version', '1.0.0');
            // Set default values for profiler configuration
            $container->setParameter('mcp_profiler.profiler.storage_path', '%kernel.cache_dir%/profiler');
            $container->setParameter('mcp_profiler.profiler.enabled', true);
        } else {
            // Process the provided configuration
            $configuration = new Configuration();
            $config = $this->processConfiguration($configuration, $configs);
            $container->setParameter('mcp_profiler.name', $config['name']);
            $container->setParameter('mcp_profiler.version', $config['version']);
            
            // Set profiler parameters if they exist in the config
            if (isset($config['profiler'])) {
                $container->setParameter('mcp_profiler.profiler.storage_path', $config['profiler']['storage_path'] ?? '%kernel.cache_dir%/profiler');
                $container->setParameter('mcp_profiler.profiler.enabled', $config['profiler']['enabled'] ?? true);
            } else {
                // Set default values if not provided
                $container->setParameter('mcp_profiler.profiler.storage_path', '%kernel.cache_dir%/profiler');
                $container->setParameter('mcp_profiler.profiler.enabled', true);
            }
        }
    }

    /**
     * Returns the extension alias
     */
    public function getAlias(): string
    {
        return 'killerwolf_mcp_profiler';
    }
}