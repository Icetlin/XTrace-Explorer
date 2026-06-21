<?php

declare(strict_types=1);

namespace App\Service\Profiler;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses the HTML of a Symfony WebProfiler DB panel into a structured list of
 * queries, each carrying SQL, time, parameters, and full backtrace.
 *
 * Output shape (matches what the Python symfony_profiler_client returns so
 * the analysis layer can stay agnostic of language):
 *
 *   [
 *     'url' => 'https://host/_profiler/TOKEN?panel=db&type=request',
 *     'queries' => [
 *       [
 *         'n'         => 1,
 *         'id'        => 'queryNo-c0-1',
 *         'sql'       => 'SELECT …',
 *         'time'      => '1.2 ms',          // raw, e.g. "1.2 ms" or "345 micros"
 *         'time_ms'   => 1.2,               // parsed float
 *         'params'    => [':foo' => '42'],
 *         'explain'   => '?_profiler/...&page=explain&query=1'  or null,
 *         'backtrace' => [
 *            [
 *              'n'        => 1,            // frame number, top of stack
 *              'class'    => 'Foo\\Bar',
 *              'method'   => 'doX',
 *              'call'     => 'Foo\\Bar->doX',
 *              'line'     => 42,
 *              'file'     => '/var/www/html/src/Foo.php',   // in-container path
 *              'file_url' => 'file:///var/www/html/src/Foo.php#L42',
 *              'is_vendor'=> false,
 *              'is_src'   => true,
 *              'host_path'=> '/home/me/proj/src/Foo.php',   // translated
 *            ],
 *            ...
 *         ],
 *       ],
 *       ...
 *     ],
 *   ]
 */
final class DbPanelParser
{
    /** Match "Doctrine\DBAL\Foo->bar (line 42)" inside a cell. */
    private const FRAME_RE = '/^(.+?)->\s*(\w+)\s*\(line\s+(\d+)\)\s*$/';

    public function __construct(
        public readonly ProfilerConfig $config,
    ) {}

