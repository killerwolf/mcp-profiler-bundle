<?php

namespace App\Tools;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;

#[ToolAttribute('example', 'An example tool showing basic functionality')]
class ExampleTool extends Tool {
    protected function doExecute(
        #[ParameterAttribute('input', type: 'string', description: 'Text to echo back')]
        array $arguments
    ): array {
        return $this->text("You said: " . $arguments['input']);
    }
}
