<?php

namespace MCP\ServerBundle;

use MCP\ServerBundle\DependencyInjection\Compiler\TaggedServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class MCPServerBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
        
        // Register the compiler pass to collect tagged services
        $container->addCompilerPass(new TaggedServicesPass());
    }
}