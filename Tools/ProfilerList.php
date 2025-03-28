<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

// Remove ToolInterface use
// Remove Parameter use
use PhpLlm\LlmChain\Chain\ToolBox\Attribute\AsTool; // Add AsTool attribute
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsTool(
    name: 'profiler_list',
    description: 'List recent Symfony profiler entries',
    method: 'execute' // Point to the execute method
)]
class ProfilerList { // Remove implements ToolInterface
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

    // Add type hints for parameters
    public function execute(
        ?int $limit = 20, // Add type hints and default values
        ?string $ip = null,
        ?string $url = null,
        ?string $method = null,
        ?int $statusCode = null
    ): string {
        // Arguments are now directly passed, no need for $arguments array access
        
        // Get the profiler
        $profiler = $this->getProfiler();
        if (!$profiler) {
            // Fall back to storage-based approach if profiler is not available
            // Note: legacyFindProfiles might need similar signature update if used elsewhere
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

    /**
     * Get the profiler storage
     */
    private function getProfilerStorage()
    {
        // ... (keep existing implementation) ...
        if ($this->profiler !== null) {
            try {
                $reflection = new \ReflectionClass($this->profiler);
                if ($reflection->hasProperty('storage')) {
                    $storageProperty = $reflection->getProperty('storage');
                    return $storageProperty->getValue($this->profiler);
                }
            } catch (\ReflectionException $e) { }
        }
        $configuredPath = null;
        if ($this->parameterBag !== null && $this->parameterBag->has('mcp_profiler.profiler.storage_path')) {
            $configuredPath = $this->parameterBag->get('mcp_profiler.profiler.storage_path');
        } elseif (self::$container !== null && self::$container->hasParameter('mcp_profiler.profiler.storage_path')) {
            $configuredPath = self::$container->getParameter('mcp_profiler.profiler.storage_path');
        }
        if ($configuredPath && is_dir($configuredPath)) {
            return new FileProfilerStorage('file:' . $configuredPath);
        }
        $storagePaths = [
            getcwd() . '/var/cache/dev/profiler',
            ($_SERVER['DOCUMENT_ROOT'] ?? getcwd()) . '/var/cache/dev/profiler',
            sys_get_temp_dir() . '/symfony/profiler'
        ];
        foreach ($storagePaths as $path) {
            if ($path && is_dir($path)) {
                return new FileProfilerStorage('file:'.$path);
            }
        }
        return null;
    }

    /**
     * Legacy method to find profiles using storage directly
     * Needs signature update to match execute method
     */
    private function legacyFindProfiles(
        ?int $limit = 20,
        ?string $ip = null,
        ?string $url = null,
        ?string $method = null,
        ?int $statusCode = null
    ): string {
        // Get the profiler storage
        $storage = $this->getProfilerStorage();
        if (!$storage) {
            return "Error: Profiler storage not available.";
        }

        try {
            $tokens = $storage->find($ip ?? '', $url ?? '', $limit ?? 20, $method ?? '', null, null, $statusCode);

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