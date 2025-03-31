<?php

namespace Killerwolf\MCPProfilerBundle;

use Killerwolf\MCPProfilerBundle\DependencyInjection\MCPProfilerExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class MCPProfilerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Compiler pass removed as tools are now injected via !tagged_iterator in services.yaml
    }

    /**
     * Returns the bundle's container extension
     */
    public function getContainerExtension(): ?\Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new MCPProfilerExtension();
        }

        return $this->extension;
    }
}
