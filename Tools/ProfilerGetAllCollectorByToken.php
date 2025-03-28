<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use PhpLlm\Mcp\Sdk\Contracts\ToolInterface;
use PhpLlm\Mcp\Sdk\Data\Parameter; // Assuming Parameter class for definition
use Symfony\Component\HttpKernel\Profiler\Profiler;

class ProfilerGetAllCollectorByToken implements ToolInterface {
    private ?Profiler $profiler = null;

    // Inject the Profiler service
    public function __construct(Profiler $profiler, ?array $config = null)
    {
        // parent::__construct($config ?? []); // Removed
        $this->profiler = $profiler;
    }

    // --- ToolInterface Methods ---

    public function getName(): string
    {
        return 'profiler_get_all_collector_by_token';
    }

    public function getDescription(): string
    {
        return 'List all available profiler collectors for a given token';
    }

    public function getParameters(): array
    {
        return [
            new Parameter('token', Parameter::TYPE_STRING, 'The profiler token', true),
        ];
    }

    public function execute(array $arguments): string
    {
        $token = $arguments['token'] ?? null;

        if (!$token) {
            return json_encode(['error' => 'Missing required parameter: token']);
        }

        if (!$this->profiler) {
             return json_encode(['error' => 'Profiler service not available.']);
        }

        // Load the profile for the given token
        try {
            $profile = $this->profiler->loadProfile($token);
        } catch (\Exception $e) {
             return json_encode(['error' => "Error loading profile for token {$token}: " . $e->getMessage()]);
        }

        if (!$profile) {
            return json_encode(['error' => "No profile found for token: {$token}"]);
        }

        // Get collector names
        try {
            $collectorNames = array_keys($profile->getCollectors());
            return json_encode($collectorNames, JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
             return json_encode(['error' => "Error getting collector names for token {$token}: " . $e->getMessage()]);
        }
    }
}