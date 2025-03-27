<?php

namespace Killerwolf\MCPProfilerBundle\Service;

use MCP\Server\Server;
use MCP\Server\Transport\StdioTransport;
use MCP\Server\Tool\Tool;
use MCP\Server\Tool\ToolRegistry;
use MCP\Server\Resource\Resource;
use MCP\Server\Resource\ResourceRegistry;
use MCP\Server\Capability\ToolsCapability;
use MCP\Server\Capability\ResourcesCapability;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class MCPServerService
{
    private ?Server $server = null;
    private ParameterBagInterface $params;
    private ToolRegistry $toolRegistry;
    private ResourceRegistry $resourceRegistry;
    private array $registeredTools = [];
    private array $registeredResources = [];

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    public function initialize(?array $config = null)
    {
        if ($config === null) {
            // Try to get config from Symfony parameters
            $config = [
                'name' => $this->params->get('mcp_profiler.name', 'MCP Server'),
                'version' => $this->params->get('mcp_profiler.version', '1.0.0'),
            ];
        }

        // Create server instance
        $this->server = new Server($config['name'], $config['version']);

        // Set up signal handling
        $this->setupSignalHandling();

        return $this;
    }

    /**
     * Set tools from tagged services
     * 
     * @param Tool[] $tools Array of tool service instances
     */
    public function setTools(array $tools)
    {
        $this->registeredTools = $tools;
        return $this;
    }

    /**
     * Set resources from tagged services
     * 
     * @param Resource[] $resources Array of resource service instances
     */
    public function setResources(array $resources)
    {
        $this->registeredResources = $resources;
        return $this;
    }

    /**
     * Register tools from tagged services
     */
    public function registerTools()
    {
        // Create tool registry
        $this->toolRegistry = new ToolRegistry();
        
        // Register tools from tagged services
        foreach ($this->registeredTools as $tool) {
            $this->toolRegistry->register($tool);
        }

        $toolsCapability = new ToolsCapability();
        foreach ($this->toolRegistry->getTools() as $tool) {
            $toolsCapability->addTool($tool);
        }
        $this->server->addCapability($toolsCapability);

        return $this;
    }

    /**
     * Register resources from tagged services
     */
    public function registerResources()
    {
        // Create resource registry
        $this->resourceRegistry = new ResourceRegistry();
        
        // Register resources from tagged services
        foreach ($this->registeredResources as $resource) {
            $this->resourceRegistry->register($resource);
        }

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