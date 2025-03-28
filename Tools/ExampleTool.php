<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use PhpLlm\Mcp\Sdk\Contracts\ToolInterface;
use PhpLlm\Mcp\Sdk\Data\Parameter; // Assuming Parameter class for definition

class ExampleTool implements ToolInterface {

    // --- ToolInterface Methods ---

    public function getName(): string
    {
        return 'example';
    }

    public function getDescription(): string
    {
        return 'An example tool showing basic functionality';
    }

    public function getParameters(): array
    {
        return [
            new Parameter('input', Parameter::TYPE_STRING, 'Text to echo back', true), // Required
        ];
    }

    public function execute(array $arguments): string
    {
        $input = $arguments['input'] ?? null;
        if ($input === null) {
             return json_encode(['error' => 'Missing required parameter: input']);
        }
        return "You said: " . $input;
    }
}
