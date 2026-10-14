<?php

namespace Survelium\AgentAnalytics;

use Survelium\AgentAnalytics\Storage\FileStorage;
use Survelium\AgentAnalytics\Storage\Storage;
use Survelium\AgentAnalytics\Transport\CurlTransport;
use Survelium\AgentAnalytics\Transport\HttpResponse;
use Survelium\AgentAnalytics\Transport\StreamTransport;
use Survelium\AgentAnalytics\Transport\Transport;
use Throwable;

/**
 * Records AI crawler hits and AI-referred visits, and ships them to Survelium
 * in batches. The request path only appends to local storage; sending happens
 * after the response has gone out (fastcgi_finish_request / LiteSpeed).
 */
final class AgentAnalytics
{
    public const VERSION = '0.1.0';

    public const MAX_ITEMS_PER_REQUEST = 1000;

    /** Totals are sent on their own at least this often, so the AI share has a denominator on quiet days. */
    public const TOTALS_INTERVAL_SECONDS = 900;

    /** At most this many batches per flush, so one request never runs long. */
    public const MAX_BATCHES_PER_FLUSH = 3;

    private ?Signatures $signatures = null;

    /** @var array{payload: array<string, mixed>, etag: ?string, checked_at: int}|false|null False until first read. */
    private array|false|null $cachedSignatures = false;

    public function __construct(
        public readonly Config $config,
        public readonly Storage $storage,
        public readonly Transport $transport,
    ) {}

    public static function make(Config $config, ?Storage $storage = null, ?Transport $transport = null): self
    {
        return new self(
            $config,
            $storage ?? new FileStorage($config->storagePath()),
            $transport ?? (function_exists('curl_init') ? new CurlTransport : new StreamTransport),
        );
    }

    /**
     * Drop-in for plain PHP sites: call once, as early as possible
     * (e.g. from auto_prepend_file). Everything else happens at shutdown.
     */
    public static function start(Config $config): self
    {
        $instance = self::make($config);

        if (PHP_SAPI !== 'cli') {
            register_shutdown_function([$instance, 'handleShutdown']);
        }

        return $instance;
    }

    public function handleShutdown(): void
    {
        try {
            $status = http_response_code();
            $this->record(Hit::fromGlobals($this->config, is_int($status) ? $status : null));

            if ($this->shouldFlush()) {
                $this->finishResponse();
                $this->flush();
            }
        } catch (Throwable) {
            // Never let analytics break the host site.
        }
    }

    /**
     * Counts the hit toward today's total and buffers it if it is an AI
     * crawler or a visitor sent by an AI assistant.
     *
     * @return 'event'|'referral'|null What was buffered.
     */
    public function record(Hit $hit): ?string
    {
        $path = $hit->normalizedPath();

        if ($this->config->isDenied($path)) {
            return null;
        }

        $day = gmdate('Y-m-d', $hit->time ?? time());

        if ($this->config->countAllHits) {
            $this->storage->incrementTotal($day);
        }

        $userAgent = $hit->userAgent !== null ? mb_substr($hit->userAgent, 0, 1024) : null;

        if ($this->signatures()->matchBot($userAgent) !== null) {
            if (! $this->config->countAllHits) {
                $this->storage->incrementTotal($day);
            }

            $this->storage->push([
                'kind' => 'event',
                'ts' => $hit->timestamp(),
                'ua' => $userAgent,
                'path' => $path,
                'status' => $hit->status !== null && $hit->status >= 100 && $hit->status <= 599 ? $hit->status : null,
                'ip' => $hit->validIp(),
            ], $this->config->maxBufferedItems);

            return 'event';
        }

        if (! $this->config->trackReferrals) {
            return null;
        }

        $source = $this->signatures()->matchReferrer($hit->referrer, $path);

        if ($source === null) {
            return null;
        }

        // Only the assistant's domain leaves the site: no visitor IP, no full referrer.
        $this->storage->push([
            'kind' => 'referral',
            'ts' => $hit->timestamp(),
            'source' => $source,
            'path' => $path,
            'ua' => $userAgent,
        ], $this->config->maxBufferedItems);

        return 'referral';
    }

    public function shouldFlush(): bool
    {
        $state = $this->storage->state();
        $now = time();

        if (($state['paused_until'] ?? 0) > $now || ($state['next_attempt_at'] ?? 0) > $now) {
            return false;
        }

        $stats = $this->storage->stats();

        return $stats['count'] >= $this->config->batchSize
            || ($stats['oldest'] !== null && $now - $stats['oldest'] >= $this->config->flushIntervalSeconds)
            || $now - (int) ($state['totals_sent_at'] ?? 0) >= self::TOTALS_INTERVAL_SECONDS
            || $this->signaturesAreStale();
    }

    /**
     * Sends what is buffered. Safe to call from a cron or queue job too.
     *
     * @return int|null HTTP status of the last batch, null when nothing was sent.
     */
    public function flush(): ?int
    {
        if (! $this->storage->acquireFlushLock()) {
            return null;
        }

        try {
            if ($this->signaturesAreStale()) {
                $this->refreshSignatures();
            }

            $status = null;

            for ($batch = 0; $batch < self::MAX_BATCHES_PER_FLUSH; $batch++) {
                $state = $this->storage->state();

                if (($state['paused_until'] ?? 0) > time() || ($state['next_attempt_at'] ?? 0) > time()) {
                    break;
                }

                $items = $this->storage->pending(self::MAX_ITEMS_PER_REQUEST);
                $sendTotals = $batch === 0;

                if ($items === [] && ! $sendTotals) {
                    break;
                }

                $payload = $this->payload($items, $sendTotals);

                if ($payload === []) {
                    break;
                }

                $status = $this->send($payload, array_keys($items));

                if ($status !== 202 || count($items) < self::MAX_ITEMS_PER_REQUEST) {
                    break;
                }
            }

            return $status;
        } finally {
            $this->storage->releaseFlushLock();
        }
    }

