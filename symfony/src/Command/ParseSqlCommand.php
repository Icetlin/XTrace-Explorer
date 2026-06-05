<?php

namespace App\Command;

use App\Service\TraceParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'trace:parse-sql')]
class ParseSqlCommand extends Command
{
    public function __construct(private readonly TraceParser $parser)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('xt_file', InputArgument::REQUIRED)
            ->addArgument('out_file', InputArgument::REQUIRED)
            ->addArgument('toc_file', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $xtFile  = $input->getArgument('xt_file');
        $outFile = $input->getArgument('out_file');
        $tocFile = $input->getArgument('toc_file');

        $toc = file_exists($tocFile) ? (json_decode(file_get_contents($tocFile), true) ?? []) : [];
        $queries = $this->parser->extractSqlQueriesPublic($xtFile, $toc);

        file_put_contents($outFile, json_encode($queries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
