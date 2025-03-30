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

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('mcp_profiler.name', $config['name']);
        $container->setParameter('mcp_profiler.version', $config['version']);

    }

    /**
     * Returns the extension alias
     */
    public function getAlias(): string
    {
        return 'killerwolf_mcp_profiler';
    }
}