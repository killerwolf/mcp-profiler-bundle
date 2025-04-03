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
    private ?Profiler $profiler; // Keep for purge?
    // $cacheDir is the env-specific cache dir, e.g., /path/to/var/cache/dev or /path/to/var/cache/APP_ID/dev
    private string $cacheDir;

    public function __construct(?Profiler $profiler, string $cacheDir)
    {
        parent::__construct();
        $this->profiler = $profiler;
        $this->cacheDir = $cacheDir;
    }

    protected function configure(): void
    {
        // ... (configure method remains the same) ...
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform (list, show, purge)')
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

  * <info>purge</info>: Delete all profiler data (currently uses default profiler)
    <info>%command.full_name% purge</info>
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
                case 'purge':
                    // TODO: Refactor purge to handle multi/single app paths if needed
                    return $this->executePurge($input, $output, $io);
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

        // --- Path Derivation ---
        $envCacheDir = $this->cacheDir; // e.g., var/cache/dev or var/cache/APP_ID/dev
        $envName = basename($envCacheDir); // e.g., 'dev'
        $parentOfEnvCacheDir = dirname($envCacheDir); // e.g., var/cache or var/cache/APP_ID
        // Determine the correct base directory for multi-app search (should be var/cache)
        $multiAppBaseSearchDir = $parentOfEnvCacheDir;
        if (strpos(basename($parentOfEnvCacheDir), '_') !== false) {
            $multiAppBaseSearchDir = dirname($parentOfEnvCacheDir); // Go one level up if parent is app-specific
        }

        // --- 1. Check Direct Path ---
        $directProfilerPath = $envCacheDir . '/profiler';
        $checkedPaths[] = $directProfilerPath; // Record check attempt

        if (is_dir($directProfilerPath)) {
            $dsn = 'file:' . $directProfilerPath;
            try {
                $storage = new FileProfilerStorage($dsn);
                $profiler = new Profiler($storage);
                $tokens = $profiler->find(null, null, $limit, null, null, null);

                $pathParts = explode('/', trim($this->cacheDir, '/'));
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
                        $foundProfileTokens[$profile->getToken()] = true; // Mark token as found
                    }
                }
            } catch (\Exception $e) {
                $io->warning(sprintf('Could not access profiler data at %s: %s', $directProfilerPath, $e->getMessage()));
            }
        } else {
             $checkedPaths[count($checkedPaths)-1] .= ' (not found)';
        }

        // --- 2. Check Multi-App Structure in Correct Base Cache Dir ---
        $multiAppPathPattern = $multiAppBaseSearchDir . '/*_*/' . $envName . '/profiler';
        $checkedPaths[] = $multiAppPathPattern; // Record check attempt

        try {
            if (is_dir($multiAppBaseSearchDir)) {
                $appIdDirs = $finder->directories()->in($multiAppBaseSearchDir)->depth('== 0')->name('*_*');

                if ($appIdDirs->hasResults()) {
                    $appCount = $appIdDirs->count();
                    foreach ($appIdDirs as $appIdDir) {
                        $appIdDirName = $appIdDir->getFilename();
                        $appId = explode('_', $appIdDirName)[0];
                        $profilerDir = $appIdDir->getRealPath() . '/' . $envName . '/profiler';

                        // Skip if this is the same as the direct path we already checked
                        if ($profilerDir === $directProfilerPath) {
                            continue;
                        }

                        $dsn = 'file:' . $profilerDir;
                        if (!is_dir($profilerDir)) continue;

                        try {
                            $storage = new FileProfilerStorage($dsn);
                            $tempProfiler = new Profiler($storage);
                            $findLimit = $appCount > 0 ? ceil($limit / $appCount) + 5 : $limit;
                            $tokens = $tempProfiler->find(null, null, $findLimit, null, null, null);

                            foreach ($tokens as $token) {
                                // Skip if this token was already found via the direct path
                                if (isset($foundProfileTokens[$token['token']])) {
                                    continue;
                                }
                                $profile = $tempProfiler->loadProfile($token['token']);
                                if ($profile instanceof Profile) {
                                     $allProfiles[] = ['appId' => $appId, 'profile' => $profile];
                                     $foundProfileTokens[$profile->getToken()] = true; // Mark token as found
                                }
                            }
                        } catch (\Exception $e) {
                            $io->warning(sprintf('Could not access profiler data for %s: %s', $appId, $e->getMessage()));
                        }
                    }
                } else {
                     $checkedPaths[count($checkedPaths)-1] .= ' (no app dirs found)';
                }
            } else {
                 $checkedPaths[count($checkedPaths)-1] .= ' (base dir not found)';
            }
        } catch (\InvalidArgumentException $e) {
             $io->warning('Error accessing base cache directory for multi-app check: ' . $e->getMessage());
             $checkedPaths[count($checkedPaths)-1] .= ' (error accessing base dir)';
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

        // --- Path Derivation ---
        $envCacheDir = $this->cacheDir;
        $envName = basename($envCacheDir);
        $parentOfEnvCacheDir = dirname($envCacheDir);
        $multiAppBaseSearchDir = $parentOfEnvCacheDir;
        if (strpos(basename($parentOfEnvCacheDir), '_') !== false) {
            $multiAppBaseSearchDir = dirname($parentOfEnvCacheDir);
        }

        $finder = new Finder();

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
                    $pathParts = explode('/', trim($this->cacheDir, '/'));
                    $parentDirName = $pathParts[count($pathParts) - 2] ?? null;
                    $foundAppId = (strpos($parentDirName, '_') !== false) ? explode('_', $parentDirName)[0] : null;
                }
            } catch (\Exception $e) {
                 $io->warning(sprintf('Could not access profiler data at %s: %s', $directProfilerPath, $e->getMessage()));
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
                 $io->warning('Error accessing base cache directory for multi-app check: ' . $e->getMessage());
                 // $checkedPaths[count($checkedPaths)-1] .= ' (error accessing base dir)';
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

            if (method_exists($collector, 'getData')) {
                try {
                    $data = $collector->getData();
                    if (is_array($data)) {
                        $this->displayArrayData($data, $output, $io);
                    } else {
                        $io->text(var_export($data, true));
                    }
                } catch (\Exception $e) {
                    $dumpedData = $this->dumpData($collector);
                    $data = null;
                }
            } else {
                $dumpedData = $this->dumpData($collector);
            }

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

    // TODO: Refactor purge to handle multi/single app paths based on derived paths
    private function executePurge(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        // ... (purge logic remains unchanged for now) ...
        if (!$this->profiler) {
             $io->error('Default profiler service not available for purge operation. Purge needs refactoring for multi/single app support.');
             return Command::FAILURE;
        }
        if (!$io->confirm('Are you sure you want to purge profiler data using the default profiler service?', false)) {
            $io->note('Operation cancelled');
            return Command::SUCCESS;
        }
        $this->profiler->purge();
        $io->success('Profiler data (default storage only) has been purged.');
        return Command::SUCCESS;
    }

    private function displayArrayData(array $data, OutputInterface $output, SymfonyStyle $io, int $level = 0): void
    {
        // ... (displayArrayData remains the same) ...
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $io->writeln(str_repeat('  ', $level) . sprintf('%s:', $key));
                $this->displayArrayData($value, $output, $io, $level + 1);
            } else {
                $io->writeln(str_repeat('  ', $level) . sprintf('%s: %s', $key, is_scalar($value) ? $value : var_export($value, true)));
            }
        }
    }

    private function dumpData($variable): ?string
    {
        // ... (dumpData remains the same) ...
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
