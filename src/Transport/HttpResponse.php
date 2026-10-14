<?php

namespace Survelium\AgentAnalytics\Transport;

final class HttpResponse
{
    /**
     * @param  int  $status  0 when the request never got an answer (DNS, timeout, refused).
     * @param  array<string, string>  $headers  Lower-cased names.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
