<?php

namespace Survelium\AgentAnalytics\Transport;

interface Transport
{
    /**
     * Never throws: network failures come back as status 0.
     *
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeoutSeconds = 5.0): HttpResponse;
}
