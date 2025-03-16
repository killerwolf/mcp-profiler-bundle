<?php

namespace MCP\ServerBundle\Command;

use MCP\ServerBundle\Service\MCPServerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class RunMCPServerCommand extends Command
{
    protected static string $defaultName = 'mcp:server:run';
    protected static string $defaultDescription = 'Run the MCP server';

    private MCPServerService $mcpServerService;

    public function __construct(MCPServerService $mcpServerService)
    {
        parent::__construct();
        $this->mcpServerService = $mcpServerService;
    }

    protected function configure()
    {
        $this
            ->setDescription(self::$defaultDescription)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success('Starting MCP Server...');

        try {
            $this->mcpServerService
                ->initialize()
                ->registerTools()
                ->registerResources()
                ->run();
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error running MCP Server: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}