<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[ToolAttribute('profiler_get_by_token', 'Access Symfony profiler data by token')]
class ProfilerGetByTokenTool extends Tool {
    private ?Profiler $profiler = null;
    private static ?ContainerInterface $container = null;
    private ?ParameterBagInterface $parameterBag = null;

    public function __construct($profilerOrConfig = null, ?array $config = null, ?ParameterBagInterface $parameterBag = null)
    {
        // Handle different constructor argument options
        if ($profilerOrConfig instanceof Profiler) {
            parent::__construct($config ?? []);
            $this->profiler = $profilerOrConfig;
        } elseif (is_array($profilerOrConfig)) {
            parent::__construct($profilerOrConfig);
        } else {
            parent::__construct($config ?? []);
        }
        
        $this->parameterBag = $parameterBag;
    }

    protected function doExecute(
        #[ParameterAttribute('token', type: 'string', description: 'The profiler token to retrieve data for')]
        array $arguments
    ): array {

        $token = $arguments['token'];        
        // Load the profile for the given token
        $profile = $this->profiler->loadProfile($token);
        
        if (!$profile) {
            return $this->text("No profile found for token: {$token}");
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
                $data['collectors'][$collectorName] = $collector->getData();
            } elseif ($collector instanceof \Symfony\Component\HttpKernel\DataCollector\RequestDataCollector) {
                // Special handling for RequestDataCollector
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
                $data['collectors'][$collectorName] = $requestData;
            } else {
                // Fallback for other collectors - try to extract data using reflection
                try {
                    $reflectionClass = new \ReflectionClass($collector);
                    $collectorData = [];
                    
                    // Try to get public properties
                    foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                        if (!$property->isStatic()) {
                            $propertyName = $property->getName();
                            $collectorData[$propertyName] = $property->getValue($collector);
                        }
                    }
                    
                    // Try to get data property if it exists
                    if ($reflectionClass->hasProperty('data')) {
                        $dataProperty = $reflectionClass->getProperty('data');
                        $dataProperty->setAccessible(true);
                        $dataValue = $dataProperty->getValue($collector);
                        
                        // Handle Symfony VarDumper Data objects
                        if ($dataValue instanceof \Symfony\Component\VarDumper\Cloner\Data) {
                            // For VarDumper Data objects, we can't directly merge them
                            $collectorData['data_object'] = 'Symfony VarDumper Data object (not directly serializable)';
                        } elseif (is_array($dataValue)) {
                            // Only merge if it's actually an array
                            $collectorData = array_merge($collectorData, $dataValue);
                        } elseif ($dataValue !== null) {
                            // For other non-null values, store them as a special property
                            $collectorData['data_value'] = $dataValue;
                        }
                    }
                    
                    $data['collectors'][$collectorName] = $collectorData;
                } catch (\Exception $e) {
                    // If reflection fails, store the error
                    $data['collectors'][$collectorName] = ['error' => 'Could not extract data: ' . $e->getMessage()];
                }
            }
        }
        
        return $this->text(json_encode($data, JSON_PRETTY_PRINT));
    }
}