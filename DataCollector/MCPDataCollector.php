<?php

namespace Killerwolf\MCPProfilerBundle\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

/**
 * MCPDataCollector collects basic information about the MCP Profiler Bundle.
 */
class MCPDataCollector extends DataCollector implements LateDataCollectorInterface
{
    private string $version;
    private string $name;

    public function __construct(string $name = 'MCP Profiler', string $version = '1.0.0')
    {
        $this->name = $name;
        $this->version = $version;
    }

    /**
     * {@inheritdoc}
     */
    public function collect(Request $request, Response $response, \Throwable $exception = null): void
    {
        $this->data = [
            'name' => $this->name,
            'version' => $this->version,
            'request_count' => 1, // Basic counter for demonstration
            'timestamp' => time(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'mcp';
    }

    /**
     * Gets the bundle name.
     */
    public function getBundleName(): string
    {
        return $this->data['name'] ?? '';
    }

    /**
     * Gets the bundle version.
     */
    public function getBundleVersion(): string
    {
        return $this->data['version'] ?? '';
    }

    /**
     * Gets the request count.
     */
    public function getRequestCount(): int
    {
        return $this->data['request_count'] ?? 0;
    }

    /**
     * Gets the timestamp when data was collected.
     */
    public function getTimestamp(): int
    {
        return $this->data['timestamp'] ?? 0;
    }

    /**
     * {@inheritdoc}
     */
    public function lateCollect(): void
    {
        // This method is called after all data collectors have been initialized
        // You can use it to gather data that depends on other collectors
    }
}
