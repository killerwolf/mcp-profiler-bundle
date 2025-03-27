# MCP Server Bundle for Symfony

This bundle Symfony MCP Profiler Bundle mimics the WebProfiler Bundle. It bridges the gap between Profiler data and your favorite (MCP enabled) AI-powerd IDE.  

## Installation

```bash
composer require killerwolf/mcp-profiler-bundle:dev-main james2037/mcp-php-server:@dev 
```

## Configuration

Add the bundle to your `config/bundles.php`:

```php
return [
    // ...
    Killerwolf\MCPProfilerBundle\MCPProfilerBundle::class => ['dev' => true],
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

@todo document available tools


## Commands

The bundle provides the following commands:

### Configure the MCP Server in your IDE (Cursor, Claude Code, Cline, etc.)

```json
{
  "mcpServers": {
    "symfony-mcp": {
      "command": "/Volumes/Work/git/symfony-demo/vendor/killerwolf/mcp-server-bundle/bin/run-mcp.sh",
      "env": {
        "BASE": "/Volumes/Work/git/symfony-demo"
      },
      "alwaysAllow": [
        "profiler_list",
        "profiler_get_all_collector_by_token",
        "profiler_get_one_collector_by_token"
      ]
    }
  }
}
```
PS: `command` is the absolut path to the `run-mcp.sh` script, and `BASE` is the environment variables providing the base path to your symfony project.

### Using the MCP Inspector

The MCP Inspector is a tool that allows you to interact with your MCP Server and test your tools and resources. You can use it with the following command:

```bash
npx --registry https://registry.npmjs.org @modelcontextprotocol/inspector
```

@add image of the Inspector with the example usage

### Interacting with the Symfony Profiler (for learning/debug puposes, not used by the MCP Server)

```bash
# List recent profiler entries
bin/console mcp:profiler list --limit=20

# Show details for a specific profile
bin/console mcp:profiler show <token>
bin/console mcp:profiler show <token> --collector=request

# Purge profiler data
bin/console mcp:profiler purge
```