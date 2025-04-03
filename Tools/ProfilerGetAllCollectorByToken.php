<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\Finder\Finder;

class ProfilerGetAllCollectorByToken
{
    private string $baseCacheDir;
    private string $environment;

    // Inject the Profiler service
    public function __construct(string $baseCacheDir, string $environment)
    {
        $this->baseCacheDir = $baseCacheDir;
        $this->environment = $environment;
    }

    // Add type hint for the token parameter
    public function execute(string $token): string
    {
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
                    // $foundAppId = explode('_', $appIdDir->getFilename())[0]; // Optional
                    if ($profile) {
                        break; // Found the profile, exit loop
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors for individual storages, continue searching
                continue;
            }
        }

        // --- Original logic resumes here, using the found $profile ---

        if (!$profile) {
            return json_encode(['error' => "No profile found for token: {$token} across all applications."]);
        }

        // Get collector names
        try {
            $collectorNames = array_keys($profile->getCollectors());
            // Optionally include foundAppId in the response if needed
            // return json_encode(['appId' => $foundAppId, 'collectors' => $collectorNames], JSON_PRETTY_PRINT);
            return json_encode($collectorNames, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            return json_encode(['error' => "Error getting collector names for token {$token}: " . $e->getMessage()]);
        }
        // Multi-app logic to find profile by token will be inserted here.

    }
}
