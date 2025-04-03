<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\Finder\Finder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class ProfilerGetByTokenTool
{
    private string $baseCacheDir; // Env-specific cache dir (e.g., var/cache/dev or var/cache/APP_ID/dev)
    private string $environment;
    private ?ParameterBagInterface $parameterBag = null;

    public function __construct(string $baseCacheDir, string $environment, ?ParameterBagInterface $parameterBag = null)
    {
        $this->baseCacheDir = $baseCacheDir;
        $this->environment = $environment;
        $this->parameterBag = $parameterBag;
    }

    public function execute(string $token): string
    {
        $finder = new Finder();
        $profile = null;
        $foundAppId = null;
        $checkedPathsLog = []; // Log paths checked for error message

        // --- Path Derivation ---
        $envCacheDir = $this->baseCacheDir; // e.g., /path/to/var/cache/dev or /path/to/var/cache/APP_ID/dev
        $envName = $this->environment;     // e.g., dev
        $parentOfEnvCacheDir = dirname($envCacheDir); // e.g., /path/to/var/cache or /path/to/var/cache/APP_ID
        // Determine the correct base directory for multi-app search (should be /path/to/var/cache)
        $multiAppBaseSearchDir = $parentOfEnvCacheDir;
        if (strpos(basename($parentOfEnvCacheDir), '_') !== false) {
            $multiAppBaseSearchDir = dirname($parentOfEnvCacheDir);
        }

        // --- 1. Check Direct Path ---
        $directProfilerPath = $envCacheDir . '/profiler';
        if (is_dir($directProfilerPath)) {
            $checkedPathsLog[] = $directProfilerPath; // Log path checked
            $dsn = 'file:' . $directProfilerPath;
            try {
                $storage = new FileProfilerStorage($dsn);
                if ($storage->read($token)) {
                    $profiler = new Profiler($storage);
                    $profile = $profiler->loadProfile($token);
                    // Determine App ID from path structure ONLY if found here
                    if ($profile) {
                        $pathParts = explode('/', trim($this->baseCacheDir, '/'));
                        $parentDirName = $pathParts[count($pathParts) - 2] ?? null;
                        $foundAppId = (strpos($parentDirName, '_') !== false) ? explode('_', $parentDirName)[0] : null;
                    }
                }
            } catch (\Exception $e) {
                 $checkedPathsLog[count($checkedPathsLog)-1] .= ' (error: ' . $e->getMessage() . ')';
            }
        } else {
             $checkedPathsLog[] = $directProfilerPath . ' (not found)';
        }

        // --- 2. Check Multi-App Structure (Only if not found in direct path) ---
        if (!$profile) {
            $multiAppPathPattern = $multiAppBaseSearchDir . '/*_*/' . $envName . '/profiler';
            $checkedPathsLog[] = $multiAppPathPattern; // Log pattern checked
            try {
                // Check if the base search directory exists
                if (is_dir($multiAppBaseSearchDir)) {
                    $appIdDirs = $finder->directories()->in($multiAppBaseSearchDir)->depth('== 0')->name('*_*');
                    if ($appIdDirs->hasResults()) {
                        foreach ($appIdDirs as $appIdDir) {
                            $profilerDir = $appIdDir->getRealPath() . '/' . $envName . '/profiler';
                            // Skip if this is the same as the direct path we already checked
                            if ($profilerDir === $directProfilerPath) {
                                continue;
                            }
                            $dsn = 'file:' . $profilerDir;
                            if (!is_dir($profilerDir)) continue;

                            try {
                                $storage = new FileProfilerStorage($dsn);
                                if ($storage->read($token)) {
                                    $tempProfiler = new Profiler($storage);
                                    $profile = $tempProfiler->loadProfile($token);
                                    $foundAppId = explode('_', $appIdDir->getFilename())[0]; // Found via multi-app
                                    if ($profile) break; // Found
                                }
                            } catch (\Exception $e) {
                                // Ignore and continue search
                            }
                        }
                        // Add status if loop finished without finding profile
                        if (!$profile) {
                             $checkedPathsLog[count($checkedPathsLog)-1] .= ' (token not found in app dirs)';
                        }
                    } else {
                         $checkedPathsLog[count($checkedPathsLog)-1] .= ' (no app dirs found)';
                    }
                } else {
                     $checkedPathsLog[count($checkedPathsLog)-1] .= ' (base dir not found)';
                }
            } catch (\InvalidArgumentException $e) {
                 $checkedPathsLog[count($checkedPathsLog)-1] .= ' (error accessing base dir: ' . $e->getMessage() . ')';
            }
        }


        // --- Process the found profile (if any) ---
        if (!$profile) {
            // Use the detailed log for the error message
            return json_encode(['error' => "No profile found for token: {$token}. Checked: " . implode('; ', $checkedPathsLog)]);
        }

        // --- Original logic to prepare response data ---
        $data = [
            'token' => $profile->getToken(),
            'ip' => $profile->getIp(),
            'method' => $profile->getMethod(),
            'url' => $profile->getUrl(),
            'time' => $profile->getTime(),
            'status_code' => $profile->getStatusCode(),
            'collectors' => []
        ];
        if ($foundAppId) {
            $data['appId'] = $foundAppId;
        }

        $collectors = $profile->getCollectors();
        foreach ($collectors as $collector) {
             $collectorName = $collector->getName();
             // ... (rest of collector processing logic remains the same) ...
             if (method_exists($collector, 'getData')) {
                 try {
                     $collectorData = $collector->getData();
                     json_encode($collectorData);
                     $data['collectors'][$collectorName] = $collectorData;
                 } catch (\Exception $e) {
                     $cloner = new VarCloner();
                     $dumper = new CliDumper();
                     $output = fopen('php://memory', 'r+');
                     $dump = "Could not dump data.";
                     try {
                         $originalData = $collector->getData();
                         $dumper->dump($cloner->cloneVar($originalData), $output);
                         rewind($output);
                         $dump = stream_get_contents($output);
                     } catch (\Exception $dumpError) {
                         $dump = "Could not dump data: " . $dumpError->getMessage();
                     }
                     fclose($output);
                     $data['collectors'][$collectorName] = ['error' => 'Could not serialize data: ' . $e->getMessage(), 'dump' => $dump];
                 }
             } elseif ($collector instanceof \Symfony\Component\HttpKernel\DataCollector\RequestDataCollector) {
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
                     json_encode($requestData);
                     $data['collectors'][$collectorName] = $requestData;
                 } catch (\Exception $e) {
                     $data['collectors'][$collectorName] = ['error' => 'Could not serialize RequestDataCollector: ' . $e->getMessage()];
                 }
             } else {
                 try {
                     $reflectionClass = new \ReflectionClass($collector);
                     $collectorData = [];
                     foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                         if (!$property->isStatic()) {
                             $propertyName = $property->getName();
                             $value = $property->getValue($collector);
                             try {
                                 json_encode($value);
                                 $collectorData[$propertyName] = $value;
                             } catch (\Exception $e) {
                                 $collectorData[$propertyName] = ['error' => 'Unserializable value', 'type' => get_debug_type($value)];
                             }
                         }
                     }
                     if ($reflectionClass->hasProperty('data')) {
                         $dataProperty = $reflectionClass->getProperty('data');
                         $dataValue = $dataProperty->getValue($collector);
                         if ($dataValue instanceof \Symfony\Component\VarDumper\Cloner\Data) {
                             $collectorData['data_object'] = 'Symfony VarDumper Data object (not directly serializable)';
                         } else {
                             try {
                                 json_encode($dataValue);
                                 if (is_array($dataValue)) {
                                     $collectorData = array_merge($collectorData, $dataValue);
                                 } elseif ($dataValue !== null) {
                                     $collectorData['data_value'] = $dataValue;
                                 }
                             } catch (\Exception $e) {
                                  $collectorData['data_value'] = ['error' => 'Unserializable value in data property', 'type' => get_debug_type($dataValue)];
                             }
                         }
                     }
                     $data['collectors'][$collectorName] = $collectorData;
                 } catch (\Exception $e) {
                     $data['collectors'][$collectorName] = ['error' => 'Could not extract or serialize data via reflection: ' . $e->getMessage()];
                 }
             }
        }

        // Return JSON string
        try {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Failed to encode final data: ' . $e->getMessage()]);
        }
    }

} // End of class
