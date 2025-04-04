<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\VarDumper\VarDumper;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class ProfilerGetOneCollectorByToken
{
    private ?Profiler $profiler = null;

    // Inject the Profiler service
    public function __construct(Profiler $profiler)
    {
        $this->profiler = $profiler;
    }

    // Add type hints for parameters
    public function execute(string $token, string $collectorName): string
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

        // Check if the collector exists in this profile
        if (!$profile->hasCollector($collectorName)) {
            return json_encode(['error' => "Collector '{$collectorName}' not found for token: {$token}"]);
        }

        // Get the specific collector
        try {
            $collector = $profile->getCollector($collectorName);
        } catch (\Exception $e) {
            return json_encode(['error' => "Error getting collector '{$collectorName}': " . $e->getMessage()]);
        }

        // Retrieve collector data (Keep existing logic)
        $data = null;
        $dumpedData = null;

        if (method_exists($collector, 'getData')) {
            try {
                $data = $collector->getData();
                json_encode($data);
            } catch (\Exception $e) {
                $dumpedData = $this->dumpData($collector);
                $data = null;
            }
        } else {
            $dumpedData = $this->dumpData($collector);
        }

        if ($data !== null) {
            try {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (\Exception $e) {
                $dumpedData = $this->dumpData($data);
                return "Collector '{$collectorName}' data (JSON failed, dumped):\n" . $dumpedData;
            }
        } elseif ($dumpedData !== null) {
            return "Collector '{$collectorName}' data (dumped):\n" . $dumpedData;
        } else {
            return json_encode(['error' => "Could not retrieve or represent data for collector '{$collectorName}'."]);
        }
    }

    /**
     * Helper method to dump data using VarDumper
     */
    private function dumpData($variable): ?string
    {
        try {
            $cloner = new VarCloner();
            $dumper = new CliDumper();
            $output = fopen('php://memory', 'r+');
            $dumper->dump($cloner->cloneVar($variable), $output);
            rewind($output);
            $dump = stream_get_contents($output);
            fclose($output);
            return $dump;
        } catch (\Exception $e) {
            return "Error during data dumping: " . $e->getMessage();
        }
    }
}
