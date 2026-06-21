<?php

declare(strict_types=1);

namespace App\Service\Profiler;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses the HTML of /_profiler (the recent profiler index) into a list
 * of token entries with method, url, time, status, ip.
 *
 * The modern Symfony WebProfilerBundle renders the list as a <table> with
 * one <tr> per token and these columns in order:
 *
 *   1. Status  — <td class="text-center"><span class="label status-…">200</span></td>
 *   2. IP      — <td>…<a href="…/search/results?ip=…">172.20.0.8</a>…</td>
 *   3. Method  — <td><span class="nowrap">GET <a …>GET</a></span></td>
 *   4. URL     — <td class="break-long-words">https://host/path<a …>…</a></td>
 *   5. Time    — <td class="text-small"><time datetime="ISO8601">…</time>…</td>
 *   6. Token   — <td class="nowrap"><a href="/_profiler/HEX">HEX</a></td>
 *
 * All fields except token can be null when the row format differs (older
 * profiler versions, or a row that only carries the token link).
 */
final class RecentListParser
{
    /**
     * @return list<array{token:string,method:?string,url:?string,time:?int,status:?int,ip:?string}>
     */
    public static function parse(string $html): array
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'UTF-8');

        $rows = [];
        $seen = [];

        $crawler->filter('table tbody tr')->each(function (Crawler $tr) use (&$rows, &$seen) {
            $tds = $tr->filter('td');
            if ($tds->count() < 2) return;

            // Token: scan all <a href="/_profiler/HEX"> for a bare HEX (no path, no query).
            // (The token cell always exists; method/url cells link to search/results, not the bare token.)
            $token = null;
            $tds->each(function (Crawler $td) use (&$token) {
                if ($token !== null) return;
                $td->filter('a[href*="/_profiler/"]')->each(function (Crawler $a) use (&$token) {
                    if ($token !== null) return;
                    $href = $a->attr('href') ?? '';
                    if (preg_match('#/_profiler/([0-9a-f]{6,})(?![/0-9a-z])#i', $href, $m)
                        && strpos($href, '/search/') === false) {
                        $token = $m[1];
                    }
                });
            });
            if ($token === null) return;
            if (isset($seen[$token])) return;
            $seen[$token] = true;

            // Status: <span class="label status-…">N</span> or <span class="status-N">N</span>
            $status = null;
            $statusNode = $tr->filter('.label.status-success, .label.status-warning, .label.status-error, [class*="status-"]')->first();
            if ($statusNode->count() > 0) {
                $t = trim($statusNode->text('', true));
                if (ctype_digit($t)) $status = (int) $t;
            }

            // IP: first <a href="…ip=…"> inside the row (search-by-ip link wraps the value).
            // The visible IP is a sibling text node of the <a>, so we read it from the href query.
            $ip = null;
            $tr->filter('a[href*="ip="]')->each(function (Crawler $a) use (&$ip) {
                if ($ip !== null) return;
                parse_str(parse_url($a->attr('href') ?? '', PHP_URL_QUERY) ?? '', $qs);
                if (!empty($qs['ip'])) $ip = (string) $qs['ip'];
            });

            // Method: the search-by-method link's href carries `method=GET` etc. The link's
            // own text is just an icon; the method label is a sibling text node of the <a>.
            $method = null;
            $tr->filter('a[href*="method="]')->each(function (Crawler $a) use (&$method) {
                if ($method !== null) return;
                parse_str(parse_url($a->attr('href') ?? '', PHP_URL_QUERY) ?? '', $qs);
                if (!empty($qs['method']) && preg_match('#^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)$#i', (string) $qs['method'])) {
                    $method = strtoupper((string) $qs['method']);
                }
            });

            // URL: <a href="…url=…">…</a> inside the row; the visible text of the cell
            // includes the link, but the link text is just an icon — use the cell text
            // up to the link instead, and strip trailing host noise.
            $url = null;
            $urlCell = $tr->filter('td.break-long-words, td')->eq(3);
            if ($urlCell->count() > 0) {
                // Copy cell and remove anchor children, then read the leftover text.
                $cellText = $urlCell->text('', true);
                $clean = trim(preg_replace('#\s+#', ' ', $cellText));
                if ($clean !== '' && filter_var($clean, FILTER_VALIDATE_URL) !== false) {
                    $url = $clean;
                } elseif ($clean !== '') {
                    // Fallback: take everything up to the search icon link.
                    $url = $clean;
                }
            }
            // Prefer the explicit search-by-url link's url= query param when present.
            $tr->filter('a[href*="url="]')->each(function (Crawler $a) use (&$url) {
                $href = $a->attr('href') ?? '';
                if (preg_match('#[?&]url=([^&]+)#', $href, $m)) {
                    $url = urldecode($m[1]);
                }
            });

            // Time: <time datetime="ISO8601">…</time> — first occurrence.
            $time = null;
            $tr->filter('time[datetime]')->each(function (Crawler $t) use (&$time) {
                if ($time !== null) return;
                $iso = $t->attr('datetime') ?? '';
                $ts = strtotime($iso);
                if ($ts !== false) $time = $ts;
            });

            $rows[] = [
                'token'  => $token,
                'method' => $method,
                'url'    => $url,
                'time'   => $time,
                'status' => $status,
                'ip'     => $ip,
            ];
        });

        return $rows;
    }
}
