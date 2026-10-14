<?php

namespace Survelium\AgentAnalytics\Transport;

/**
 * Records requests and answers from a queue of canned responses. For tests,
 * including those of the Laravel package and the WordPress plugin.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var list<HttpResponse> */
    private array $responses;

    public function __construct(HttpResponse ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function push(HttpResponse $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeoutSeconds = 5.0): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return array_shift($this->responses) ?? new HttpResponse(202, [], '{}');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastJson(): ?array
    {
        $last = end($this->requests);
        $decoded = $last && $last['body'] !== null ? json_decode($last['body'], true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
