<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ProfilerList { // Remove implements ToolInterface
    private ?Profiler $profiler = null;
    private ?ParameterBagInterface $parameterBag = null;

    public function __construct(?Profiler $profiler, ?ParameterBagInterface $parameterBag = null)
    {
        $this->profiler = $profiler;
        $this->parameterBag = $parameterBag;
    }

    // Remove getName, getDescription, getParameters methods

    // Add type hints for parameters
    public function execute(
        ?int $limit = 20, // Add type hints and default values
        ?string $ip = null,
        ?string $url = null,
        ?string $method = null,
        ?int $statusCode = null
    ): string {
        if (!$this->profiler) {
            return json_encode(['error' => 'Profiler service not available.']);
       }
        try {
            // Use the profiler to find profiles matching the criteria
            $tokens = $this->profiler->find($ip, $url, $limit, $method, null, null, $statusCode);

            if (empty($tokens)) {
                return "No profiler entries found.";
            }

            // Format the results
            $results = [];
            foreach ($tokens as $token) {
                // Load the full profile to get complete data
                $profile = $this->profiler->loadProfile($token['token']);
                if (!$profile) {
                    continue;
                }

                $results[] = [
                    'token' => $profile->getToken(),
                    'ip' => $profile->getIp(),
                    'method' => $profile->getMethod(),
                    'url' => $profile->getUrl(),
                    'time' => date('Y-m-d H:i:s', $profile->getTime()),
                    'status_code' => $profile->getStatusCode()
                ];
            }

            if (empty($results)) {
                return "No profiler entries match the specified criteria.";
            }

            return json_encode($results, JSON_PRETTY_PRINT);

        } catch (\Exception $e) {
            return "Error retrieving profiler entries: " . $e->getMessage();
        }
    }


}