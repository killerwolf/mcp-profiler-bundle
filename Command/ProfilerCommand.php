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

#[AsCommand(name: 'mcp:profiler', description: 'Interact with Symfony profiler')]
class ProfilerCommand extends Command
{
    private Profiler $profiler;

    public function __construct(Profiler $profiler)
    {
        parent::__construct();
        $this->profiler = $profiler;
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
        $io->title(sprintf('Listing the %d most recent profiles', $limit));

        $tokens = $this->profiler->find(null, null, $limit, null, null, null);

        if (count($tokens) === 0) {
            $io->warning('No profiles found');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Token', 'IP', 'Method', 'URL', 'Time', 'Status']);

        foreach ($tokens as $token) {
            $profile = $this->profiler->loadProfile($token['token']);
            if ($profile) {
                $table->addRow([
                    $profile->getToken(),
                    $profile->getIp(),
                    $profile->getMethod(),
                    $profile->getUrl(),
                    date('Y-m-d H:i:s', $profile->getTime()),
                    $profile->getStatusCode()
                ]);
            }
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

        $profile = $this->profiler->loadProfile($token);
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
            if (method_exists($collector, 'getData')) {
                $data = $collector->getData();

                if (is_array($data)) {
                    $this->displayArrayData($data, $output, $io);
                } else {
                    $io->text(var_export($data, true));
                }
            } else {
                $io->warning(sprintf('Collector "%s" does not have a standard getData() method. Cannot display raw data.', $collectorName));
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
}
