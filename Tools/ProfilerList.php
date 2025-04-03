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
        $allProfiles = [];
        $processed = false; // Flag to track if any profiler data was processed

        // --- Try Multi-App Structure First ---
        // Assumes baseCacheDir is like /path/to/var/cache
        try {
            $appIdDirs = $finder->directories()->in($this->baseCacheDir)->depth('== 0')->name('*_*');

            if ($appIdDirs->hasResults()) {
                $appCount = $appIdDirs->count();
                foreach ($appIdDirs as $appIdDir) {
                    $appIdDirName = $appIdDir->getFilename();
                    $appIdParts = explode('_', $appIdDirName);
                    $appId = $appIdParts[0];

                    $profilerDir = $appIdDir->getRealPath() . '/' . $this->environment . '/profiler';
                    $dsn = 'file:' . $profilerDir;

                    if (!is_dir($profilerDir)) {
                        continue;
                    }

                    try {
                        $storage = new FileProfilerStorage($dsn);
                        $tempProfiler = new Profiler($storage);
                        // Fetch slightly more per app to increase chance of getting overall limit after sorting
                        $findLimit = $appCount > 0 ? ceil($limit / $appCount) + 5 : $limit;
                        $tokens = $tempProfiler->find($ip, $url, $findLimit, $method, null, null, $statusCode);

                        foreach ($tokens as $token) {
                            $profile = $tempProfiler->loadProfile($token['token']);
                            if ($profile instanceof Profile) {
                                $allProfiles[] = ['appId' => $appId, 'profile' => $profile];
                                $processed = true; // Mark as processed (multi-app structure found and used)
                            }
                        }
                    } catch (\Exception $e) {
                        continue; // Ignore errors for individual app storages
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            // Base cache dir might be inaccessible or not exist, proceed to check single-app path
        }

        // --- Fallback to Single-App Structure if Multi-App wasn't processed ---
        if (!$processed) {
            // Construct the path like /path/to/var/cache/dev/profiler
            $directProfilerPath = $this->baseCacheDir . '/' . $this->environment . '/profiler';

            if (is_dir($directProfilerPath)) {
                 $dsn = 'file:' . $directProfilerPath;
                 try {
                     $storage = new FileProfilerStorage($dsn);
                     $profiler = new Profiler($storage);
                     $tokens = $profiler->find($ip, $url, $limit, $method, null, null, $statusCode);

                     foreach ($tokens as $token) {
                         $profile = $profiler->loadProfile($token['token']);
                         if ($profile instanceof Profile) {
                             // Add profile without appId for single-app case
                             $allProfiles[] = ['profile' => $profile];
                             // No need to set $processed=true here, as we only reach this if multi-app failed
                         }
                     }
                 } catch (\Exception $e) {
                     // Error accessing single-app profiler, return error or empty
                     // Avoid returning here, let it fall through to the empty check below
                     // Consider logging this error instead
                 }
            }
        }


        // --- Process Combined Results ---
        if (empty($allProfiles)) {
            // Provide a more informative message if no structure was found or accessible
             $checkedPaths = $this->baseCacheDir . '/*_*/' . $this->environment . '/profiler' .
                             (isset($directProfilerPath) ? ' and ' . $directProfilerPath : '');
            return json_encode(['message' => 'No profiles found matching the criteria. Checked: ' . $checkedPaths]);
        }

        // Sort all found profiles by time, descending
        usort($allProfiles, fn ($a, $b) => $b['profile']->getTime() <=> $a['profile']->getTime());

        // Limit to the requested number *after* sorting all results
        $limitedProfiles = array_slice($allProfiles, 0, $limit);

        // Format the final results
        $results = [];
        foreach ($limitedProfiles as $profileData) {
            $profile = $profileData['profile'];
            $resultEntry = [
                // Conditionally add appId if it exists
                'token' => $profile->getToken(),
                'ip' => $profile->getIp(),
                'method' => $profile->getMethod(),
                'url' => $profile->getUrl(),
                'time' => date('Y-m-d H:i:s', $profile->getTime()),
                'status_code' => $profile->getStatusCode()
            ];
            // Add appId only if it was set (i.e., came from multi-app logic)
            if (isset($profileData['appId'])) {
                 $resultEntry['appId'] = $profileData['appId'];
            }
            $results[] = $resultEntry;
        }

        return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }


}