    public function parse(string $html, string $url = ''): array
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'UTF-8');

        $queries = [];
        $crawler->filter('tr[id^="queryNo-"]')->each(function (Crawler $tr) use (&$queries) {
            $tds = $tr->filter('td');
            if ($tds->count() < 3) return;

            try {
                $n = (int) trim($tds->eq(0)->text('', true));
            } catch (\Throwable) {
                return;
            }

            $timeText = trim($tds->eq(1)->text('', true));
            $sqlCell  = $tds->eq(2);

            // SQL is in <pre class="highlight-sql"> (modern profiler) or first <pre>.
            $sql = '';
            $sqlPre = $sqlCell->filter('pre');
            if ($sqlPre->count() > 0) {
                $sql = trim($sqlPre->first()->text('', true));
            }
            if ($sql === '') {
                $sql = trim($sqlCell->text('', true));
            }

            // Parameters sit in a <div> with text starting "Parameters : [...]"
            $params = [];
            $sqlCell->filter('div')->each(function (Crawler $div) use (&$params) {
                $txt = trim($div->text('', true));
                if (preg_match('/Parameters\s*[:：]\s*\[(.+)\]\s*$/s', $txt, $m)) {
                    $params = $this->parseParams($m[1]);
                }
            });

            // Explain link
            $explainUrl = null;
            $sqlCell->filter('a[href*="page=explain"]')->each(function (Crawler $a) use (&$explainUrl) {
                if ($explainUrl === null) {
                    $explainUrl = $a->attr('href');
                }
            });

            // Backtrace lives in a hidden <div> inside the SQL cell.
            $backtrace = [];
            $sqlCell->filter('div.hidden')->each(function (Crawler $hidden) use (&$backtrace) {
                if ($backtrace !== []) return; // first match wins
                $bt = $this->parseBacktrace($hidden);
                if ($bt !== []) $backtrace = $bt;
            });

            $queries[] = [
                'n'         => $n,
                'id'        => $tr->attr('id') ?? ('queryNo-c0-' . $n),
                'sql'       => $sql,
                'time'      => $timeText,
                'time_ms'   => self::parseTimeMs($timeText),
                'params'    => $params,
                'explain'   => $explainUrl,
                'backtrace' => $backtrace,
            ];
        });

        return ['url' => $url, 'queries' => $queries];
    }

    /**
     * Normalise the inner of a Parameters block: "[1 => 'a', 2 => 'b']" or
     * "['a', 'b']" → array of strings (positional) or [name => value].
     *
     * We do best-effort parsing. Doctrine escapes commas inside quoted strings;
     * if our naive split breaks, we keep raw text as the single element.
     */
    private function parseParams(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];

        // If looks like "key => value, key => value" → assoc.
        if (preg_match('/^\d+\s*=>/', $raw) || preg_match('/[\'"]\w+[\'"]\s*=>/', $raw)) {
            // assoc
            $assoc = [];
            // Match "key => value" where key is quoted string or integer.
            if (preg_match_all(
                "/(?:^|,)\s*(?:(\d+|'[^']*'|\"[^\"]*\")\s*=>\s*)?((?:'(?:\\\\.|[^'\\\\])*'|\"(?:\\\\.|[^\"\\\\])*\"|[^,]+))/",
                $raw,
                $m,
                PREG_SET_ORDER,
            )) {
                foreach ($m as $pair) {
                    $k = isset($pair[1]) && $pair[1] !== '' ? trim($pair[1], "'\"") : null;
                    $v = trim($pair[2]);
                    if ($k !== null) $assoc[$k] = $v; else $assoc[] = $v;
                }
            }
            return $assoc;
        }

        // Positional: split on top-level commas (not inside quotes/parens).
        return $this->splitTopLevel($raw, ',');
    }

    /**
     * Split a string on $sep, but ignore occurrences inside matched pairs of
     * any of: ' " ( [ {
     */
    private function splitTopLevel(string $s, string $sep): array
    {
        $parts = [];
        $cur = '';
        $depth = 0;
        $quote = null;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                if ($c === '\\' && $i + 1 < $len) { $cur .= $c . $s[++$i]; continue; }
                if ($c === $quote) $quote = null;
                $cur .= $c;
                continue;
            }
            if ($c === '\'' || $c === '"') { $quote = $c; $cur .= $c; continue; }
            if ($c === '(' || $c === '[' || $c === '{') { $depth++; $cur .= $c; continue; }
            if ($c === ')' || $c === ']' || $c === '}') { $depth--; $cur .= $c; continue; }
            if ($c === $sep && $depth === 0) {
                $parts[] = trim($cur); $cur = ''; continue;
            }
            $cur .= $c;
        }
        if (trim($cur) !== '') $parts[] = trim($cur);
        return array_values(array_filter($parts, fn($p) => $p !== ''));
    }

    /**
     * Parse a backtrace hidden block.
     *
     * Symfony's modern profiler renders the backtrace as a <table> with
     * "# | File/Call" columns. The hidden block may also contain a legacy
     * <ol><li>... format on older versions.
     */
    private function parseBacktrace(Crawler $hidden): array
    {
        $tbl = $hidden->filter('table');
        if ($tbl->count() > 0) {
            $headText = '';
            $thead = $tbl->filter('thead');
            if ($thead->count() > 0) $headText = trim($thead->text('', true));
            if ($headText === '') $headText = trim($tbl->text('', true));

            // Modern profiler uses "# | File/Call" header.
            if (str_contains($headText, 'File/Call') || str_contains($headText, 'File / Call')) {
                return $this->parseModernTable($tbl);
            }
        }
        $ol = $hidden->filter('ol');
        if ($ol->count() > 0) {
            return $this->parseLegacyOl($ol);
        }
        return [];
    }

    private function parseModernTable(Crawler $tbl): array
    {
        $frames = [];
        $body = $tbl->filter('tbody');
        if ($body->count() === 0) $body = $tbl;

        $body->filter('tr')->each(function (Crawler $tr) use (&$frames) {
            $tds = $tr->filter('td');
            if ($tds->count() < 2) return;
            $nText = trim($tds->eq(0)->text('', true));
            $n = ctype_digit($nText) ? (int) $nText : 0;

            $cell = $tds->eq(1);
            $anchor = $cell->filter('a');
            $fileUrl = $anchor->count() > 0 ? ($anchor->attr('href') ?? null) : null;
            $filePath = null;
            if ($fileUrl !== null && str_starts_with($fileUrl, 'file://')) {
                // file:///var/www/html/.../File.php#L42 → /var/www/html/.../File.php
                $filePath = substr($fileUrl, 7);
                if (($hash = strpos($filePath, '#')) !== false) {
                    $filePath = substr($filePath, 0, $hash);
                }
            }

            $cellText = trim(preg_replace('/\s+/', ' ', $cell->text('', true)));
            $class = $method = null;
            $line = null;
            if (preg_match(self::FRAME_RE, $cellText, $m)) {
                $class = $m[1];
                $method = $m[2];
                $line = (int) $m[3];
            }

            $frames[] = [
                'n'         => $n,
                'class'     => $class,
                'method'    => $method,
                'call'      => ($class !== null && $method !== null) ? ($class . '->' . $method) : $cellText,
                'line'      => $line,
                'file'      => $filePath,
                'file_url'  => $fileUrl,
                'is_vendor' => $filePath !== null && str_contains($filePath, '/vendor/'),
                // "is_src" means our project's src/, not vendor's src/ subdirectory.
                'is_src'    => $filePath !== null
                    && str_contains($filePath, '/src/')
                    && !str_contains($filePath, '/vendor/'),
                'host_path' => $this->toHostPath($filePath),
            ];
        });

        return $frames;
    }

    private function parseLegacyOl(Crawler $ol): array
    {
        $frames = [];
        $ol->filter('li')->each(function (Crawler $li) use (&$frames) {
            $n = 0;
            $li->filter('a')->each(function (Crawler $a) use (&$n) {
                if ($n === 0) {
                    $t = trim($a->text('', true));
                    if (ctype_digit($t)) $n = (int) $t;
                }
            });
            $pre = $li->filter('pre');
            $callText = $pre->count() > 0
                ? trim(preg_replace('/\s+/', ' ', $pre->text('', true)))
                : trim(preg_replace('/\s+/', ' ', $li->text('', true)));
            $class = $method = null;
            $line = null;
            if (preg_match('/^(.+?)->(\w+)\s*\(line\s+(\d+)\)\s*$/', $callText, $m)) {
                $class = $m[1]; $method = $m[2]; $line = (int) $m[3];
            }
            $frames[] = [
                'n'         => $n,
                'class'     => $class,
                'method'    => $method,
                'call'      => $callText,
                'line'      => $line,
                'file'      => null,
                'file_url'  => null,
                'is_vendor' => false,
                'is_src'    => false,
                'host_path' => null,
            ];
        });
        return $frames;
    }

    /**
     * Translate an in-container file path (e.g. /var/www/html/src/Service/Foo.php)
     * to the local checkout (e.g. /home/me/proj/src/Service/Foo.php).
     */
    private function toHostPath(?string $filePath): ?string
    {
        if ($filePath === null || $filePath === '') return null;
        $host = $this->config->hostPrefix;
        $src  = $this->config->srcPrefix;
        if ($host !== null && $host !== '' && str_starts_with($filePath, rtrim($host, '/') . '/')) {
            return $filePath;
        }
        if ($src !== null && $src !== '' && str_starts_with($filePath, rtrim($src, '/') . '/')) {
            if ($host !== null && $host !== '') {
                return rtrim($host, '/') . substr($filePath, strlen(rtrim($src, '/')));
            }
        }
        // Try common prefixes as last resort.
        foreach (['/var/www/html', '/app', '/srv/app', '/var/www'] as $common) {
            if (str_starts_with($filePath, $common . '/')) {
                if ($host !== null && $host !== '') {
                    return rtrim($host, '/') . substr($filePath, strlen($common));
                }
                return $filePath;
            }
        }
        return $filePath;
    }

    /**
     * Parse "1.2 ms" / "345 micros" / "0.005 s" → milliseconds (float).
     */
    public static function parseTimeMs(string $t): float
    {
        if ($t === '') return 0.0;
        if (!preg_match('/([\d.]+)\s*(ms|s|µs|us)?/i', trim($t), $m)) return 0.0;
        $v = (float) $m[1];
        $u = strtolower($m[2] ?? 'ms');
        return match ($u) {
            's'     => $v * 1000.0,
            'µs', 'us' => $v / 1000.0,
            default => $v,
        };
    }
}