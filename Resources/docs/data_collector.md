# MCP Profiler Data Collector

This bundle includes a minimal data collector that integrates with Symfony's profiler system. The data collector provides basic information about the MCP Profiler Bundle.

**Contributions are welcome!** Visit our [GitHub repository](https://github.com/killerwolf/mcp-profiler-bundle) to contribute to this project.

## Features

- Displays the bundle name and version
- Shows a simple request counter
- Includes timestamp of when data was collected

## How it Works

The data collector is automatically registered with Symfony's profiler when the bundle is enabled. It collects basic information during each request and makes it available in the profiler interface.

## Extending the Data Collector

You can extend the data collector to include additional information by modifying the `MCPDataCollector` class. Here's how:

1. Add new properties to the `collect()` method to gather additional data
2. Create getter methods for the new properties
3. Update the template to display the new information

## Template Customization

The data collector template is located at:

```
Resources/views/data_collector/mcp.html.twig
```

You can customize this template to change how the collected data is displayed in the profiler.