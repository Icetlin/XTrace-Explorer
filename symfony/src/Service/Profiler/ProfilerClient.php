<?php

declare(strict_types=1);

namespace App\Service\Profiler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * High-level facade combining ProfilerHttpClient + DbPanelParser + DbAnalyzer.
 *
 * Returns plain arrays — DB persistence is the controller's job. This class
 * is the equivalent of "profiler db-analyze <token>" in the Python CLI.
 */
final class ProfilerClient
{
    public function __construct(
        public readonly ProfilerConfig $config,
        private readonly ProfilerHttpClient $http,
        private readonly DbPanelParser $parser,
        private readonly DbAnalyzer $analyzer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Fetch a profiler DB panel and return both the parsed queries and the
     * aggregated analysis. This is what the controller will persist.
     *
     * @return array{panel: array, analysis: array, html_size: int}
     */
    public function fetchAndAnalyze(string $token, string $type = 'request'): array
    {
        $url = $this->http->buildUrl($token, panel: 'db', type_: $type);
        $html = $this->http->fetchPanel($token, panel: 'db', type: $type);
        $this->logger->info('[ProfilerClient] fetched DB panel for {token}, {bytes} bytes', [
            'token' => $token, 'bytes' => strlen($html),
        ]);
        $panel = $this->parser->parse($html, $url);
        $analysis = $this->analyzer->analyze($panel);
        return ['panel' => $panel, 'analysis' => $analysis, 'html_size' => strlen($html)];
    }

    /**
     * Fetch and analyse — but for a single query (faster, smaller payload).
     *
     * @return array{n:int, sql:string, time:string, time_ms:float, params:array, backtrace:array}
     */
    public function fetchQuery(string $token, int $n, string $type = 'request'): array
    {
        // Single-query mode is still the same DB panel HTML — the parser picks
        // up query $n by index. (Symfony's db-query?n= view doesn't return a
        // standalone HTML; the panel is the canonical source.)
        $data = $this->fetchAndAnalyze($token, $type);
        foreach ($data['panel']['queries'] as $q) {
            if ((int) ($q['n'] ?? 0) === $n) {
                return $q;
            }
        }
        throw new ProfilerException(sprintf('Query #%d not found in profiler panel for token %s', $n, $token));
    }

    /**
     * List recent profiler tokens from the index page.
     *
     * @return list<array{token:string,method:?string,url:?string,time:?int,status:?int,ip:?string}>
     */
    public function listRecent(int $limit = 50): array
    {
        $html = $this->http->fetchRecent($limit);
        return RecentListParser::parse($html);
    }

    /**
     * Cheap sanity-check that the profiler endpoint is reachable and auth is
     * working. Returns ["ok" => true, "recent_count" => N] or throws.
     */
    public function ping(): array
    {
        $recent = $this->listRecent(5);
        return ['ok' => true, 'recent_count' => count($recent)];
    }

    /**
     * Fetch the "request" panel for a candidate token (used by AutoFinder for
     * scoring). Returns the raw HTML — the controller parses it.
     */
    public function fetchRequestPanel(string $token): string
    {
        return $this->http->fetchPanel($token, panel: 'request', type: 'request');
    }
}