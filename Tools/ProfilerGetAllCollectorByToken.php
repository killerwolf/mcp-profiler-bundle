<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;


class ProfilerGetAllCollectorByToken { // Remove implements ToolInterface
    private ?Profiler $profiler = null;

    // Inject the Profiler service
    public function __construct(?Profiler $profiler)
    {
        $this->profiler = $profiler;
    }

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