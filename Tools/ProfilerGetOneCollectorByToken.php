<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\Finder\Finder;
use Symfony\Component\VarDumper\VarDumper;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class ProfilerGetOneCollectorByToken
{
    private string $baseCacheDir;
    private string $environment;

    // Inject the Profiler service
    public function __construct(string $baseCacheDir, string $environment)
    {
        $this->baseCacheDir = $baseCacheDir;
        $this->environment = $environment;
    }

    // Add type hints for parameters
    public function execute(string $token, string $collectorName): string
    {
        $finder = new Finder();
        $profile = null;
        $foundAppId = null; // Optional
        $multiAppChecked = false;
        $directProfilerPath = null;

        // --- Try Multi-App Structure First ---
        // Assumes baseCacheDir is like /path/to/var/cache
        try {
            $appIdDirs = $finder->directories()->in($this->baseCacheDir)->depth('== 0')->name('*_*');
            $multiAppChecked = true;

            if ($appIdDirs->hasResults()) {
                foreach ($appIdDirs as $appIdDir) {
                    $profilerDir = $appIdDir->getRealPath() . '/' . $this->environment . '/profiler';
                    $dsn = 'file:' . $profilerDir;

                    if (!is_dir($profilerDir)) {
                        continue;
                    }

                    try {
                        $storage = new FileProfilerStorage($dsn);
                        if ($storage->read($token)) {
                            $tempProfiler = new Profiler($storage);
                            $profile = $tempProfiler->loadProfile($token);
                            // $foundAppId = explode('_', $appIdDir->getFilename())[0]; // Optional
                            if ($profile) {
                                break; // Found the profile
                            }
                        }
                    } catch (\Exception $e) {
                        continue; // Ignore errors for individual storages
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            // Base cache dir might be inaccessible or not exist
            $multiAppChecked = false;
        }

        // --- Fallback to Single-App Structure if not found ---
        if (!$profile) {
            // Construct the path like /path/to/var/cache/dev/profiler
            $directProfilerPath = $this->baseCacheDir . '/' . $this->environment . '/profiler';
            if (is_dir($directProfilerPath)) {
                 $dsn = 'file:' . $directProfilerPath;
                 try {
                     $storage = new FileProfilerStorage($dsn);
                     if ($storage->read($token)) {
                         $profiler = new Profiler($storage);
                         $profile = $profiler->loadProfile($token);
                         // $foundAppId = null; // Explicitly null for single-app
                     }
                 } catch (\Exception $e) {
                     // Log error? Fall through to !$profile check
                     // Consider logging this error
                 }
            }
        }

        // --- Process the found profile (if any) ---
        if (!$profile) {
             $checkedPaths = $multiAppChecked ? $this->baseCacheDir . '/*_*/' . $this->environment . '/profiler' : '(multi-app check failed)';
             $checkedPaths .= ($directProfilerPath && is_dir($directProfilerPath) ? ' and ' . $directProfilerPath : '');
            return json_encode(['error' => "No profile found for token: {$token}. Checked: " . $checkedPaths]);
        }

        // --- Original logic to get collector data ---
        if (!$profile->hasCollector($collectorName)) {
            // Add AppId to error message if available
            $appIdMsg = $foundAppId ? " (App: {$foundAppId})" : "";
            return json_encode(['error' => "Collector '{$collectorName}' not found for token: {$token}{$appIdMsg}"]);
        }

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
                // Attempt to encode to check for serialization issues early
                // This helps prevent fatal errors later if data is unencodable
                json_encode($data);
            } catch (\Exception $e) {
                // If getData() exists but fails or json_encode fails
                $dumpedData = $this->dumpData($collector);
                $data = null; // Ensure data is null if dumping occurred
            }
        } else {
            // If getData() doesn't exist, dump the whole collector
            $dumpedData = $this->dumpData($collector);
        }

        // Return data or dumped representation
        if ($data !== null) {
            // If we have serializable data from getData()
             try {
                 // Use substitute flag for robustness
                 return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
             } catch (\Exception $e) {
                 // Should be rare if json_encode passed above, but fallback just in case
                 $dumpedData = $this->dumpData($data); // Dump the data that failed to encode
                 return "Collector '{$collectorName}' data (JSON failed, dumped):\n" . $dumpedData;
             }
        } elseif ($dumpedData !== null) {
            // If we only have dumped data (either from failed getData or no getData method)
            return "Collector '{$collectorName}' data (dumped):\n" . $dumpedData;
        } else {
            // Should not happen if collector was found, but handle gracefully
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
