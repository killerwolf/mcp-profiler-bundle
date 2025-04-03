<?php

namespace Killerwolf\MCPProfilerBundle\Command;

use Killerwolf\MCPProfilerBundle\Tools\ProfilerGetAllCollectorByToken;
use Killerwolf\MCPProfilerBundle\Tools\ProfilerGetByTokenTool;
use Killerwolf\MCPProfilerBundle\Tools\ProfilerGetOneCollectorByToken;
use Killerwolf\MCPProfilerBundle\Tools\ProfilerList;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Profiler\Profiler;

#[AsCommand(
    name: 'mcp:server:run',
    description: 'Starts MCP Profiler service',
)]
class RunMCPServerCommand extends Command
{
    private const APP_VERSION = '1.0.0';
    private string $cacheDir;
    private string $environment;
    private ParameterBagInterface $parameterBag;

    // Inject dependencies needed by the tools
    public function __construct(string $cacheDir, string $environment, ParameterBagInterface $parameterBag)
    {
        parent::__construct();
        $this->cacheDir = $cacheDir;
        $this->environment = $environment;
        $this->parameterBag = $parameterBag;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $buffer = '';

        while (true) {
            $line = fgets(STDIN);
            if (false === $line) {
                usleep(1000);
                continue;
            }
            $buffer .= $line;
            if (str_contains($buffer, "\n")) {
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);
                foreach ($lines as $line) {
                    if (empty(trim($line))) {
                        continue;
                    } // Skip empty lines
                    $this->processLine($output, $line);
                }
            }
        }

        // This is unreachable due to the infinite loop, but kept for structure
        // @codeCoverageIgnoreStart
        return Command::SUCCESS;
        // @codeCoverageIgnoreEnd
    }

    private function processLine(OutputInterface $output, string $line): void
    {
        try {
            $payload = json_decode($line, true, JSON_THROW_ON_ERROR);
            $method = $payload['method'] ?? null;

            $response = match ($method) {
                'initialize' => $this->sendInitialize(),
                'tools/list' => $this->sendToolsList(),
                'tools/call' => $this->callTool($payload['params'] ?? []), // Ensure params exist
                'notifications/initialized' => null,
                default => $this->sendProtocolError(\sprintf('Method "%s" not found', $method)),
            };
        } catch (\Throwable $e) {
            $response = $this->sendApplicationError($e);
        }

        if (!$response) {
            return;
        }

        $response['id'] = $payload['id'] ?? 0;
        $response['jsonrpc'] = '2.0';

        // Use JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE for cleaner output
        $output->writeln(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function sendInitialize(): array
    {
        return [
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => [
                        'listChanged' => true, // Keep true as list is static but client might expect it
                    ],
                ],
                'serverInfo' => [
                    'name' => 'Symfony MCP Profiler Bundle (Static)', // Updated name
                    'version' => self::APP_VERSION,
                ],
            ],
        ];
    }

    // Hardcoded tool list based on original services.yaml
    private function sendToolsList(): array
    {
        $schemaBase = ['type' => 'object', '$schema' => 'http://json-schema.org/draft-07/schema#'];
        $tokenInput = [
            'properties' => ['token' => ['type' => 'string']],
            'required' => ['token'],
        ];
        $tokenCollectorInput = [
            'properties' => ['token' => ['type' => 'string'], 'collector' => ['type' => 'string']],
            'required' => ['token', 'collector'],
        ];

        return [
            'result' => [
                'tools' => [
                    [
                        'name' => 'profiler:list',
                        'description' => 'Lists available profiler tokens.',
                        'inputSchema' => array_merge($schemaBase, ['properties' => ['limit' => ['type' => 'integer', 'default' => 10]]]),
                    ],
                    [
                        'name' => 'profiler:get_collectors',
                        'description' => 'Gets all collector data for a specific profiler token.',
                        'inputSchema' => array_merge($schemaBase, $tokenInput),
                    ],
                    [
                        'name' => 'profiler:get_collector',
                        'description' => 'Gets specific collector data for a specific profiler token.',
                        'inputSchema' => array_merge($schemaBase, $tokenCollectorInput),
                    ],
                    [
                        'name' => 'profiler:get_by_token',
                        'description' => 'Gets basic profile data for a specific token.',
                        'inputSchema' => array_merge($schemaBase, $tokenInput),
                    ]
                ],
            ],
        ];
    }

    // Manually handle tool calls
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        // Derive base cache directory and environment name
        $envName = $this->environment; // Keep environment name

        try {
            $result = match ($name) {
                'profiler:list' => (new ProfilerList($this->cacheDir, $envName, $this->parameterBag))->execute(
                    $arguments['limit'] ?? 10
                ),
                'profiler:get_collectors' => (new ProfilerGetAllCollectorByToken($this->cacheDir, $envName))->execute(
                    $arguments['token'] ?? ''
                ),
                'profiler:get_collector' => (new ProfilerGetOneCollectorByToken($this->cacheDir, $envName))->execute(
                    $arguments['token'] ?? '',
                    $arguments['collector'] ?? ''
                ),
                'profiler:get_by_token' => (new ProfilerGetByTokenTool($this->cacheDir, $envName, $this->parameterBag))->execute(
                    $arguments['token'] ?? ''
                ),
                default => null, // Will be handled below
            };

            if ($result === null && $name !== null) {
                return $this->sendProtocolError(\sprintf('Tool "%s" not found', $name));
            }

            // Format successful result
            return [
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return $this->sendApplicationError($e);
        }
    }

    private function sendProtocolError(string $message): array
    {
        return [
            'error' => [
                'code' => -32601, // Method not found / Invalid Request
                'message' => $message,
            ],
        ];
    }

    private function sendApplicationError(\Throwable $e): array
    {
        // Use a generic error code for application errors
        return [
            'error' => [
                'code' => -32000, // Server error
                'message' => 'Application error: ' . $e->getMessage(),
                // Optionally include more details in 'data' if needed for debugging
                // 'data' => ['trace' => $e->getTraceAsString()],
            ],
        ];
    }
}
