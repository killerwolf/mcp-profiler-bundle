<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\Finder\Finder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

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
        // Ensure profiler is available
        // Placeholder for profile search logic

        // Load the profile for the given token
        $finder = new Finder();
        $profile = null;
        $foundAppId = null; // Optional: Track which app the token was found in

        try {
            $appIdDirs = $finder->directories()->in($this->baseCacheDir)->depth('== 0')->name('*_*');
        } catch (\InvalidArgumentException $e) {
            return json_encode(['error' => 'Could not access base cache directory: ' . $this->baseCacheDir . ' - ' . $e->getMessage()]);
        }

        if (!$appIdDirs->hasResults()) {
            return json_encode(['warning' => 'Could not find application cache directories in ' . $this->baseCacheDir]);
        }

        foreach ($appIdDirs as $appIdDir) {
            $profilerDir = $appIdDir->getRealPath() . '/' . $this->environment . '/profiler';
            $dsn = 'file:' . $profilerDir;

            if (!is_dir($profilerDir)) {
                continue;
            }

            try {
                $storage = new FileProfilerStorage($dsn);
                // Check if the token exists in this storage *before* creating Profiler
                if ($storage->read($token)) {
                    $tempProfiler = new Profiler($storage);
                    $profile = $tempProfiler->loadProfile($token);
                    $foundAppId = explode('_', $appIdDir->getFilename())[0]; // Store the App ID
                    if ($profile) {
                        break; // Found the profile, exit loop
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors for individual storages, continue searching
                continue;
            }
        }

        if (!$profile) {
            return json_encode(['error' => "No profile found for token: {$token}"]);
        }

        // Prepare the response data
        $data = [
            'token' => $profile->getToken(),
            'ip' => $profile->getIp(),
            'method' => $profile->getMethod(),
            'url' => $profile->getUrl(),
            'time' => $profile->getTime(),
            'status_code' => $profile->getStatusCode(),
            'collectors' => []
        ];

        // Get all collectors from the profile
        $collectors = $profile->getCollectors();

        // Add collector data (Keep the existing complex logic for handling different collectors)
        foreach ($collectors as $collector) {
            $collectorName = $collector->getName();

            if (method_exists($collector, 'getData')) {
                try {
                    $collectorData = $collector->getData();
                    json_encode($collectorData);
                    $data['collectors'][$collectorName] = $collectorData;
                } catch (\Exception $e) {
                    $data['collectors'][$collectorName] = ['error' => 'Could not serialize data: ' . $e->getMessage()];
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
                            json_encode($value);
                            $collectorData[$propertyName] = $value;
                        }
                    }
                    if ($reflectionClass->hasProperty('data')) {
                        $dataProperty = $reflectionClass->getProperty('data');
                        $dataValue = $dataProperty->getValue($collector);
                        if ($dataValue instanceof \Symfony\Component\VarDumper\Cloner\Data) {
                            $collectorData['data_object'] = 'Symfony VarDumper Data object (not directly serializable)';
                        } else {
                            json_encode($dataValue);
                            if (is_array($dataValue)) {
                                $collectorData = array_merge($collectorData, $dataValue);
                            } elseif ($dataValue !== null) {
                                $collectorData['data_value'] = $dataValue;
                            }
                        }
                    }
                    $data['collectors'][$collectorName] = $collectorData;
                } catch (\Exception $e) {
                    $data['collectors'][$collectorName] = ['error' => 'Could not extract or serialize data: ' . $e->getMessage()];
                }
            }
        }

        // Return JSON string
        try {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Exception $e) {
            return json_encode(['error' => 'Failed to encode final data: ' . $e->getMessage()]);
        }
    }

}
