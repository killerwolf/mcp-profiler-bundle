<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use Symfony\Component\HttpKernel\Profiler\Profiler;

#[ToolAttribute('profiler_get_all_collector_by_token', 'List all available profiler collectors for a given token, each collector gives a perpective of what might have gone wrong')]
class ProfilerGetAllCollectorByToken extends Tool {
    private ?Profiler $profiler = null;

    // Inject the Profiler service
    public function __construct(Profiler $profiler, ?array $config = null)
    {
        parent::__construct($config ?? []);
        $this->profiler = $profiler;
    }

    protected function doExecute(
        #[ParameterAttribute('token', type: 'string', description: 'The profiler token')]
        array $arguments
    ): array {
        $token = $arguments['token'];

        if (!$this->profiler) {
             return $this->text('Profiler service not available.');
        }

        // Load the profile for the given token
        $profile = $this->profiler->loadProfile($token);

        if (!$profile) {
            return $this->text("No profile found for token: {$token}");
        }

        // Get collector names
        $collectorNames = array_keys($profile->getCollectors());

        return $this->text(json_encode($collectorNames, JSON_PRETTY_PRINT));
    }
}