<?php

declare(strict_types=1);

namespace App\Service\Profiler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin HTTP client for the Symfony WebProfilerBundle.
 *
 * Uses raw cURL instead of Symfony HttpClient because the dev profiler
 * endpoint is a self-signed HTTPS server, and the cURL transport in
 * some PHP-FPM setups was silently dropping `verify_peer=false`.
 *
 * Pure transport — knows nothing about HTML parsing. See DbPanelParser for that.
 */
final class ProfilerHttpClient
{
    public function __construct(
        public readonly ProfilerConfig $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Fetch a profiler page (typically the DB panel) and return the raw HTML.
     *
     * @param array<string,string|int> $extraQuery
     */
    public function fetchPanel(string $token, string $panel = 'db', string $type = 'request', array $extraQuery = []): string
    {
        $url = $this->buildUrl($token, panel: $panel, type_: $type, extraQuery: $extraQuery);
        return $this->doGet($url);
    }

    /**
     * Fetch the profiler index (lists recent tokens).
     * Returns the raw HTML; AutoFinder parses it.
     */
    public function fetchRecent(int $limit = 50): string
    {
        if ($this->config->baseUrl === null) {
            throw new ProfilerException('baseUrl is not configured');
        }
        $base = rtrim($this->config->baseUrl, '/');
        $url = $base . '/_profiler?' . http_build_query(['limit' => $limit, 'panel' => 'request']);
        return $this->doGet($url);
    }

    /**
     * Build a /_profiler/<token>?panel=...&type=... URL. Pure function — no IO.
     *
     * @param array<string,string|int> $extraQuery
     */
    public function buildUrl(
        string $token,
        ?string $panel = null,
        ?string $type_ = null,
        ?int $query = null,
        array $extraQuery = [],
    ): string {
        if ($this->config->baseUrl === null) {
            throw new ProfilerException('baseUrl is not configured');
        }
        $base = rtrim($this->config->baseUrl, '/');
        $path = '/_profiler/' . $token;
        $qs = [];
        if ($panel !== null) $qs['panel'] = $panel;
        if ($type_ !== null) $qs['type'] = $type_;
        if ($query !== null) $qs['query'] = $query;
        $qs += $extraQuery;
        $queryString = http_build_query($qs);
        return $base . $path . ($queryString !== '' ? '?' . $queryString : '');
    }

    /**
     * Single GET helper — configures cURL with the timeout/TLS options from
     * ProfilerConfig and returns the body or throws ProfilerException.
     */
    private function doGet(string $url): string
    {
        $this->logger->debug('[ProfilerHttpClient] GET {url}', ['url' => $url]);

        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->config->timeoutSec > 0 ? $this->config->timeoutSec : 600,
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
        ];
        if ($this->config->insecure) {
            // Self-signed dev certs — skip both peer and host verification.
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new ProfilerException(
                sprintf('Profiler request failed: %s (%s)', $error, $url),
                null,
                null,
            );
        }
        if ($status >= 400) {
            throw new ProfilerException(
                sprintf('Profiler HTTP %d for %s: %s', $status, $url, substr((string) $body, 0, 500)),
                $status,
            );
        }
        return (string) $body;
    }

    /**
     * Fetch the request panel for a specific token (used by AutoFinder to
     * score candidates). Returns the raw HTML.
     */
    public function fetchRequestPanel(string $token): string
    {
        return $this->fetchPanel($token, 'request', 'request');
    }
}
