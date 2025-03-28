<?php

namespace Killerwolf\MCPProfilerBundle\Service;

// SDK Components
use PhpLlm\McpSdk\Server;
use PhpLlm\McpSdk\Server\JsonRpcHandler;
use PhpLlm\McpSdk\Server\Transport; // Keep interface import
use PhpLlm\McpSdk\Server\Transport\Stdio\SymfonyConsoleTransport; // Correct path and class name
use PhpLlm\McpSdk\Message\Factory as MessageFactory; // Alias Factory
use PhpLlm\McpSdk\Server\RequestHandler\InitializeHandler;
use PhpLlm\McpSdk\Server\RequestHandler\PingHandler;
use PhpLlm\McpSdk\Server\RequestHandler\ToolCallHandler;
use PhpLlm\McpSdk\Server\RequestHandler\ToolListHandler;

// LLM Chain Components
use PhpLlm\LlmChain\Chain\ToolBox\ToolBox;
use PhpLlm\LlmChain\Chain\ToolBox\ToolBoxInterface;
use PhpLlm\LlmChain\Chain\ToolBox\ToolAnalyzer;

// Symfony & PSR Components
use Symfony\Component\Console\Input\InputInterface;   // Add InputInterface
use Symfony\Component\Console\Output\OutputInterface; // Add OutputInterface
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MCPServerService
{
    private ?Server $server = null;
    private ParameterBagInterface $params;
    private ToolBoxInterface $toolBox; // Use ToolBoxInterface
    private LoggerInterface $logger;
    private iterable $tools; // Store tools passed from DI

    // Inject ToolAnalyzer, iterable tools, params, and logger
    public function __construct(
        ToolAnalyzer $toolAnalyzer,
        iterable $tools, // Tools collected via tag
        ParameterBagInterface $params,
        ?LoggerInterface $logger = null
    ) {
        $this->params = $params;
        $this->logger = $logger ?? new NullLogger();
        $this->tools = $tools; // Store tools

        // Instantiate ToolBox here or ensure it's injected if preferred
        // For simplicity here, instantiate it directly
        $this->toolBox = new ToolBox($toolAnalyzer, $this->tools, $this->logger);
    }

    public function initialize(?array $config = null)
    {
        // Get server name/version from params (used by InitializeHandler)
        $serverName = $config['name'] ?? $this->params->get('mcp_profiler.name', 'MCP Server');
        $serverVersion = $config['version'] ?? $this->params->get('mcp_profiler.version', '1.0.0');

        // 1. Instantiate Handlers
        $requestHandlers = [
            new InitializeHandler($serverName, $serverVersion),
            new PingHandler(),
            new ToolCallHandler($this->toolBox), // Pass ToolBox
            new ToolListHandler($this->toolBox), // Pass ToolBox
        ];
        $notificationHandlers = []; // Add notification handlers if any

        // 2. Instantiate Message Factory
        $messageFactory = new MessageFactory();

        // 3. Instantiate JsonRpcHandler
        $jsonRpcHandler = new JsonRpcHandler(
            $messageFactory,
            $requestHandlers,
            $notificationHandlers,
            $this->logger
        );

        // 4. Instantiate Server
        // Note: The new Server constructor only takes JsonRpcHandler and Logger
        $this->server = new Server($jsonRpcHandler, $this->logger);

        // 5. Set up signal handling (remains the same)
        $this->setupSignalHandling();

        return $this;
    }

    // Remove setTools, setResources, registerTools, registerResources methods

    // Accept InputInterface and OutputInterface
    public function run(InputInterface $input, OutputInterface $output)
    {
        if (!$this->server) {
            throw new \RuntimeException('Server not initialized. Call initialize() first.');
        }

        // The connect method now contains the run loop
        // Pass input and output to the transport constructor
        $this->server->connect(new SymfonyConsoleTransport($input, $output));

        // The old run() call is removed as connect() handles the loop.
        // The blocking parameter might need reconsideration based on how connect behaves.

        return $this;
    }

    public function shutdown()
    {
        // The new Server doesn't have a shutdown method.
        // Signal handling should exit the process, which implicitly stops the server.
        // Commenting out the old call.
        // if ($this->server) {
        //     $this->server->shutdown();
        // }
        $this->logger->info('Shutdown requested (handled by process exit).');
    }

    private function setupSignalHandling()
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function() {
                $this->logger->info('SIGINT received, shutting down...');
                // No explicit server shutdown needed, just exit.
                exit(0);
            });
            pcntl_signal(SIGTERM, function() {
                 $this->logger->info('SIGTERM received, shutting down...');
                 exit(0);
             });
        }
        // Windows handling might need review if sapi_windows_set_ctrl_handler
        // relied on the old shutdown method. For now, keep it similar.
        elseif (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function() {
                 $this->logger->info('CTRL event received, shutting down...');
                 // Attempting graceful exit might be complex here without explicit shutdown
                 exit(0); // Or handle differently if needed
            });
        }
    }

    // getServer might be less useful now, or return null if not initialized
    public function getServer(): ?Server
    {
        return $this->server;
    }
}