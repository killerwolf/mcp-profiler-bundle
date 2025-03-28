<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use PhpLlm\Mcp\Sdk\Contracts\ToolInterface;
use PhpLlm\Mcp\Sdk\Data\Parameter; // Assuming Parameter class for definition
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\DependencyInjection\ContainerInterface; // Keep for getProfiler logic
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ProfilerGetByTokenTool implements ToolInterface {
    private ?Profiler $profiler = null;
    private static ?ContainerInterface $container = null; // Keep for getProfiler logic
    private ?ParameterBagInterface $parameterBag = null;

    // Constructor remains largely the same, just removing parent calls
    public function __construct($profilerOrConfig = null, ?array $config = null, ?ParameterBagInterface $parameterBag = null)
    {
        if ($profilerOrConfig instanceof Profiler) {
            $this->profiler = $profilerOrConfig;
        } elseif (is_array($profilerOrConfig)) {
            // Config passed as first arg
        } else {
            // No initial config
        }
        $this->parameterBag = $parameterBag;
    }

    // --- ToolInterface Methods ---

    public function getName(): string
    {
        return 'profiler_get_by_token';
    }

    public function getDescription(): string
    {
        return 'Access Symfony profiler data by token';
    }

    public function getParameters(): array
    {
        return [
            new Parameter('token', Parameter::TYPE_STRING, 'The profiler token to retrieve data for', true), // Required
        ];
    }

    public function execute(array $arguments): string
    {
        $token = $arguments['token'] ?? null;
        if (!$token) {
             return json_encode(['error' => 'Missing required parameter: token']);
        }

        // Ensure profiler is available (using the existing getProfiler method)
        $profiler = $this->getProfiler();
        if (!$profiler) {
            return json_encode(['error' => 'Profiler service not available.']);
        }

        // Load the profile for the given token
        try {
            $profile = $profiler->loadProfile($token);
        } catch (\Exception $e) {
             return json_encode(['error' => "Error loading profile for token {$token}: " . $e->getMessage()]);
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

        // Add collector data
        foreach ($collectors as $collector) {
            $collectorName = $collector->getName();
            
            // Different handling based on collector type
            if (method_exists($collector, 'getData')) {
                // Standard case - collector has getData() method
                try {
                    $collectorData = $collector->getData();
                    // Attempt to JSON encode to catch unserializable data early
                    json_encode($collectorData); 
                    $data['collectors'][$collectorName] = $collectorData;
                } catch (\Exception $e) {
                     $data['collectors'][$collectorName] = ['error' => 'Could not serialize data: ' . $e->getMessage()];
                }
            } elseif ($collector instanceof \Symfony\Component\HttpKernel\DataCollector\RequestDataCollector) {
                // Special handling for RequestDataCollector (ensure data is serializable)
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
                    json_encode($requestData); // Test serializability
                    $data['collectors'][$collectorName] = $requestData;
                 } catch (\Exception $e) {
                     $data['collectors'][$collectorName] = ['error' => 'Could not serialize RequestDataCollector: ' . $e->getMessage()];
                 }
            } else {
                // Fallback for other collectors - try to extract data using reflection
                try {
                    $reflectionClass = new \ReflectionClass($collector);
                    $collectorData = [];
                    
                    // Try to get public properties
                    foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                        if (!$property->isStatic()) {
                            $propertyName = $property->getName();
                            $value = $property->getValue($collector);
                            // Check if value is serializable before adding
                            json_encode($value); 
                            $collectorData[$propertyName] = $value;
                        }
                    }
                    
                    // Try to get data property if it exists
                    if ($reflectionClass->hasProperty('data')) {
                        $dataProperty = $reflectionClass->getProperty('data');
                        // $dataProperty->setAccessible(true); // Deprecated
                        $dataValue = $dataProperty->getValue($collector);
                        
                        // Handle Symfony VarDumper Data objects
                        if ($dataValue instanceof \Symfony\Component\VarDumper\Cloner\Data) {
                            $collectorData['data_object'] = 'Symfony VarDumper Data object (not directly serializable)';
                        } else {
                             // Check serializability before merging/adding
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
                    // If reflection or serialization fails, store the error
                    $data['collectors'][$collectorName] = ['error' => 'Could not extract or serialize data: ' . $e->getMessage()];
                }
            }
        }
        
        // Return JSON string
        try {
             return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE); // Add flag for potential UTF8 issues
        } catch (\Exception $e) {
             return json_encode(['error' => 'Failed to encode final data: ' . $e->getMessage()]);
        }
    }

    // Keep the getProfiler method as it was in ProfilerList (assuming it's needed and correct)
    /**
     * Get the profiler instance
     */
    private function getProfiler(): ?Profiler
    {
        if ($this->profiler !== null) {
            return $this->profiler;
        }
        if (self::$container === null) {
            global $kernel;
            if (isset($kernel) && method_exists($kernel, 'getContainer')) {
                self::$container = $kernel->getContainer();
            } else if (class_exists('\\Symfony\\Component\\HttpKernel\\KernelInterface')) {
                $kernelFile = $_SERVER['DOCUMENT_ROOT'] ?? getcwd();
                $kernelFile = rtrim($kernelFile, '/') . '/var/cache/dev/App_KernelDevDebugContainer.php';
                if (file_exists($kernelFile)) {
                    require_once $kernelFile;
                    if (class_exists('\\App_KernelDevDebugContainer')) {
                        $container = new \App_KernelDevDebugContainer();
                        if ($container instanceof ContainerInterface) {
                             self::$container = $container;
                        }
                    }
                }
            }
        }
        if (self::$container !== null && self::$container->has('profiler')) {
             $profilerService = self::$container->get('profiler');
             if ($profilerService instanceof Profiler) {
                 $this->profiler = $profilerService;
                 return $this->profiler;
             }
        }
        return null;
    }
}