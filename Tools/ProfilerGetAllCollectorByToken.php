<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

// Remove ToolInterface use
// Remove Parameter use
use PhpLlm\LlmChain\Chain\ToolBox\Attribute\AsTool; // Add AsTool attribute
use Symfony\Component\HttpKernel\Profiler\Profiler;

#[AsTool(
    name: 'profiler_get_all_collector_by_token',
    description: 'List all available profiler collectors for a given token',
    method: 'execute' // Point to the execute method
)]
class ProfilerGetAllCollectorByToken { // Remove implements ToolInterface
    private ?Profiler $profiler = null;

    // Inject the Profiler service
    public function __construct(Profiler $profiler, ?array $config = null)
    {
        $this->profiler = $profiler;
    }

    // Remove getName, getDescription, getParameters methods

    // Add type hint for the token parameter
    public function execute(string $token): string
    {
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