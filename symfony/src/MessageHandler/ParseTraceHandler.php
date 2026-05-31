<?php

namespace App\MessageHandler;

use App\Message\ParseTraceMessage;
use App\Repository\TraceFileRepository;
use App\Service\TraceParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ParseTraceHandler
{
    public function __construct(
        private readonly TraceFileRepository $repo,
        private readonly TraceParser $parser,
        private readonly EntityManagerInterface $em,
        private readonly string $tracesDir,
    ) {}

    public function __invoke(ParseTraceMessage $message): void
    {
        $traceFile = $this->repo->find($message->traceFileId);
        if (!$traceFile) return;

        $xtPath = $this->tracesDir . '/' . $traceFile->getId() . '/trace.xt';

        try {
            $this->parser->parse($traceFile, $xtPath);
        } catch (\Throwable $e) {
            $traceFile->setStatus('error')->setErrorMessage($e->getMessage());
            $this->em->flush();
        }
    }
}
