<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\Finder\Finder;
use Symfony\Component\DependencyInjection\ContainerInterface; // Keep if used elsewhere, otherwise remove
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner; // Add for dumping complex data
use Symfony\Component\VarDumper\Dumper\CliDumper; // Add for dumping complex data

class ProfilerGetByTokenTool
{
    private string $baseCacheDir;
    private string $environment;
    private ?ParameterBagInterface $parameterBag = null;

    public function __construct(string $baseCacheDir, string $environment, ?ParameterBagInterface $parameterBag = null)
    {
        $this->baseCacheDir = $baseCacheDir;
        $this->environment = $environment;
        $this->parameterBag = $parameterBag;
    }


    // Add type hint for the token parameter
    public function execute(string $token): string
    {
        $finder = new Finder();
        $profile = null;
        $foundAppId = null; // Track which app the token was found in
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
                            $foundAppId = explode('_', $appIdDir->getFilename())[0]; // Store the App ID
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
                         $foundAppId = null; // Explicitly null for single-app
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

        // --- Original logic to prepare response data ---
        $data = [
            'token' => $profile->getToken(),
            'ip' => $profile->getIp(),
            'method' => $profile->getMethod(),
            'url' => $profile->getUrl(),
            'time' => $profile->getTime(), // Keep as timestamp for potential consumers
            'status_code' => $profile->getStatusCode(),
            'collectors' => []
        ];
        // Add appId if found from multi-app structure
        if ($foundAppId) {
            $data['appId'] = $foundAppId;
        }

        // Get all collectors from the profile
        $collectors = $profile->getCollectors();

        // Add collector data (Keep the existing complex logic)
        foreach ($collectors as $collector) {
             $collectorName = $collector->getName();

             if (method_exists($collector, 'getData')) {
                 try {
                     $collectorData = $collector->getData();
                     // Attempt to encode early to catch errors
                     json_encode($collectorData);
                     $data['collectors'][$collectorName] = $collectorData;
                 } catch (\Exception $e) {
                     // Use VarDumper for complex/unserializable data
                     $cloner = new VarCloner();
                     $dumper = new CliDumper();
                     $output = fopen('php://memory', 'r+');
                     // Attempt to dump the original data that caused the error
                     try {
                         $originalData = $collector->getData(); // Re-fetch original data
                         $dumper->dump($cloner->cloneVar($originalData), $output);
                     } catch (\Exception $dumpError) {
                         // If even fetching/dumping fails, provide a basic error
                         $dump = "Could not dump data: " . $dumpError->getMessage();
                     }
                     rewind($output);
                     $dump = stream_get_contents($output);
                     fclose($output);
                     $data['collectors'][$collectorName] = ['error' => 'Could not serialize data: ' . $e->getMessage(), 'dump' => $dump];
                 }
             } elseif ($collector instanceof \Symfony\Component\HttpKernel\DataCollector\RequestDataCollector) {
                 // Keep specific handling for RequestDataCollector
                 try {
                     $requestData = [
                         'method' => $collector->getMethod(),
                         'request_headers' => $collector->getRequestHeaders()->all(),
                         'response_headers' => $collector->getResponseHeaders()->all(),
                         'request_server' => $collector->getRequestServer()->all(),
                         'request_cookies' => $collector->getRequestCookies()->all(),
                         'request_attributes' => $collector->getRequestAttributes()->all(),
                         'request_query' => $collector->getRequestQuery()->all(),
                         'request_request' => $collector->getRequestRequest()->all(),
                         'content_type' => $collector->getContentType(),
                         'status_code' => $collector->getStatusCode(),
                         'status_text' => $collector->getStatusText(),
                     ];
                     json_encode($requestData); // Check serializability
                     $data['collectors'][$collectorName] = $requestData;
                 } catch (\Exception $e) {
                     $data['collectors'][$collectorName] = ['error' => 'Could not serialize RequestDataCollector: ' . $e->getMessage()];
                 }
             } else {
                 // Fallback using reflection (keep existing logic)
                 try {
                     $reflectionClass = new \ReflectionClass($collector);
                     $collectorData = [];
                     foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                         if (!$property->isStatic()) {
                             $propertyName = $property->getName();
                             $value = $property->getValue($collector);
                             // Attempt to json_encode individual values to catch issues early
                             try {
                                 json_encode($value);
                                 $collectorData[$propertyName] = $value;
                             } catch (\Exception $e) {
                                 $collectorData[$propertyName] = ['error' => 'Unserializable value', 'type' => get_debug_type($value)];
                             }
                         }
                     }
                     // Keep handling for 'data' property if it exists
                     if ($reflectionClass->hasProperty('data')) {
                         $dataProperty = $reflectionClass->getProperty('data');
                         $dataValue = $dataProperty->getValue($collector);
                         if ($dataValue instanceof \Symfony\Component\VarDumper\Cloner\Data) {
                             $collectorData['data_object'] = 'Symfony VarDumper Data object (not directly serializable)';
                         } else {
                             try {
                                 json_encode($dataValue);
                                 if (is_array($dataValue)) {
                                     // Merge carefully to avoid overwriting keys like 'data_object'
                                     $collectorData = array_merge($collectorData, $dataValue);
                                 } elseif ($dataValue !== null) {
                                     $collectorData['data_value'] = $dataValue;
                                 }
                             } catch (\Exception $e) {
                                  $collectorData['data_value'] = ['error' => 'Unserializable value in data property', 'type' => get_debug_type($dataValue)];
                             }
                         }
                     }
                     $data['collectors'][$collectorName] = $collectorData; // Assign extracted data
                 } catch (\Exception $e) {
                     $data['collectors'][$collectorName] = ['error' => 'Could not extract or serialize data via reflection: ' . $e->getMessage()];
                 }
             }
        }

        // Return JSON string
        try {
            // Use substitute flag for robustness
            return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            // Fallback if final encoding fails (should be rare)
            return json_encode(['error' => 'Failed to encode final data: ' . $e->getMessage()]);
        }
    }

} // End of class
