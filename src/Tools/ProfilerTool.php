<?php

namespace MCP\ServerBundle\Tools;

use MCP\Server\Tool\Tool;
use MCP\Server\Tool\Attribute\Tool as ToolAttribute;
use MCP\Server\Tool\Attribute\Parameter as ParameterAttribute;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[ToolAttribute('profiler', 'Access Symfony profiler data by token')]
class ProfilerTool extends Tool {
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
        // If profiler is not set, try various methods to get or create it
        if ($this->profiler === null) {
            // Method 1: Try to get it from the container
            if (!$this->tryGetProfilerFromContainer()) {
                // Method 2: Try to create a profiler with configured or default storage
                if (!$this->tryCreateProfilerWithStorage()) {
                    // Method 3: Try to find the profiler storage directory from kernel
                    if (!$this->tryCreateProfilerFromKernelPath()) {
                        return $this->text("Error: Profiler service not available. Unable to initialize a profiler instance.");
                    }
                }
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
    
    /**
     * Try to get the profiler from the container
     */
    private function tryGetProfilerFromContainer(): bool
    {
        if (self::$container === null) {
            // Try to get the container from the global kernel
            global $kernel;
            if (isset($kernel) && method_exists($kernel, 'getContainer')) {
                self::$container = $kernel->getContainer();
            } else if (class_exists('\\Symfony\\Component\\HttpKernel\\KernelInterface')) {
                // Try to get kernel from bootstrap cache if available
                $kernelFile = $_SERVER['DOCUMENT_ROOT'] ?? getcwd();
                $kernelFile = rtrim($kernelFile, '/') . '/var/cache/dev/App_KernelDevDebugContainer.php';
                if (file_exists($kernelFile)) {
                    require_once $kernelFile;
                    if (class_exists('\\App_KernelDevDebugContainer')) {
                        $container = new \App_KernelDevDebugContainer();
                        self::$container = $container;
                    }
                }
            }
        }
        
        // Get profiler from container
        if (self::$container !== null) {
            if (self::$container->has('profiler')) {
                $this->profiler = self::$container->get('profiler');
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Try to create a profiler with configured storage location
     */
    private function tryCreateProfilerWithStorage(): bool
    {
        // First check if we have configuration settings
        $configuredPath = null;
        
        if ($this->parameterBag !== null && $this->parameterBag->has('mcp_server.profiler.storage_path')) {
            $configuredPath = $this->parameterBag->get('mcp_server.profiler.storage_path');
        } elseif (self::$container !== null && self::$container->hasParameter('mcp_server.profiler.storage_path')) {
            $configuredPath = self::$container->getParameter('mcp_server.profiler.storage_path');
        }
        
        // Check if configured path exists and is usable
        if ($configuredPath && is_dir($configuredPath)) {
            $storage = new FileProfilerStorage('file:' . $configuredPath);
            $this->profiler = new Profiler($storage);
            return true;
        }
        
        // Common storage locations to try
        $storagePaths = [
            getcwd() . '/var/cache/dev/profiler',
            $_SERVER['DOCUMENT_ROOT'] . '/var/cache/dev/profiler',
            sys_get_temp_dir() . '/symfony/profiler'
        ];
        
        foreach ($storagePaths as $path) {
            if (is_dir($path)) {
                $storage = new FileProfilerStorage('file:'.$path);
                $this->profiler = new Profiler($storage);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Try to create a profiler by finding the kernel path
     */
    private function tryCreateProfilerFromKernelPath(): bool
    {
        // Try to find the Symfony kernel to determine the profiler storage path
        $possiblePaths = [
            getcwd(),
            $_SERVER['DOCUMENT_ROOT'] ?? '',
            dirname($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '',
        ];
        
        foreach ($possiblePaths as $basePath) {
            if (!$basePath) {
                continue;
            }
            
            // Common Symfony project structures
            $kernelPaths = [
                $basePath . '/src/Kernel.php',
                $basePath . '/app/AppKernel.php'
            ];
            
            foreach ($kernelPaths as $kernelPath) {
                if (file_exists($kernelPath)) {
                    // Found a kernel, try to determine project root and cache dir
                    $cacheDir = $basePath . '/var/cache/dev/profiler';
                    
                    if (is_dir($cacheDir)) {
                        $storage = new FileProfilerStorage('file:'.$cacheDir);
                        $this->profiler = new Profiler($storage);
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
}