<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\ErrorLog;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Global error listener: every uncaught exception bubbles through
 * kernel.exception. We log a compact record (timestamp, level, message,
 * request, file/line) to the JSON-lines error log so the live console
 * in the ctrl menu can show it without hitting the Symfony profiler.
 */
#[AsEventListener(event: 'kernel.exception', priority: -100)]
final class ExceptionListener
{
    public function __construct(private readonly ErrorLog $log) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $request = $event->getRequest();

        $status = 500;
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
        }

        // Don't pollute the log with 404s and the like — those are noise.
        // Still log 4xx (other than 404) because they signal bad requests.
        if ($status === 404) {
            return;
        }

        $this->log->append([
            'ts'        => date('c'),
            'level'     => $status >= 500 ? 'error' : 'warning',
            'status'    => $status,
            'message'   => $e->getMessage(),
            'class'     => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'method'    => $request->getMethod(),
            'path'      => $this->shortenPath($request),
            'ip'        => $request->getClientIp(),
            'trace'     => $this->trimTrace($e->getTrace()),
        ]);
    }

    /** First 6 frames of the trace, paths shortened — keeps the file small. */
    private function trimTrace(array $trace): array
    {
        $out = [];
        foreach (array_slice($trace, 0, 6) as $f) {
            $out[] = [
                'file' => isset($f['file']) ? $this->short($f['file']) : null,
                'line' => $f['line'] ?? null,
                'call' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
            ];
        }
        return $out;
    }

    private function shortenPath(Request $request): string
    {
        $path = $request->getPathInfo();
        $qs = $request->getQueryString();
        if ($qs !== null && strlen($qs) < 200) $path .= '?' . $qs;
        return $path;
    }

    private function short(string $file): string
    {
        // Strip the project root + vendor prefixes for readability.
        return preg_replace('#^/app/(src/|vendor/|public/)?#', '', $file) ?? $file;
    }
}
