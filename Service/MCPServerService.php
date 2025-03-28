<?php

namespace Killerwolf\MCPProfilerBundle\Service;

use PhpLlm\Mcp\Sdk\Server;
use PhpLlm\Mcp\Sdk\Transport\StdioTransport;
use PhpLlm\Mcp\Sdk\Contracts\ToolInterface; // Assuming interface name
// Removed: use MCP\Server\Tool\ToolRegistry;
use PhpLlm\Mcp\Sdk\Contracts\ResourceInterface; // Assuming interface name
// Removed: use MCP\Server\Resource\ResourceRegistry;
// Removed: use MCP\Server\Capability\ToolsCapability;
// Removed: use MCP\Server\Capability\ResourcesCapability;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class MCPServerService
{
    private ?Server $server = null;
    private ParameterBagInterface $params;
    // private ToolRegistry $toolRegistry; // Removed
    // private ResourceRegistry $resourceRegistry; // Removed
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
     * @param ToolInterface[] $tools Array of tool service instances
     */
    public function setTools(array $tools)
    {
        $this->registeredTools = $tools;
        return $this;
    }

    /**
     * Set resources from tagged services
     * 
     * @param ResourceInterface[] $resources Array of resource service instances
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
        // Register tools from tagged services
        foreach ($this->registeredTools as $tool) {
            // Assuming the new Server class has an addTool method
            if ($this->server && $tool instanceof ToolInterface) {
                $this->server->addTool($tool);
            }
        }
        return $this;
    }

    /**
     * Register resources from tagged services
     */
    public function registerResources()
    {
        // Register resources from tagged services
        foreach ($this->registeredResources as $resource) {
            // Assuming the new Server class has an addResource method
            if ($this->server && $resource instanceof ResourceInterface) {
                $this->server->addResource($resource);
            }
        }
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