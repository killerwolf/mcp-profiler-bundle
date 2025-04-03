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
use Symfony\Component\HttpKernel\Profiler\Profile; // Import Profile class
use Symfony\Component\HttpKernel\KernelInterface; // Or just get kernel.cache_dir parameter
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

#[AsCommand(name: 'mcp:profiler', description: 'Interact with Symfony profiler')]
class ProfilerCommand extends Command
{
    private Profiler $profiler;
    private string $cacheDir;

    // Inject kernel.cache_dir. Binding might need configuration if not autowired.
    public function __construct(Profiler $profiler, string $cacheDir)
    {
        parent::__construct();
        $this->profiler = $profiler;
        $this->cacheDir = $cacheDir; // e.g., /path/to/project/var/cache/APP_ID/dev
    }

    protected function configure(): void
    {
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
    
  * <info>purge</info>: Delete all profiler data
    <info>%command.full_name% purge</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        try {
            switch ($action) {
                case 'list':
                    return $this->executeList($input, $output, $io);
                case 'show':
                    return $this->executeShow($input, $output, $io);
                case 'purge':
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
        $io->title(sprintf('Listing the %d most recent profiles across all applications', $limit));

        $baseCacheDir = dirname(dirname($this->cacheDir)); // Get /path/to/project/var/cache
        $envDirName = basename($this->cacheDir); // Get 'dev' (or current env)

        $finder = new Finder();
        // Find directories like 'fac_FAC', 'voi_VOI' etc. in var/cache/
        $appIdDirs = $finder->directories()->in($baseCacheDir)->depth('== 0')->name('*_*');

        $allProfiles = [];

        if (!$appIdDirs->hasResults()) {
            // Fallback or handle case where APP_ID structure isn't used?
            // For now, assume structure exists if command is relevant.
            $io->warning('Could not find application cache directories in ' . $baseCacheDir);
            // Maybe try the default profiler?
            // $tokens = $this->profiler->find(null, null, $limit, null, null, null);
            // ... load profiles from default profiler ...
        } else {
            foreach ($appIdDirs as $appIdDir) {
                $appIdDirName = $appIdDir->getFilename();
                // Extract App ID (e.g., 'fac' from 'fac_FAC') - adjust logic if needed
                $appId = explode('_', $appIdDirName)[0];

                $profilerDir = $appIdDir->getRealPath() . '/' . $envDirName . '/profiler';
                $dsn = 'file:' . $profilerDir;

                if (!is_dir($profilerDir)) {
                    continue; // Skip if profiler dir doesn't exist for this app/env
                }

                try {
                    $storage = new FileProfilerStorage($dsn);
                    // Find tokens - fetch more initially to sort accurately later
                    // Create a temporary profiler for this specific storage
                    $tempProfiler = new Profiler($storage);
                    $tokens = $tempProfiler->find(null, null, $limit * $appIdDirs->count(), null, null, null);

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
                    $io->warning(sprintf('Could not access profiler data for %s: %s', $appId, $e->getMessage()));
                }
            }
        }

        if (empty($allProfiles)) {
            $io->warning('No profiles found');
            return Command::SUCCESS;
        }

        // Sort all found profiles by time, descending
        // Adjust sorting to access the profile object within the array
        usort($allProfiles, fn ($a, $b) => $b['profile']->getTime() <=> $a['profile']->getTime());

        // Limit to the requested number
        $limitedProfiles = array_slice($allProfiles, 0, $limit);

        $table = new Table($output);
        $table->setHeaders(['APP_ID', 'Token', 'IP', 'Method', 'URL', 'Time', 'Status']);

        foreach ($limitedProfiles as $profileData) {
            $profile = $profileData['profile']; // Extract profile object
            $table->addRow([
                $profileData['appId'], // Display the stored App ID
                $profile->getToken(),
                $profile->getIp(),
                $profile->getMethod(),
                $profile->getUrl(),
                date('Y-m-d H:i:s', $profile->getTime()),
                $profile->getStatusCode()
            ]);
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
        // --- Modification Start ---
        $profile = null;
        $foundAppId = null;

        $baseCacheDir = dirname(dirname($this->cacheDir)); // Get /path/to/project/var/cache
        $envDirName = basename($this->cacheDir); // Get 'dev' (or current env)

        $finder = new Finder();
        $appIdDirs = $finder->directories()->in($baseCacheDir)->depth('== 0')->name('*_*');

        foreach ($appIdDirs as $appIdDir) {
            $profilerDir = $appIdDir->getRealPath() . '/' . $envDirName . '/profiler';
            $dsn = 'file:' . $profilerDir;

            if (!is_dir($profilerDir)) {
                continue;
            }

            try {
                $storage = new FileProfilerStorage($dsn);
                // Check if the token exists in this storage
                if ($storage->read($token)) {
                    $tempProfiler = new Profiler($storage);
                    $profile = $tempProfiler->loadProfile($token);
                    $foundAppId = explode('_', $appIdDir->getFilename())[0]; // Extract App ID
                    break; // Found the profile, exit loop
                }
            } catch (\Exception $e) {
                // Ignore errors for individual storages, continue searching
            }
        }
        // --- Modification End ---

        if (!$profile) {
            $io->error(sprintf('No profile found for token "%s"', $token));
            return Command::FAILURE;
        }

        $io->title(sprintf('Profile for "%s"', $token));

        $io->section('Profile Information');
        $io->definitionList(
            ['Token' => $profile->getToken()],
            ['IP' => $profile->getIp()],
            ['Method' => $profile->getMethod()],
            ['URL' => $profile->getUrl()],
            ['Time' => date('Y-m-d H:i:s', $profile->getTime())],
            ['Status' => $profile->getStatusCode()]
        );

        $collectorName = $input->getOption('collector');
        if ($collectorName) {
            // Display specific collector
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
                        return Command::SUCCESS;
                    } else {
                        $io->text(var_export($data, true));
                        return Command::SUCCESS;
                    }
                } catch (\Exception $e) {
                    $dumpedData = $this->dumpData($collector);
                    $data = null;
                }
            } else {
                $dumpedData = $this->dumpData($collector);
            }

            if ($dumpedData !== null) {
                $io->text("Collector '{$collectorName}' data (dumped):\n" . $dumpedData);
            } else {
                $io->warning(sprintf('Could not retrieve or represent data for collector "%s".', $collectorName));
            }
        } else {
            // List available collectors
            $io->section('Available Collectors');
            $collectors = $profile->getCollectors();

            $table = new Table($output);
            $table->setHeaders(['Collector', 'Data']);

            foreach ($collectors as $collector) {
                $table->addRow([
                    $collector->getName(),
                    sprintf('Use --collector=%s to view details', $collector->getName())
                ]);
            }

            $table->render();
        }

        return Command::SUCCESS;
    }

    private function executePurge(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        if (!$io->confirm('Are you sure you want to purge all profiler data?', false)) {
            $io->note('Operation cancelled');
            return Command::SUCCESS;
        }

        $this->profiler->purge();
        $io->success('All profiler data has been purged');

        return Command::SUCCESS;
    }

    private function displayArrayData(array $data, OutputInterface $output, SymfonyStyle $io, int $level = 0): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $io->writeln(str_repeat(' ', $level * 2) . sprintf('%s:', $key));
                $this->displayArrayData($value, $output, $io, $level + 1);
            } else {
                $io->writeln(str_repeat(' ', $level * 2) . sprintf('%s: %s', $key, is_scalar($value) ? $value : var_export($value, true)));
            }
        }
    }

    /**
     * Helper method to dump data using VarDumper
     */
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
