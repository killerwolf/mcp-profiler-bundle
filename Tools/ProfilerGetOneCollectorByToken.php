<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use PhpLlm\Mcp\Sdk\Contracts\ToolInterface;
use PhpLlm\Mcp\Sdk\Data\Parameter; // Assuming Parameter class for definition
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\VarDumper\VarDumper;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class ProfilerGetOneCollectorByToken implements ToolInterface {
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
        return 'profiler_get_one_collector_by_token';
    }

    public function getDescription(): string
    {
        return 'Get data for a specific profiler collector by token';
    }

    public function getParameters(): array
    {
        return [
            new Parameter('token', Parameter::TYPE_STRING, 'The profiler token (e.g., 8b7fa2)', true),
            new Parameter('collector_name', Parameter::TYPE_STRING, 'The name of the collector (e.g., request, doctrine, logs)', true),
        ];
    }

    public function execute(array $arguments): string
    {
        $token = $arguments['token'] ?? null;
        $collectorName = $arguments['collector_name'] ?? null;

        if (!$token || !$collectorName) {
            return json_encode(['error' => 'Missing required parameter(s): token and/or collector_name']);
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

        // Retrieve collector data
        $data = null;
        $dumpedData = null;

        if (method_exists($collector, 'getData')) {
            try {
                $data = $collector->getData();
                // Attempt to JSON encode to check serializability
                json_encode($data);
            } catch (\Exception $e) {
                // If getData() exists but fails or returns unserializable data, try dumping
                $dumpedData = $this->dumpData($collector);
                $data = null; // Ensure data is null if encoding failed
            }
         } else {
             // Fallback: Try to represent the collector state if getData isn't available
             $dumpedData = $this->dumpData($collector);
         }

         // Return JSON if possible, otherwise return the dump
         if ($data !== null) {
             try {
                 return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
             } catch (\Exception $e) {
                 // JSON encoding failed, fallback to dump
                 $dumpedData = $this->dumpData($data); // Dump the data that failed to encode
                 return "Collector '{$collectorName}' data (JSON failed, dumped):\n" . $dumpedData;
             }
         } elseif ($dumpedData !== null) {
             return "Collector '{$collectorName}' data (dumped):\n" . $dumpedData;
         } else {
             // Should not happen if dumpData works, but as a safeguard
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
            // Configure dumper to return the output as a string
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