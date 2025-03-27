<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\VarDumper\VarDumper;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

#[ToolAttribute('profiler_get_one_collector_by_token', 'Get data for a specific profiler collector by token')]
class ProfilerGetOneCollectorByToken extends Tool {
    private ?Profiler $profiler = null;

    // Inject the Profiler service
    public function __construct(Profiler $profiler, ?array $config = null)
    {
        parent::__construct($config ?? []);
        $this->profiler = $profiler;
    }

    protected function doExecute(
        #[ParameterAttribute('token', type: 'string', description: 'The profiler token (e.g., 8b7fa2 ) ')]
        #[ParameterAttribute('collector_name', type: 'string', description: 'The name of the collector (e.g., request, doctrine, logs)')]
        array $arguments
    ): array {
        $token = $arguments['token'];
        $collectorName = $arguments['collector_name'];

        if (!$this->profiler) {
             return $this->text('Profiler service not available.');
        }
        
        // Load the profile for the given token
        $profile = $this->profiler->loadProfile($token);

        if (!$profile) {
            return $this->text("No profile found for token: {$token}");
        }

        // Check if the collector exists in this profile
        if (!$profile->hasCollector($collectorName)) {
             return $this->text("Collector '{$collectorName}' not found for token: {$token}");
        }

        // Get the specific collector
        $collector = $profile->getCollector($collectorName);

        // Retrieve collector data
        // Use similar logic as ProfilerGetByTokenTool to handle different data structures if needed
        // For now, use getData() if available, otherwise dump the object structure
        $data = null;
        if (method_exists($collector, 'getData')) {
            $data = $collector->getData();
         } else {
             // Fallback: Try to represent the collector state if getData isn't available
             $cloner = new VarCloner();
             $dumper = new CliDumper();
             $data = $dumper->dump($cloner->cloneVar($collector), true); // Dump as string
             // Return data as text if it was dumped as string
             return $this->text("Collector '{$collectorName}' data (dumped):\n" . $data);
         }

         // Handle potential serialization issues (e.g., closures, resources)
         try {
             // Attempt to return as JSON
             return $this->json($data);
         } catch (\Exception $e) {
             // Fallback to text representation if JSON encoding fails
             $cloner = new VarCloner();
             $dumper = new CliDumper();
             $textData = $dumper->dump($cloner->cloneVar($data), true);
             return $this->text("Collector '{$collectorName}' data (JSON failed, dumped):\n" . $textData);
         }
    }
}