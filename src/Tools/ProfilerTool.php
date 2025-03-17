<?php

namespace MCP\ServerBundle\Tools;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[ToolAttribute('profiler', 'Access Symfony profiler data by token')]
class ProfilerTool extends Tool {
    private ?Profiler $profiler = null;
    private static ?ContainerInterface $container = null;

    public function __construct($profilerOrConfig = null, ?array $config = null)
    {
        // Handle the case where only config is passed
        if (is_array($profilerOrConfig)) {
            parent::__construct($profilerOrConfig);
        } 
        // Handle the case where Profiler is passed
        else if ($profilerOrConfig instanceof Profiler) {
            parent::__construct($config ?? []);
            $this->profiler = $profilerOrConfig;
        }
        // Handle the default case
        else {
            parent::__construct($config ?? []);
        }
    }

    protected function doExecute(
        #[ParameterAttribute('token', type: 'string', description: 'The profiler token to retrieve data for')]
        array $arguments
    ): array {
        // If profiler is not set, try to get it from the container
        if ($this->profiler === null) {
            if (self::$container === null) {
                // Try to get the container from the global kernel
                if (class_exists('\\Symfony\\Component\\HttpKernel\\KernelInterface') && 
                    function_exists('\\Symfony\\Component\\HttpKernel\\KernelInterface::getContainer')) {
                    global $kernel;
                    if (isset($kernel) && method_exists($kernel, 'getContainer')) {
                        self::$container = $kernel->getContainer();
                    }
                }
            }
            
            // Get profiler from container
            if (self::$container !== null && self::$container->has('profiler')) {
                $this->profiler = self::$container->get('profiler');
            } else {
                return $this->text("Error: Profiler service not available");
            }
        }
        
        $token = $arguments['token'];
        
        // Load the profile for the given token
        $profile = $this->profiler->loadProfile($token);
        
        if (!$profile) {
            return $this->text("No profile found for token: {$token}");
        }
        
        // Get all collectors from the profile
        $collectors = $profile->getCollectors();
        
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
        
        // Add collector data
        foreach ($collectors as $collector) {
            $data['collectors'][$collector->getName()] = $collector->getData();
        }
        
        return $this->text(json_encode($data, JSON_PRETTY_PRINT));
    }
}