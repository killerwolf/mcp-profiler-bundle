<?php

namespace Killerwolf\MCPProfilerBundle;

use Killerwolf\MCPProfilerBundle\DependencyInjection\Compiler\TaggedServicesPass;
use Killerwolf\MCPProfilerBundle\DependencyInjection\MCPProfilerExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class MCPProfilerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        
        // Register the compiler pass to collect tagged services
        $container->addCompilerPass(new TaggedServicesPass());
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