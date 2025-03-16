<?php

namespace MCP\ServerBundle\Service;

use MCP\Server\Server;
use MCP\Server\Transport\StdioTransport;
use MCP\Server\Tool\ToolRegistry;
use MCP\Server\Resource\ResourceRegistry;
use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Capability\ResourcesCapability;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class MCPServerService
{
    private ?Server $server;
    private ParameterBagInterface $params;
    private ToolRegistry $toolRegistry;
    private ResourceRegistry $resourceRegistry;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    public function initialize(array $config = null)
    {
        if ($config === null) {
            // Try to get config from Symfony parameters
            $config = [
                'name' => $this->params->get('mcp_server.name', 'MCP Server'),
                'version' => $this->params->get('mcp_server.version', '1.0.0'),
            ];
        }

        // Create server instance
        $this->server = new Server($config['name'], $config['version']);

        // Set up signal handling
        $this->setupSignalHandling();

        return $this;
    }

    public function registerTools(string $toolsDirectory = null, array $config = [])
    {
        $toolsDirectory = $toolsDirectory ?? $this->params->get('mcp_server.tools_directory');
        
        // Discover and register tools
        $this->toolRegistry = new ToolRegistry();
        $this->toolRegistry->discover($toolsDirectory, $config);

        $toolsCapability = new ToolsCapability();
        foreach ($this->toolRegistry->getTools() as $tool) {
            $toolsCapability->addTool($tool);
        }
        $this->server->addCapability($toolsCapability);

        return $this;
    }

    public function registerResources(string $resourcesDirectory = null, array $config = [])
    {
        $resourcesDirectory = $resourcesDirectory ?? $this->params->get('mcp_server.resources_directory');
        
        // Discover and register resources
        $this->resourceRegistry = new ResourceRegistry();
        $this->resourceRegistry->discover($resourcesDirectory, $config);

        $resourcesCapability = new ResourcesCapability();
        foreach ($this->resourceRegistry->getResources() as $resource) {
            $resourcesCapability->addResource($resource);
        }
        $this->server->addCapability($resourcesCapability);

        return $this;
    }

    public function run(bool $blocking = true)
    {
        if (!$this->server) {
            throw new \RuntimeException('Server not initialized. Call initialize() first.');
        }

        $this->server->connect(new StdioTransport());
        
        if ($blocking) {
            $this->server->run();
        } else {
            // Non-blocking mode for Symfony integration
            // You might need to implement a custom transport or event loop integration here
        }

        return $this;
    }

    public function shutdown()
    {
        if ($this->server) {
            $this->server->shutdown();
        }
    }

    private function setupSignalHandling()
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function() {
                $this->shutdown();
                exit(0);
            });
        } else if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function() {
                $this->shutdown();
            });
        }
    }

    public function getServer(): ?Server
    {
        return $this->server;
    }
}