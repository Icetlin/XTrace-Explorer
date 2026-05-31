<?php

namespace App\Message;

class ParseTraceMessage
{
    public function __construct(public readonly int $traceFileId) {}
}
