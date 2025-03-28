<?php

// Namespace updated back to Killerwolf\MCPProfilerBundle\Controller
namespace Killerwolf\MCPProfilerBundle\Controller;

use Killerwolf\MCPProfilerBundle\Service\MCPServerService;
use PhpLlm\McpSdk\Server\Transport\Sse\Store;
use PhpLlm\McpSdk\Server\Transport\Sse\StreamTransport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;

#[AsController]
class MCPSseController extends AbstractController
{
    public function __construct(
        private MCPServerService $mcpServerService,
        private Store $store,
        private UrlGeneratorInterface $router,
        private LoggerInterface $logger
    ) {}

    // Remove Route attribute
    public function streamAction(): StreamedResponse
    {
        $connectionId = Uuid::v4();
        $this->logger->info('New SSE connection requested', ['connectionId' => $connectionId->toRfc4122()]);

        $messageEndpoint = $this->router->generate(
            'mcp_profiler_message_receive', // Use the route name (will be defined in YAML)
            ['connectionId' => $connectionId->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $transport = new StreamTransport($messageEndpoint, $this->store, $connectionId);

        $response = new StreamedResponse(function () use ($transport, $connectionId) {
            try {
                $this->logger->info('Initializing MCP Server for SSE connection', ['connectionId' => $connectionId->toRfc4122()]);
                $this->mcpServerService->initialize();

                $server = $this->mcpServerService->getServer();
                if (!$server) {
                    throw new \RuntimeException('MCP Server could not be retrieved from service.');
                }
                $server->connect($transport);

                $this->logger->info('SSE connection closed by client or server', ['connectionId' => $connectionId->toRfc4122()]);

            } catch (\Throwable $e) {
                $this->logger->error('Error during SSE stream execution', [
                    'connectionId' => $connectionId->toRfc4122(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            } finally {
                 $this->store->remove($connectionId);
                 $this->logger->info('Cleaned up SSE store for connection', ['connectionId' => $connectionId->toRfc4122()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}