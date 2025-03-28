<?php

// Namespace updated back to Killerwolf\MCPProfilerBundle\Controller
namespace Killerwolf\MCPProfilerBundle\Controller;

use PhpLlm\McpSdk\Server\Transport\Sse\Store;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
// Remove Route attribute import, as routes will be defined in YAML
// use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;

#[AsController]
class MCPMessageController extends AbstractController
{
    public function __construct(
        private Store $store,
        private LoggerInterface $logger
    ) {}

    // Remove Route attribute
    public function receiveMessageAction(Request $request, string $connectionId): Response
    {
        try {
            $uuid = Uuid::fromString($connectionId);
            $messageBody = $request->getContent();

            if (empty($messageBody)) {
                $this->logger->warning('Received empty message body for connection', ['connectionId' => $connectionId]);
                return new Response('Empty message body received.', Response::HTTP_BAD_REQUEST);
            }

            $this->logger->info('Pushing message to store for connection', [
                'connectionId' => $connectionId,
                'message' => $messageBody
            ]);

            $this->store->push($uuid, $messageBody);

            return new Response(null, Response::HTTP_ACCEPTED);

        } catch (\InvalidArgumentException $e) {
            $this->logger->error('Invalid connection ID format', ['connectionId' => $connectionId, 'error' => $e->getMessage()]);
            return new Response('Invalid connection ID format.', Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Error processing MCP message', ['connectionId' => $connectionId, 'error' => $e->getMessage()]);
            return new Response('Internal server error.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}