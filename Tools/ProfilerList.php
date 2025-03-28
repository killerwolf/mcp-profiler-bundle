<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use PhpLlm\Mcp\Sdk\Contracts\ToolInterface;
use PhpLlm\Mcp\Sdk\Data\Parameter; // Assuming Parameter class for definition
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ProfilerList implements ToolInterface {
    private ?Profiler $profiler = null;
    private static ?ContainerInterface $container = null;
    private ?ParameterBagInterface $parameterBag = null;

    public function __construct($profilerOrConfig = null, ?array $config = null, ?ParameterBagInterface $parameterBag = null)
    {
        // Handle different constructor argument options
        if ($profilerOrConfig instanceof Profiler) {
            $this->profiler = $profilerOrConfig;
        } elseif (is_array($profilerOrConfig)) {
            // Config was passed as first argument, handle if needed
        } else {
            // No profiler or config passed initially
        }
        
        $this->parameterBag = $parameterBag;
    }

    // --- ToolInterface Methods ---

    public function getName(): string
    {
        return 'profiler_list';
    }

    public function getDescription(): string
    {
        return 'List recent Symfony profiler entries';
    }

    public function getParameters(): array
    {
        // Assuming Parameter class structure from php-llm/mcp-sdk
        return [
            new Parameter('limit', Parameter::TYPE_INTEGER, 'Maximum number of profiles to return', false, 20), // Added default
            new Parameter('ip', Parameter::TYPE_STRING, 'Filter by IP address', false),
            new Parameter('url', Parameter::TYPE_STRING, 'Filter by URL', false),
            new Parameter('method', Parameter::TYPE_STRING, 'Filter by HTTP method', false),
            new Parameter('status_code', Parameter::TYPE_INTEGER, 'Filter by HTTP status code', false),
        ];
    }

    public function execute(array $arguments): string
    {
        // Set default values if not provided (using defaults from getParameters if possible)
        $limit = $arguments['limit'] ?? 20;
        $ip = $arguments['ip'] ?? null;
        $url = $arguments['url'] ?? null;
        $method = $arguments['method'] ?? null;
        $statusCode = $arguments['status_code'] ?? null;
        
        // Get the profiler
        $profiler = $this->getProfiler();
        if (!$profiler) {
            // Fall back to storage-based approach if profiler is not available
            return $this->legacyFindProfiles($limit, $ip, $url, $method, $statusCode);
        }        
        try {
            // Use the profiler to find profiles matching the criteria
            $tokens = $profiler->find($ip, $url, $limit, $method, null, null, $statusCode);
            
            if (empty($tokens)) {
                return "No profiler entries found.";
            }
            
            // Format the results
            $results = [];
            foreach ($tokens as $token) {
                // Load the full profile to get complete data
                $profile = $profiler->loadProfile($token['token']);
                if (!$profile) {
                    continue;
                }
                
                $results[] = [
                    'token' => $profile->getToken(),
                    'ip' => $profile->getIp(),
                    'method' => $profile->getMethod(),
                    'url' => $profile->getUrl(),
                    'time' => date('Y-m-d H:i:s', $profile->getTime()),
                    'status_code' => $profile->getStatusCode()
                ];
            }
            
            if (empty($results)) {
                return "No profiler entries match the specified criteria.";
            }
            
            return json_encode($results, JSON_PRETTY_PRINT);
            
        } catch (\Exception $e) {
            return "Error retrieving profiler entries: " . $e->getMessage();
        }
    }
    
    /**
     * Get the profiler instance
     */
    private function getProfiler(): ?Profiler
    {
        // If profiler is already set, return it
        if ($this->profiler !== null) {
            return $this->profiler;
        }
        
        // Try to get profiler from container
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
                        // Ensure type compatibility if possible, otherwise suppress error or cast
                        if ($container instanceof ContainerInterface) {
                             self::$container = $container;
                        }
                    }
                }
            }
        }
        
        // Get profiler from container
        if (self::$container !== null && self::$container->has('profiler')) {
            $profilerService = self::$container->get('profiler');
             if ($profilerService instanceof Profiler) {
                 $this->profiler = $profilerService;
                 return $this->profiler;
             }
        }
        
        return null;
    }
    
    /**
     * Get the profiler storage
     */
    private function getProfilerStorage()
    {
        // If profiler is already set, get its storage
        if ($this->profiler !== null) {
            try {
                $reflection = new \ReflectionClass($this->profiler);
                if ($reflection->hasProperty('storage')) {
                    $storageProperty = $reflection->getProperty('storage');
                    // $storageProperty->setAccessible(true); // Deprecated in PHP 8.1+
                    return $storageProperty->getValue($this->profiler);
                }
            } catch (\ReflectionException $e) {
                 // Handle reflection error if needed
            }
        }
        
        // Try to create a storage directly
        $configuredPath = null;
        
        if ($this->parameterBag !== null && $this->parameterBag->has('mcp_profiler.profiler.storage_path')) {
            $configuredPath = $this->parameterBag->get('mcp_profiler.profiler.storage_path');
        } elseif (self::$container !== null && self::$container->hasParameter('mcp_profiler.profiler.storage_path')) {
            $configuredPath = self::$container->getParameter('mcp_profiler.profiler.storage_path');
        }
        
        // Check if configured path exists and is usable
        if ($configuredPath && is_dir($configuredPath)) {
            return new FileProfilerStorage('file:' . $configuredPath);
        }
        
        // Common storage locations to try
        $storagePaths = [
            getcwd() . '/var/cache/dev/profiler',
            ($_SERVER['DOCUMENT_ROOT'] ?? getcwd()) . '/var/cache/dev/profiler', // Safer DOCUMENT_ROOT access
            sys_get_temp_dir() . '/symfony/profiler'
        ];
        
        foreach ($storagePaths as $path) {
            if ($path && is_dir($path)) { // Check path is not empty
                return new FileProfilerStorage('file:'.$path);
            }
        }
        
        return null;
    }
    
    /**
     * Legacy method to find profiles using storage directly
     * Used as a fallback when profiler is not available
     */
    private function legacyFindProfiles(int $limit, ?string $ip, ?string $url, ?string $method, ?string $statusCode): string
    {
        // Get the profiler storage
        $storage = $this->getProfilerStorage();
        if (!$storage) {
            return "Error: Profiler storage not available.";
        }
        
        try {
            $tokens = $storage->find($ip ?? '', $url ?? '', $limit, $method ?? '', null, null, $statusCode);
            
            if (empty($tokens)) {
                return "No profiler entries found.";
            }
            
            // Format the results
            $results = [];
            foreach ($tokens as $token) {
                $results[] = [
                    'token' => $token['token'],
                    'ip' => $token['ip'],
                    'method' => $token['method'],
                    'url' => $token['url'],
                    'time' => date('Y-m-d H:i:s', $token['time']),
                    'status_code' => $token['status_code']
                ];
            }
            
            if (empty($results)) {
                return "No profiler entries match the specified criteria.";
            }
            
            return json_encode($results, JSON_PRETTY_PRINT);
            
        } catch (\Exception $e) {
            return "Error retrieving profiler entries: " . $e->getMessage();
        }
    }
}