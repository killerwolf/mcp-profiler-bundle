<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\Finder\Finder;

class ProfilerGetAllCollectorByToken
{
    // $baseCacheDir is the env-specific cache dir, e.g., var/cache/dev or var/cache/APP_ID/dev
    private string $baseCacheDir;
    private string $environment;

    public function __construct(string $baseCacheDir, string $environment)
    {
        $this->baseCacheDir = $baseCacheDir;
        $this->environment = $environment;
    }

    public function execute(string $token): string
    {
        $finder = new Finder();
        $profile = null;
        $foundAppId = null; // Optional: Track which app the token was found in
        $checkedPaths = []; // Store paths actually checked

        // --- Path Derivation ---
        $envCacheDir = $this->baseCacheDir;
        $envName = $this->environment;
        $parentOfEnvCacheDir = dirname($envCacheDir);
        $multiAppBaseSearchDir = $parentOfEnvCacheDir;
        if (strpos(basename($parentOfEnvCacheDir), '_') !== false) {
            $multiAppBaseSearchDir = dirname($parentOfEnvCacheDir);
        }

        // --- 1. Check Direct Path ---
        $directProfilerPath = $envCacheDir . '/profiler';
        $checkedPaths[] = $directProfilerPath;

        if (is_dir($directProfilerPath)) {
            $dsn = 'file:' . $directProfilerPath;
            try {
                $storage = new FileProfilerStorage($dsn);
                if ($storage->read($token)) {
                    $profiler = new Profiler($storage);
                    $profile = $profiler->loadProfile($token);
                    $pathParts = explode('/', trim($this->baseCacheDir, '/'));
                    $parentDirName = $pathParts[count($pathParts) - 2] ?? null;
                    $foundAppId = (strpos($parentDirName, '_') !== false) ? explode('_', $parentDirName)[0] : null;
                }
            } catch (\Exception $e) {
                // $checkedPaths[count($checkedPaths)-1] .= ' (error)';
            }
        } else {
             $checkedPaths[count($checkedPaths)-1] .= ' (not found)';
        }

        // --- 2. Check Multi-App Structure (Only if not found in direct path) ---
        if (!$profile) {
            $multiAppPathPattern = $multiAppBaseSearchDir . '/*_*/' . $envName . '/profiler';
            $checkedPaths[] = $multiAppPathPattern;
            try {
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
                    } else {
                         // $checkedPaths[count($checkedPaths)-1] .= ' (no app dirs found)';
                    }
                } else {
                     // $checkedPaths[count($checkedPaths)-1] .= ' (base dir not found)';
                }
            } catch (\InvalidArgumentException $e) {
                 // $checkedPaths[count($checkedPaths)-1] .= ' (error accessing base dir)';
            }
        }

        // --- Process the found profile (if any) ---
        if (!$profile) {
            return json_encode(['error' => "No profile found for token: {$token}. Checked: " . implode(' and ', array_unique($checkedPaths))]);
        }

        // Get collector names from the found profile
        try {
            $collectorNames = array_keys($profile->getCollectors());
            // Optionally include foundAppId in the response if needed
            // $response = ['collectors' => $collectorNames];
            // if ($foundAppId) { $response['appId'] = $foundAppId; }
            // return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return json_encode($collectorNames, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            return json_encode(['error' => "Error getting collector names for token {$token}: " . $e->getMessage()]);
        }
    }
}
