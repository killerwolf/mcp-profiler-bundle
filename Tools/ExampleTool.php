<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

// Remove ToolInterface use
// Remove Parameter use
use PhpLlm\LlmChain\Chain\ToolBox\Attribute\AsTool; // Add AsTool attribute

#[AsTool(
    name: 'example',
    description: 'An example tool showing basic functionality',
    method: 'execute' // Point to the execute method
)]
class ExampleTool { // Remove implements ToolInterface

    // Remove getName, getDescription, getParameters methods

    // Add type hint for the input parameter
    public function execute(string $input): string
    {
        return "You said: " . $input;
    }
}