    public function signatures(): Signatures
    {
        if ($this->signatures === null) {
            $cached = $this->cachedSignatures();
            $this->signatures = $cached !== null ? Signatures::fromArray($cached['payload']) : Signatures::bundled();
        }

        return $this->signatures;
    }

    public function refreshSignatures(): void
    {
        $cached = $this->cachedSignatures();
        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent()];

        if (($cached['etag'] ?? null) !== null) {
            $headers['If-None-Match'] = $cached['etag'];
        }

        $response = $this->transport->send('GET', $this->config->url('api/v1/signatures'), $headers, null, $this->config->timeoutSeconds);
        $payload = $response->status === 200 ? json_decode($response->body, true) : null;

        if (is_array($payload) && is_array($payload['bots'] ?? null)) {
            $this->storeSignatures(['payload' => $payload, 'etag' => $response->header('etag'), 'checked_at' => time()]);
            $this->signatures = Signatures::fromArray($payload);

            return;
        }

        if ($cached !== null) {
            // 304, or the server is unreachable: keep the copy, look again after a full TTL.
            $cached['checked_at'] = time();
            $this->storeSignatures($cached);

            return;
        }

        // Never fetched and the server is down: store the bundled list so we retry in a day, not on every request.
        $bundled = Signatures::bundled();
        $this->storeSignatures([
            'payload' => ['bots' => $bundled->bots, 'referrers' => $bundled->referrers, 'version' => $bundled->version],
            'etag' => null,
            'checked_at' => time(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'version' => self::VERSION,
            'buffered' => $this->storage->stats(),
            'state' => $this->storage->state(),
            'signatures_version' => $this->signatures()->version,
            'bots' => count($this->signatures()->bots),
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, etag: ?string, checked_at: int}|null
     */
    private function cachedSignatures(): ?array
    {
        if ($this->cachedSignatures === false) {
            $this->cachedSignatures = $this->storage->signatures();
        }

        return $this->cachedSignatures;
    }

    /**
     * @param  array{payload: array<string, mixed>, etag: ?string, checked_at: int}  $signatures
     */
    private function storeSignatures(array $signatures): void
    {
        $this->storage->putSignatures($signatures);
        $this->cachedSignatures = $signatures;
    }

    private function signaturesAreStale(): bool
    {
        $cached = $this->cachedSignatures();
        $ttl = (int) ($cached['payload']['ttl'] ?? 86400);

        return $cached === null || time() - (int) $cached['checked_at'] >= $ttl;
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, list<array<string, mixed>>>
     */
    private function payload(array $items, bool $withTotals): array
    {
        $payload = [];

        foreach ($items as $item) {
            $kind = $item['kind'] ?? null;
            unset($item['kind'], $item['id']);

            if ($kind === 'event') {
                $payload['events'][] = array_filter($item, fn ($value): bool => $value !== null);
            } elseif ($kind === 'referral') {
                $payload['referrals'][] = array_filter($item, fn ($value): bool => $value !== null);
            }
        }

        if ($withTotals) {
            // Cumulative for the day; the server keeps the maximum, so resending is harmless.
            foreach ($this->storage->totals(2) as $day => $hits) {
                $payload['totals'][] = ['day' => (string) $day, 'hits' => $hits];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $payload
     * @param  list<string>  $ids
     */
    private function send(array $payload, array $ids): int
    {
        $response = $this->transport->send('POST', $this->config->url('api/v1/ingest/crawlers'), [
            'Authorization' => 'Bearer '.$this->config->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent(),
        ], (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->config->timeoutSeconds);

        $this->storage->putState($this->nextState($this->storage->state(), $response, isset($payload['totals'])));

        // Accepted, or rejected in a way that resending can never fix.
        if (in_array($response->status, [202, 413, 422], true)) {
            $this->storage->remove($ids);
        }

        return $response->status;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function nextState(array $state, HttpResponse $response, bool $sentTotals): array
    {
        $now = time();
        $state['last_status'] = $response->status;
        $state['last_attempt_at'] = $now;
        unset($state['next_attempt_at'], $state['paused_until']);

        if ($response->status === 202) {
            $state['failures'] = 0;
            $state['last_success_at'] = $now;
            $state['last_error'] = null;

            if ($sentTotals) {
                $state['totals_sent_at'] = $now;
            }
        } elseif ($response->status === 401) {
            $state['last_error'] = 'invalid_key';
            $state['paused_until'] = $now + 3600;
        } elseif ($response->status === 402) {
            $state['last_error'] = 'plan_required';
            $state['paused_until'] = $now + 6 * 3600;
        } elseif (in_array($response->status, [413, 422], true)) {
            $state['last_error'] = 'rejected';
        } elseif ($response->status === 429) {
            $retryAfter = (int) ($response->header('retry-after') ?? 60);
            $state['last_error'] = 'rate_limited';
            $state['next_attempt_at'] = $now + max(1, min(3600, $retryAfter));
        } else {
            $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
            $state['last_error'] = $response->status === 0 ? 'unreachable' : 'server_error';
            $state['next_attempt_at'] = $now + min(3600, 30 * 2 ** min(7, $state['failures'] - 1));
        }

        return $state;
    }

    private function finishResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    private function userAgent(): string
    {
        return 'survelium-agent-analytics-php/'.self::VERSION.' (PHP '.PHP_VERSION.')';
    }
}
