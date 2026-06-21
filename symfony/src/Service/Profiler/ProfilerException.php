<?php

declare(strict_types=1);

namespace App\Service\Profiler;

/**
 * Thrown when something goes wrong talking to the Profiler HTTP endpoint.
 *
 * Carries the HTTP status (when applicable) so the controller layer can
 * surface it as a useful 4xx/5xx instead of a generic 500.
 */
class ProfilerException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}