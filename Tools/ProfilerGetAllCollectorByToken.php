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
        $multiAppChecked = false; // Flag to know if we checked the multi-app structure
        $directProfilerPath = null; // Initialize path variable

        // --- Try Multi-App Structure First ---
        // Assumes baseCacheDir is like /path/to/var/cache
        try {
            $appIdDirs = $finder->directories()->in($this->baseCacheDir)->depth('== 0')->name('*_*');
            $multiAppChecked = true; // We attempted to check this structure

            if ($appIdDirs->hasResults()) {
                foreach ($appIdDirs as $appIdDir) {
                    $profilerDir = $appIdDir->getRealPath() . '/' . $this->environment . '/profiler';
                    $dsn = 'file:' . $profilerDir;

                    if (!is_dir($profilerDir)) {
                        continue;
                    }

                    try {
                        $storage = new FileProfilerStorage($dsn);
                        if ($storage->read($token)) {
                            $tempProfiler = new Profiler($storage);
                            $profile = $tempProfiler->loadProfile($token);
                            // $foundAppId = explode('_', $appIdDir->getFilename())[0]; // Optional
                            if ($profile) {
                                break; // Found the profile, exit multi-app loop
                            }
                        }
                    } catch (\Exception $e) {
                        continue; // Ignore errors for individual app storages
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            // Base cache dir might be inaccessible or not exist, proceed to check single-app path
            $multiAppChecked = false; // Indicate multi-app check failed early
        }

        // --- Fallback to Single-App Structure if not found in Multi-App ---
        if (!$profile) {
            // Construct the path like /path/to/var/cache/dev/profiler
            $directProfilerPath = $this->baseCacheDir . '/' . $this->environment . '/profiler';

            if (is_dir($directProfilerPath)) {
                 $dsn = 'file:' . $directProfilerPath;
                 try {
                     $storage = new FileProfilerStorage($dsn);
                     // Check token existence first for efficiency
                     if ($storage->read($token)) {
                         $profiler = new Profiler($storage);
                         $profile = $profiler->loadProfile($token);
                         // $foundAppId = null; // Explicitly null for single-app
                     }
                 } catch (\Exception $e) {
                     // Error accessing single-app profiler, let it fall through to the !$profile check
                     // Consider logging this error
                 }
            }
        }

        // --- Process the found profile (if any) ---
        if (!$profile) {
             $checkedPaths = $multiAppChecked ? $this->baseCacheDir . '/*_*/' . $this->environment . '/profiler' : '(multi-app check failed)';
             $checkedPaths .= ($directProfilerPath && is_dir($directProfilerPath) ? ' and ' . $directProfilerPath : '');
            return json_encode(['error' => "No profile found for token: {$token}. Checked: " . $checkedPaths]);
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
