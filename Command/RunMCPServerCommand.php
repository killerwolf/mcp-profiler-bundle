<?php

namespace Killerwolf\MCPProfilerBundle\Command;

use Killerwolf\MCPProfilerBundle\Service\MCPServerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'mcp:server:run', description: 'Run the MCP server')]
class RunMCPServerCommand extends Command
{

    private MCPServerService $mcpServerService;

    public function __construct(MCPServerService $mcpServerService)
    {
        parent::__construct();
        $this->mcpServerService = $mcpServerService;
    }

    protected function configure()
    {
        $this
            ->setHelp('This command allows you to run the MCP server...');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Pass input and output to the run method
            $this->mcpServerService
                ->initialize()
                ->run($input, $output); // Pass input and output
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io = new SymfonyStyle($input, $output);
            $io->error('Error running MCP Server: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}