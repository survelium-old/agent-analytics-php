<?php

namespace Survelium\AgentAnalytics\Transport;

/**
 * Fallback for hosts without ext-curl.
 */
final class StreamTransport implements Transport
{
    public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeoutSeconds = 5.0): HttpResponse
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", array_map(fn (string $name, string $value): string => "{$name}: {$value}", array_keys($headers), $headers)),
                'content' => $body ?? '',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $rawHeaders = $http_response_header ?? [];

        if ($responseBody === false || $rawHeaders === []) {
            return new HttpResponse(0);
        }

        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $rawHeaders[0], $matches) ? (int) $matches[1] : 0;
        $responseHeaders = [];

        foreach (array_slice($rawHeaders, 1) as $line) {
            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return new HttpResponse($status, $responseHeaders, $responseBody);
    }
}
