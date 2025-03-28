<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

// Remove ToolInterface use
// Remove Parameter use
use PhpLlm\LlmChain\Chain\ToolBox\Attribute\AsTool; // Add AsTool attribute
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsTool(
    name: 'profiler_get_by_token',
    description: 'Access Symfony profiler data by token',
    method: 'execute' // Point to the execute method
)]
class ProfilerGetByTokenTool { // Remove implements ToolInterface
    private ?Profiler $profiler = null;
    private static ?ContainerInterface $container = null;
    private ?ParameterBagInterface $parameterBag = null;

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

    // Remove getName, getDescription, getParameters methods

    // Add type hint for the token parameter
    public function execute(string $token): string
    {
        // Ensure profiler is available
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

    // --- Helper methods remain the same ---
    /**
     * Get the profiler instance
     */
    private function getProfiler(): ?Profiler
    {
        // ... (keep existing implementation) ...
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