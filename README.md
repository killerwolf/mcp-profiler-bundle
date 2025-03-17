# MCP Server Bundle for Symfony

This bundle integrates MCP Server capabilities into your Symfony application, providing a seamless way to use AI tools and resources in your project.

## Installation

```bash
composer require killerwolf/mcp-server-bundle
```

## Configuration

Add the bundle to your `config/bundles.php`:

```php
return [
    // ...
    MCP\ServerBundle\MCPServerBundle::class => ['all' => true],
];
```

Configure the bundle in your Symfony configuration:

```yaml
# config/packages/mcp_server.yaml
mcp_server:
    name: 'Your MCP Server Name'
    version: '1.0.0'
    profiler:
        storage_path: '%kernel.cache_dir%/profiler'
        enabled: true
```

## Built-in Tools

The bundle comes with two pre-configured tools:

### Profile Tool

Access Symfony profiler data by token:

```php
// Example in your JavaScript client
const response = await mcp.tools.profiler({
  token: "your-profiler-token"
});
console.log(response);
```

### Example Tool

A simple echo tool that returns your input:

```php
// Example in your JavaScript client
const response = await mcp.tools.example({
  input: "Hello, MCP Server!"
});
console.log(response); // "You said: Hello, MCP Server!"
```

## Registering Custom Tools and Resources

### Using Service Tags

The bundle uses Symfony's service tagging system to discover and register tools and resources. This approach integrates with Symfony's Dependency Injection Container and allows for proper service injection.

#### Tools

1. Create your Tool class extending `MCP\Server\Tool\Tool`:

```php
<?php

namespace App\MCP\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;

#[ToolAttribute('my_tool', 'Description of my tool')]
class MyTool extends Tool
{
    protected function doExecute(
        #[ParameterAttribute('input', type: 'string', description: 'Input parameter')]
        array $arguments
    ): array {
        // Implement your tool logic here
        $result = "Processed: " . $arguments['input'];
        
        return $this->text($result);
    }
}
```

2. Register it as a service in your `services.yaml`:

```yaml
# config/services.yaml
services:
    App\MCP\Tool\MyTool:
        # The tool tag is automatically added due to auto-configuration
        # Or you can add it explicitly:
        tags:
            - { name: 'mcp_server.tool' }
```

#### Resources

1. Create your Resource class extending `MCP\Server\Resource\Resource`:

```php
<?php

namespace App\MCP\Resource;

use MCP\Server\Resource\Resource;
use MCP\Server\Resource\Attribute\Resource as ResourceAttribute;

#[ResourceAttribute('my_resource', 'Description of my resource')]
class MyResource extends Resource
{
    public function getFields(): array
    {
        return [
            'id' => 'string',
            'name' => 'string',
            'created_at' => 'datetime'
        ];
    }
    
    public function get(array $params = []): array
    {
        // Implement your resource retrieval logic
        return [
            ['id' => '1', 'name' => 'Resource 1', 'created_at' => '2023-01-01T00:00:00Z'],
            ['id' => '2', 'name' => 'Resource 2', 'created_at' => '2023-01-02T00:00:00Z']
        ];
    }
}
```

2. Register it as a service in your `services.yaml`:

```yaml
# config/services.yaml
services:
    App\MCP\Resource\MyResource:
        # The resource tag is automatically added due to auto-configuration
        # Or you can add it explicitly:
        tags:
            - { name: 'mcp_server.resource' }
```

### Auto-configuration

This bundle automatically configures services that:
- Extend `MCP\Server\Tool\Tool` with the `mcp_server.tool` tag
- Extend `MCP\Server\Resource\Resource` with the `mcp_server.resource` tag
- Use the `#[Tool]` or `#[Resource]` attributes (PHP 8.0+)

You don't need to manually add tags if you're using Symfony's auto-configuration feature.

## Injecting Dependencies

Because tools and resources are registered as services, you can inject other services into them:

```php
<?php

namespace App\MCP\Tool;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use Symfony\Component\HttpClient\HttpClientInterface;

#[ToolAttribute('api_fetch', 'Fetch data from an API')]
class ApiFetchTool extends Tool
{
    private HttpClientInterface $httpClient;
    
    public function __construct(HttpClientInterface $httpClient, array $config = [])
    {
        parent::__construct($config);
        $this->httpClient = $httpClient;
    }

    protected function doExecute(
        #[ParameterAttribute('url', type: 'string', description: 'URL to fetch')]
        array $arguments
    ): array {
        $response = $this->httpClient->request('GET', $arguments['url']);
        $data = $response->toArray();
        
        return $this->text(json_encode($data, JSON_PRETTY_PRINT));
    }
}
```

## Commands

The bundle provides the following commands:

### Running the MCP Server

```bash
bin/console mcp:server:run
```

### Interacting with the Symfony Profiler

```bash
# List recent profiler entries
bin/console mcp:profiler list --limit=20

# Show details for a specific profile
bin/console mcp:profiler show <token>
bin/console mcp:profiler show <token> --collector=request

# Purge profiler data
bin/console mcp:profiler purge
``` 