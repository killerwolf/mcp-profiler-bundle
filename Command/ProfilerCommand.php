<?php

namespace Killerwolf\MCPProfilerBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

#[AsCommand(name: 'mcp:profiler', description: 'Interact with Symfony profiler')]
class ProfilerCommand extends Command
{
    private ?Profiler $profiler;
    // $cacheDir is the env-specific cache dir, e.g., /path/to/var/cache/dev or /path/to/var/cache/APP_ID/dev
    private string $cacheDir;

    public function __construct(?Profiler $profiler, string $cacheDir)
    {
        parent::__construct();
    /**
     * @param FileProfilerStorage $profilerStorage The profiler storage service, automatically injected by Symfony.
     *                                                Its configuration (e.g., the DSN 'file:%kernel.cache_dir%/profiler' for FileProfilerStorage)
     *                                                is typically defined in framework.yaml or web_profiler.yaml.
     */
        $this->profiler = $profiler;
        $this->cacheDir = $cacheDir;
    }

    protected function configure(): void
    {
        // ... (configure method remains the same) ...
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform (list, show)')
            ->addArgument('token', InputArgument::OPTIONAL, 'Profiler token (required for show action)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Number of profiles to show when listing', 20)
            ->addOption('collector', 'c', InputOption::VALUE_OPTIONAL, 'Specific collector to display for show action')
            ->setHelp(
                <<<EOT
The <info>%command.name%</info> command provides basic interaction with the Symfony profiler.

Available actions:
  * <info>list</info>: List the most recent profiles
    <info>%command.full_name% list --limit=20</info>

  * <info>show</info>: Show details for a specific profile token
    <info>%command.full_name% show abc123</info>
    <info>%command.full_name% show abc123 --collector=request</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ... (execute method remains the same) ...
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        try {
            switch ($action) {
                case 'list':
                    return $this->executeList($input, $output, $io);
                case 'show':
                    return $this->executeShow($input, $output, $io);
                default:
                    $io->error(sprintf('Unknown action "%s"', $action));
                    return Command::INVALID;
            }
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function executeList(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $limit = (int) $input->getOption('limit');
        $io->title(sprintf('Listing the %d most recent profiles', $limit));

        $finder = new Finder();
        $allProfiles = [];
        $checkedPaths = []; // Store paths actually checked
        $foundProfileTokens = []; // Keep track of tokens found to avoid duplicates

        // Get the environment name from the cache directory
        $envName = basename($this->cacheDir); // e.g., 'dev'
        
        // Find all profiler directories in the cache structure
        $profilerDirs = $this->findProfilerDirectories($io);
        
        if (empty($profilerDirs)) {
            $io->warning('No profiler directories found in the cache structure.');
            return Command::SUCCESS;
        }
        
        // Process each profiler directory
        foreach ($profilerDirs as $profilerDir => $appId) {
            $checkedPaths[] = $profilerDir;
            
            if (!is_dir($profilerDir)) {
                continue;
            }
            
            $dsn = 'file:' . $profilerDir;
            try {
                $storage = new FileProfilerStorage($dsn);
                $profiler = new Profiler($storage);
                
                // Adjust limit based on number of profiler directories
                $findLimit = count($profilerDirs) > 1 ? ceil($limit / count($profilerDirs)) + 5 : $limit;
                $tokens = $profiler->find(null, null, $findLimit, null, null, null);
                
                foreach ($tokens as $token) {
                    // Skip if this token was already found
                    if (isset($foundProfileTokens[$token['token']])) {
                        continue;
                    }
                    
                    $profile = $profiler->loadProfile($token['token']);
                    if ($profile instanceof Profile) {
                        $profileData = ['profile' => $profile];
                        if ($appId) {
                            $profileData['appId'] = $appId;
                        }
                        $allProfiles[] = $profileData;
                        $foundProfileTokens[$profile->getToken()] = true; // Mark token as found
                    }
                }
            } catch (\Exception $e) {
                $io->warning(sprintf('Could not access profiler data at %s: %s', $profilerDir, $e->getMessage()));
            }
        }


        // --- Process Combined Results ---
        if (empty($allProfiles)) {
            $io->warning('No profiles found. Checked: ' . implode(' and ', array_unique($checkedPaths)));
            return Command::SUCCESS;
        }

        usort($allProfiles, fn ($a, $b) => $b['profile']->getTime() <=> $a['profile']->getTime());
        $limitedProfiles = array_slice($allProfiles, 0, $limit);

        $table = new Table($output);
        $hasAppId = !empty(array_filter($limitedProfiles, fn($p) => isset($p['appId'])));
        $headers = $hasAppId ? ['APP_ID', 'Token', 'IP', 'Method', 'URL', 'Time', 'Status'] : ['Token', 'IP', 'Method', 'URL', 'Time', 'Status'];
        $table->setHeaders($headers);

        foreach ($limitedProfiles as $profileData) {
            $profile = $profileData['profile'];
            $rowData = [
                $profile->getToken(),
                $profile->getIp(),
                $profile->getMethod(),
                $profile->getUrl(),
                date('Y-m-d H:i:s', $profile->getTime()),
                $profile->getStatusCode()
            ];
            if ($hasAppId) {
                array_unshift($rowData, $profileData['appId'] ?? '-');
            }
            $table->addRow($rowData);
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function executeShow(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $token = $input->getArgument('token');
        if (!$token) {
            $io->error('Token argument is required for show action');
            return Command::INVALID;
        }

        $profile = null;
        $foundAppId = null;
        $checkedPaths = [];
        
        // Find all profiler directories in the cache structure
        $profilerDirs = $this->findProfilerDirectories($io);
        
        if (empty($profilerDirs)) {
            $io->warning('No profiler directories found in the cache structure.');
            return Command::FAILURE;
        }
        
        // Try to find the profile in each profiler directory
        foreach ($profilerDirs as $profilerDir => $appId) {
            $checkedPaths[] = $profilerDir;
            
            if (!is_dir($profilerDir)) {
                continue;
            }
            
            $dsn = 'file:' . $profilerDir;
            try {
                $storage = new FileProfilerStorage($dsn);
                if ($storage->read($token)) {
                    $profiler = new Profiler($storage);
                    $profile = $profiler->loadProfile($token);
                    $foundAppId = $appId;
                    break; // Found the profile, no need to check other directories
                }
            } catch (\Exception $e) {
                $io->warning(sprintf('Could not access profiler data at %s: %s', $profilerDir, $e->getMessage()));
            }
        }


        // --- Process the found profile (if any) ---
        if (!$profile) {
            $io->error(sprintf('No profile found for token "%s". Checked: %s', $token, implode(' and ', array_unique($checkedPaths))));
            return Command::FAILURE;
        }

        $io->title(sprintf('Profile for "%s"%s', $token, $foundAppId ? " (App: {$foundAppId})" : ""));

        // --- Display Profile Info ---
        // ... (rest of show logic remains the same) ...
        $io->section('Profile Information');
        $defList = [
            ['Token' => $profile->getToken()],
            ['IP' => $profile->getIp()],
            ['Method' => $profile->getMethod()],
            ['URL' => $profile->getUrl()],
            ['Time' => date('Y-m-d H:i:s', $profile->getTime())],
            ['Status' => $profile->getStatusCode()]
        ];
        if ($foundAppId) {
            array_unshift($defList, ['App ID' => $foundAppId]);
        }
        $io->definitionList(...$defList);


        // --- Display Collector Info ---
        $collectorName = $input->getOption('collector');
        if ($collectorName) {
            $collector = $profile->getCollector($collectorName);
            if (!$collector) {
                $io->error(sprintf('No collector named "%s" found', $collectorName));
                return Command::FAILURE;
            }

            $io->section(sprintf('Collector: %s', $collectorName));
            $data = null;
            $dumpedData = null;

            // Always dump the collector object directly, as getData() is not standard.
            // The dumpData method should handle displaying the information appropriately.
            $dumpedData = $this->dumpData($collector);
            if ($dumpedData !== null && $data === null) {
                $io->text("Collector '{$collectorName}' data (dumped):\n" . $dumpedData);
            } elseif ($data === null && $dumpedData === null) {
                $io->warning(sprintf('Could not retrieve or represent data for collector "%s".', $collectorName));
            }
        } else {
            $io->section('Available Collectors');
            $collectors = $profile->getCollectors();
            $table = new Table($output);
            $table->setHeaders(['Collector', 'Data']);
            foreach ($collectors as $collector) {
                $table->addRow([$collector->getName(), sprintf('Use --collector=%s to view details', $collector->getName())]);
            }
            $table->render();
        }

        return Command::SUCCESS;
    }

    /**
     * Find all profiler directories in the cache structure
     * 
     * @param SymfonyStyle $io The IO interface for warnings
     * @return array An array of profiler directories with their associated app IDs
     */
    private function findProfilerDirectories(SymfonyStyle $io): array
    {
        $profilerDirs = [];
        $cacheDir = $this->cacheDir;
        $envName = basename($cacheDir); // e.g., 'dev'
        
        // Start with the direct path
        $directProfilerPath = $cacheDir . '/profiler';
        if (is_dir($directProfilerPath)) {
            // Check if this is an app-specific path
            $pathParts = explode('/', trim($cacheDir, '/'));
            $parentDirName = $pathParts[count($pathParts) - 2] ?? null;
            $appId = (strpos($parentDirName, '_') !== false) ? explode('_', $parentDirName)[0] : null;
            $profilerDirs[$directProfilerPath] = $appId;
        }
        
        // Find the base cache directory (var/cache)
        $baseCacheDir = dirname($cacheDir); // First level up
        if (strpos(basename($baseCacheDir), '_') !== false) {
            $baseCacheDir = dirname($baseCacheDir); // Second level up if needed
        }
        
        // Use Finder to locate all profiler directories
        try {
            if (is_dir($baseCacheDir)) {
                $finder = new Finder();
                $finder->directories()
                    ->in($baseCacheDir)
                    ->path('/' . preg_quote($envName, '/') . '\/profiler$/')
                    ->depth('< 4'); // Limit depth to avoid excessive searching
                
                foreach ($finder as $dir) {
                    $profilerPath = $dir->getRealPath();
                    
                    // Skip if we already have this path
                    if (isset($profilerDirs[$profilerPath])) {
                        continue;
                    }
                    
                    // Extract app ID from path if possible
                    $pathParts = explode('/', $profilerPath);
                    $appId = null;
                    
                    // Look for app_* pattern in the path
                    foreach ($pathParts as $part) {
                        if (strpos($part, '_') !== false && preg_match('/^([^_]+)_/', $part, $matches)) {
                            $appId = $matches[1];
                            break;
                        }
                    }
                    
                    $profilerDirs[$profilerPath] = $appId;
                }
            }
        } catch (\Exception $e) {
            $io->warning('Error searching for profiler directories: ' . $e->getMessage());
        }
        
        return $profilerDirs;
    }
    


    private function dumpData($variable): ?string
    {
        try {
            $cloner = new VarCloner();
            $dumper = new CliDumper();
            $output = fopen('php://memory', 'r+');
            $dumper->dump($cloner->cloneVar($variable), $output);
            rewind($output);
            $dump = stream_get_contents($output);
            fclose($output);
            return $dump;
        } catch (\Exception $e) {
            return "Error during data dumping: " . $e->getMessage();
        }
    }
}
