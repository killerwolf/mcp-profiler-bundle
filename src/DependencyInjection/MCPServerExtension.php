<?php

namespace MCP\ServerBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class MCPServerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('mcp_server.name', $config['name']);
        $container->setParameter('mcp_server.version', $config['version']);
        $container->setParameter('mcp_server.tools_directory', $config['tools_directory']);
        $container->setParameter('mcp_server.resources_directory', $config['resources_directory']);
    }
}