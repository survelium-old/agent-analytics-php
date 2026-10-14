<?php

namespace Survelium\AgentAnalytics\Transport;

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeoutSeconds = 5.0): HttpResponse
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return new HttpResponse(0);
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(2000, $timeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeoutSeconds * 1000),
            CURLOPT_HTTPHEADER => array_map(fn (string $name, string $value): string => "{$name}: {$value}", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        $status = $responseBody === false ? 0 : (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        return new HttpResponse($status, $responseHeaders, is_string($responseBody) ? $responseBody : '');
    }
}
