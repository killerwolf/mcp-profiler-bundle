<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;

class ProfilerList
{
    private string $injectedCacheDir; // Env-specific cache dir
    private string $environment;
    private ?ParameterBagInterface $parameterBag = null;

    public function __construct(string $injectedCacheDir, string $environment, ?ParameterBagInterface $parameterBag = null)
    {
        $this->injectedCacheDir = $injectedCacheDir;
        $this->environment = $environment;
        $this->parameterBag = $parameterBag;
    }

    public function execute(
        ?int $limit = 20,
        ?string $ip = null,
        ?string $url = null,
        ?string $method = null,
        ?int $statusCode = null
    ): string {
        // --- Variable Initialization ---
        $finder = new Finder();
        $allProfiles = [];
        $checkedPaths = [];
        $foundProfileTokens = [];

        // --- Path Derivation ---
        $envCacheDir = $this->injectedCacheDir;
        $envName = $this->environment;
        $parentOfEnvCacheDir = dirname($envCacheDir);
        $multiAppBaseSearchDir = $parentOfEnvCacheDir;
        if (strpos(basename($parentOfEnvCacheDir), '_') !== false) {
            $multiAppBaseSearchDir = dirname($parentOfEnvCacheDir);
        }
        $directProfilerPath = $envCacheDir . '/profiler';
        $multiAppPathPattern = $multiAppBaseSearchDir . '/*_*/' . $envName . '/profiler';

        // --- 1. Check Direct Path ---
        $checkedPaths[] = $directProfilerPath;
        if (is_dir($directProfilerPath)) {
            $dsn = 'file:' . $directProfilerPath;
            try {
                $storage = new FileProfilerStorage($dsn);
                $profiler = new Profiler($storage);
                $tokens = $profiler->find($ip, $url, $limit, $method, null, null, $statusCode);

                $pathParts = explode('/', trim($this->injectedCacheDir, '/'));
                $parentDirName = $pathParts[count($pathParts) - 2] ?? null;
                $directPathAppId = (strpos($parentDirName, '_') !== false) ? explode('_', $parentDirName)[0] : null;

                foreach ($tokens as $token) {
                    $profile = $profiler->loadProfile($token['token']);
                    if ($profile instanceof Profile) {
                        $profileData = ['profile' => $profile];
                        if ($directPathAppId) {
                            $profileData['appId'] = $directPathAppId;
                        }
                        $allProfiles[] = $profileData;
                        $foundProfileTokens[$profile->getToken()] = true;
                    }
                }
            } catch (\Exception $e) { /* Log? */
            }
        } else {
            $checkedPaths[count($checkedPaths) - 1] .= ' (not found)';
        }

        // --- 2. Check Multi-App Structure ---
        $checkedPaths[] = $multiAppPathPattern;
        try {
            if (is_dir($multiAppBaseSearchDir)) {
                // Use depth(0) syntax for Finder
                $appIdDirs = $finder->directories()->in($multiAppBaseSearchDir)->name('*_*')->depth(0);

                if ($appIdDirs->hasResults()) {
                    $appCount = $appIdDirs->count();
                    foreach ($appIdDirs as $appIdDir) {
                        $profilerDir = $appIdDir->getRealPath() . '/' . $envName . '/profiler';
                        if ($profilerDir === $directProfilerPath) {
                            continue;
                        }
                        $dsn = 'file:' . $profilerDir;
                        if (!is_dir($profilerDir)) {
                            continue;
                        }

                        try {
                            $storage = new FileProfilerStorage($dsn);
                            $tempProfiler = new Profiler($storage);
                            $findLimit = $appCount > 0 ? ceil($limit / $appCount) + 5 : $limit;
                            $tokens = $tempProfiler->find($ip, $url, $findLimit, $method, null, null, $statusCode);

                            foreach ($tokens as $token) {
                                if (isset($foundProfileTokens[$token['token']])) {
                                    continue;
                                }
                                $profile = $tempProfiler->loadProfile($token['token']);
                                if ($profile instanceof Profile) {
                                    $appId = explode('_', $appIdDir->getFilename())[0];
                                    $allProfiles[] = ['appId' => $appId, 'profile' => $profile];
                                    $foundProfileTokens[$profile->getToken()] = true;
                                }
                            }
                        } catch (\Exception $e) { /* Log? */
                        }
                    }
                } else {
                    $checkedPaths[count($checkedPaths) - 1] .= ' (no app dirs found)';
                }
            } else {
                $checkedPaths[count($checkedPaths) - 1] .= ' (base dir not found)';
            }
        } catch (\InvalidArgumentException $e) {
            $checkedPaths[count($checkedPaths) - 1] .= ' (error accessing base dir)';
        }

        // --- Process Combined Results ---
        if (empty($allProfiles)) {
            return json_encode(['message' => 'No profiles found matching the criteria. Checked: ' . implode(' and ', array_unique($checkedPaths))]);
        }

        usort($allProfiles, fn ($a, $b) => $b['profile']->getTime() <=> $a['profile']->getTime());
        $limitedProfiles = array_slice($allProfiles, 0, $limit);

        $results = [];
        foreach ($limitedProfiles as $profileData) {
            $profile = $profileData['profile'];
            $resultEntry = [
                'token' => $profile->getToken(),
                'ip' => $profile->getIp(),
                'method' => $profile->getMethod(),
                'url' => $profile->getUrl(),
                'time' => date('Y-m-d H:i:s', $profile->getTime()),
                'status_code' => $profile->getStatusCode()
            ];
            if (isset($profileData['appId'])) {
                $resultEntry['appId'] = $profileData['appId'];
            }
            $results[] = $resultEntry;
        }

        return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
