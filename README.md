# Survelium Agent Analytics for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/survelium/agent-analytics-php.svg?style=flat-square)](https://packagist.org/packages/survelium/agent-analytics-php)
[![Tests](https://img.shields.io/github/actions/workflow/status/survelium/agent-analytics-php/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/survelium/agent-analytics-php/actions/workflows/run-tests.yml)

See which AI crawlers read your site and how many people AI assistants send you, in [Survelium](https://survelium.com).

AI crawlers (GPTBot, ClaudeBot, PerplexityBot, …) do not run JavaScript, so a JS snippet never sees them. This
package runs on your server instead: it recognises AI crawlers by user agent and visitors arriving from
ChatGPT, Perplexity, Claude, Gemini or Copilot by referrer, buffers them locally and sends them to Survelium in
batches **after** the response has gone out to the visitor.

This is the framework-agnostic core. For Laravel use
[`survelium/agent-analytics-laravel`](https://github.com/survelium/agent-analytics-laravel); for WordPress use
the [plugin](https://github.com/survelium/agent-analytics-wordpress).

## What leaves your server

| | Sent | Never sent |
|---|---|---|
| AI crawler hit | time, user agent, path, HTTP status, IP (to verify it is really that crawler) | — |
| Visitor from an AI assistant | time, assistant domain (e.g. `chatgpt.com`), path, user agent | IP, full referrer, cookies |
| Every other request | a daily count, used for "share of traffic from AI" | anything about the request |

Survelium checks crawler IPs against the ranges each operator publishes and deletes them after 30 days.

## Requirements

PHP 8.1+, `ext-json`. `ext-curl` is used when present, PHP streams otherwise. No other dependencies.

## Installation

```bash
composer require survelium/agent-analytics-php
```

Create an ingest key in Survelium → AI traffic → Integration.

## Usage

As early as possible in every request (your front controller, or `auto_prepend_file`):

```php
use Survelium\AgentAnalytics\AgentAnalytics;
use Survelium\AgentAnalytics\Config;

AgentAnalytics::start(new Config(apiKey: 'svk_...'));
```

That is all. At shutdown the request is recorded and, every 50 items or 60 seconds, the buffer is sent. Under
PHP-FPM or LiteSpeed the response is finished first, so visitors never wait on it.

### Options

```php
new Config(
    apiKey: 'svk_...',
    trackReferrals: true,                      // false = crawlers only
    denyPaths: ['/admin', '/wp-admin'],        // path prefixes never reported or counted
    ipHeader: 'HTTP_CF_CONNECTING_IP',         // only behind a proxy that always sets it
    storagePath: '/var/www/storage/survelium', // default: the system temp folder
    batchSize: 50,
    flushIntervalSeconds: 60,
    maxBufferedItems: 20000,                   // when full, new items are dropped
    timeoutSeconds: 5.0,
);
```

### Driving it yourself

Integrations with their own lifecycle (queues, cron) can call the parts directly:

```php
$analytics = AgentAnalytics::make($config);   // or pass your own Storage / Transport

$analytics->record(Hit::fromGlobals($config, http_response_code()));

if ($analytics->shouldFlush()) {
    $analytics->flush();                        // safe from cron; one flush at a time
}

$analytics->status();                           // buffer size, last error, signatures version
```

`Storage` (buffer, counters, cached signatures) and `Transport` (HTTP) are interfaces; `FakeTransport` is
included for tests.

## How it behaves when things go wrong

- **Survelium unreachable or 5xx:** data stays buffered, retries back off from 30 s to an hour.
- **Key revoked (401):** pauses an hour. **Plan without AI traffic (402):** pauses six hours. Data is kept.
- **Rate limited (429):** waits for `Retry-After`.
- **Storage folder not writable:** does nothing. Analytics never breaks your site.

The bot list is fetched from `GET /api/v1/signatures` once a day (with an ETag), with a bundled copy as a
fallback. Survelium re-checks every hit against its own list, so an outdated copy only misses the newest bots.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Security

Please report vulnerabilities through [GitHub security advisories](../../security/advisories/new), not in issues.

## License

The MIT License (MIT). See [License File](LICENSE.md).
