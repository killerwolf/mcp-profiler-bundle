<?php

namespace Killerwolf\MCPProfilerBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mcp:server:run',
    description: 'Starts MCP Profiler service',
)]
class RunMCPServerCommand extends Command
{
    private const APP_VERSION = '1.0.0';
    private iterable $tools;

    public function __construct(iterable $tools)
    {
        parent::__construct();
        $this->tools = $tools;
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
                    $this->processLine($output, $line);
                }
            }
        }

        return Command::SUCCESS;
    }

    private function processLine(OutputInterface $output, string $line): void
    {
        try {
            $payload = json_decode($line, true, JSON_THROW_ON_ERROR);

            $method = $payload['method'] ?? null;

            $response = match ($method) {
                // protocols
                'initialize' => $this->sendInitialize(),
                'tools/list' => $this->sendToolsList(),
                'tools/call' => $this->callTool($payload['params']),
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

        $output->writeln(json_encode($response));
    }

    private function sendInitialize(): array
    {
        return [
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => [
                        'listChanged' => true,
                    ],
                ],
                'serverInfo' => [
                    'name' => 'Symfony MCP Profiler Bundle',
                    'version' => self::APP_VERSION,
                ],
            ],
        ];
    }

    private function sendToolsList(): array
    {
        $toolsData = [];
        
        foreach ($this->tools as $tool) {
            // Get tool metadata using reflection
            $reflectionClass = new \ReflectionClass($tool);
            $attributes = $reflectionClass->getAttributes();
            
            $toolName = null;
            $toolDescription = null;
            $inputSchema = null;
            
            // Look for tool attributes or method attributes
            foreach ($attributes as $attribute) {
                $attributeName = $attribute->getName();
                if (str_ends_with($attributeName, 'AsTool')) {
                    $attributeInstance = $attribute->newInstance();
                    $toolName = $attributeInstance->name ?? null;
                    $toolDescription = $attributeInstance->description ?? null;
                    break;
                }
            }
            
            // If no tool name found, skip this tool
            if (!$toolName) {
                continue;
            }
            
            // Build basic schema based on execute method parameters
            if (method_exists($tool, 'execute')) {
                $method = $reflectionClass->getMethod('execute');
                $parameters = $method->getParameters();
                
                $properties = [];
                $required = [];
                
                foreach ($parameters as $parameter) {
                    $paramName = $parameter->getName();
                    $properties[$paramName] = [];
                    
                    // Get parameter type
                    $type = $parameter->getType();
                    if ($type) {
                        $typeName = $type->getName();
                        switch ($typeName) {
                            case 'int':
                            case 'integer':
                                $properties[$paramName]['type'] = 'integer';
                                break;
                            case 'float':
                            case 'double':
                                $properties[$paramName]['type'] = 'number';
                                break;
                            case 'bool':
                            case 'boolean':
                                $properties[$paramName]['type'] = 'boolean';
                                break;
                            case 'array':
                                $properties[$paramName]['type'] = 'array';
                                break;
                            default:
                                $properties[$paramName]['type'] = 'string';
                        }
                    } else {
                        $properties[$paramName]['type'] = 'string';
                    }
                    
                    // Check if parameter has default value
                    if (!$parameter->isOptional()) {
                        $required[] = $paramName;
                    } else if ($parameter->isDefaultValueAvailable()) {
                        $properties[$paramName]['default'] = $parameter->getDefaultValue();
                    }
                }
                
                $inputSchema = [
                    'type' => 'object',
                    'properties' => $properties,
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                ];
                
                if (!empty($required)) {
                    $inputSchema['required'] = $required;
                }
            }
            
            $toolsData[] = [
                'name' => $toolName,
                'description' => $toolDescription ?: 'No description available',
                'inputSchema' => $inputSchema ?: [
                    'type' => 'object',
                    'properties' => [],
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                ],
            ];
        }
        
        return [
            'result' => [
                'tools' => $toolsData,
            ],
        ];
    }

    private function callTool(array $params): array
    {
        $name = $params['name'];
        $arguments = $params['arguments'] ?? [];
        
        // Find the tool with the matching name
        $targetTool = null;
        foreach ($this->tools as $tool) {
            $reflectionClass = new \ReflectionClass($tool);
            $attributes = $reflectionClass->getAttributes();
            
            foreach ($attributes as $attribute) {
                $attributeName = $attribute->getName();
                if (str_ends_with($attributeName, 'AsTool')) {
                    $attributeInstance = $attribute->newInstance();
                    if ($attributeInstance->name === $name) {
                        $targetTool = $tool;
                        break 2;
                    }
                }
            }
        }
        
        if (!$targetTool) {
            return $this->sendProtocolError(\sprintf('Tool "%s" not found', $name));
        }
        
        try {
            // Call the execute method with the provided arguments
            $result = $targetTool->execute(...array_values($arguments));
            
            return [
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT),
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
                'code' => -32601,
                'message' => $message,
            ],
        ];
    }

    private function sendApplicationError(\Throwable $e): array
    {
        return [
            'error' => [
                'code' => -32601,
                'message' => 'Something gone wrong! ' . $e->getMessage(),
            ],
        ];
    }
}