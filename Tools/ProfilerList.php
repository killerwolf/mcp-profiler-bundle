<?php

namespace Killerwolf\MCPProfilerBundle\Tools;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;

class ProfilerList
{
    private string $baseCacheDir;
    private string $environment;
    private ?ParameterBagInterface $parameterBag = null;

    public function __construct(string $baseCacheDir, string $environment, ?ParameterBagInterface $parameterBag = null)
    {
        $this->baseCacheDir = $baseCacheDir;
        $this->environment = $environment;
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
        $finder = new Finder();
        // Find directories like 'fac_FAC', 'voi_VOI' etc. in base cache dir
        try {
            $appIdDirs = $finder->directories()->in($this->baseCacheDir)->depth('== 0')->name('*_*');
        } catch (\InvalidArgumentException $e) {
            // Handle case where baseCacheDir might not exist or is inaccessible
            return json_encode(['error' => 'Could not access base cache directory: ' . $this->baseCacheDir . ' - ' . $e->getMessage()]);
        }

        $allProfiles = [];
        $appCount = $appIdDirs->count(); // Count apps for calculating find limit

        if (!$appIdDirs->hasResults()) {
            // Maybe return a warning or an empty list if no app dirs found?
            return json_encode(['warning' => 'Could not find application cache directories in ' . $this->baseCacheDir]);
        }

        foreach ($appIdDirs as $appIdDir) {
            $appIdDirName = $appIdDir->getFilename();
            // Extract App ID (e.g., 'fac' from 'fac_FAC') - adjust logic if needed
            $appIdParts = explode('_', $appIdDirName);
            $appId = $appIdParts[0]; // Assume first part is the app ID

            $profilerDir = $appIdDir->getRealPath() . '/' . $this->environment . '/profiler';
            $dsn = 'file:' . $profilerDir;

            if (!is_dir($profilerDir)) {
                continue; // Skip if profiler dir doesn't exist for this app/env
            }

            try {
                $storage = new FileProfilerStorage($dsn);
                // Create a temporary profiler for this specific storage
                $tempProfiler = new Profiler($storage);
                // Find tokens - fetch more initially to sort accurately later
                // Adjust limit per app based on total apps to try and get enough overall
                $findLimit = $appCount > 0 ? ceil($limit / $appCount) + 5 : $limit; // Fetch slightly more per app
                $tokens = $tempProfiler->find($ip, $url, $findLimit, $method, null, null, $statusCode);

                foreach ($tokens as $token) {
                    // Load profile using the temporary profiler instance
                    $profile = $tempProfiler->loadProfile($token['token']);
                    if ($profile instanceof Profile) {
                        // Store appId alongside the profile object
                        $allProfiles[] = ['appId' => $appId, 'profile' => $profile];
                    }
                }
            } catch (\Exception $e) {
                // Log or warn about issues accessing storage for a specific app
                // For now, we'll just continue, but logging could be added
                continue;
            }
        }

        if (empty($allProfiles)) {
            return json_encode(['message' => 'No profiles found matching the criteria across all applications.']);
        }

        // Sort all found profiles by time, descending
        usort($allProfiles, fn ($a, $b) => $b['profile']->getTime() <=> $a['profile']->getTime());

        // Limit to the requested number *after* sorting all results
        $limitedProfiles = array_slice($allProfiles, 0, $limit);

        // Format the final results
        $results = [];
        foreach ($limitedProfiles as $profileData) {
            $profile = $profileData['profile']; // Extract profile object
            $results[] = [
                'appId' => $profileData['appId'], // Include the App ID
                'token' => $profile->getToken(),
                'ip' => $profile->getIp(),
                'method' => $profile->getMethod(),
                'url' => $profile->getUrl(),
                'time' => date('Y-m-d H:i:s', $profile->getTime()),
                'status_code' => $profile->getStatusCode()
            ];
        }

        return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Multi-app logic will be inserted here.


    }


}
