<?php

namespace Survelium\AgentAnalytics;

final class Config
{
    public const DEFAULT_ENDPOINT = 'https://survelium.com';

    /**
     * @param  string  $apiKey  Ingest key from Survelium → AI traffic → Integration (starts with "svk_").
     * @param  bool  $trackReferrals  Also report people who arrive from an AI assistant (chatgpt.com, perplexity.ai, …).
     * @param  list<string>  $denyPaths  Path prefixes never reported, e.g. "/wp-admin", "/admin".
     * @param  string|null  $ipHeader  Server variable holding the real client IP behind a proxy, e.g.
     *                                 "HTTP_CF_CONNECTING_IP". Only trust it when the proxy always sets it.
     * @param  string|null  $storagePath  Folder for the buffer, counters and cached signatures. Defaults to the system temp folder.
     * @param  bool  $countAllHits  The daily total is people plus the AI crawlers reported; other bots never count.
     *                              True: count people here. False: the host counts them another way (the WordPress
     *                              plugin uses a JS ping, since cached pages never reach PHP), so only AI crawlers are added.
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $endpoint = self::DEFAULT_ENDPOINT,
        public readonly bool $trackReferrals = true,
        public readonly array $denyPaths = [],
        public readonly ?string $ipHeader = null,
        public readonly ?string $storagePath = null,
        public readonly int $batchSize = 50,
        public readonly int $flushIntervalSeconds = 60,
        public readonly int $maxBufferedItems = 20000,
        public readonly float $timeoutSeconds = 5.0,
        public readonly bool $countAllHits = true,
    ) {}

    public function storagePath(): string
    {
        return rtrim($this->storagePath ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.'survelium-'.substr(hash('sha256', $this->apiKey), 0, 12), '/\\');
    }

    public function url(string $path): string
    {
        return rtrim($this->endpoint, '/').'/'.ltrim($path, '/');
    }

    public function isDenied(string $path): bool
    {
        foreach ($this->denyPaths as $prefix) {
            if ($prefix !== '' && strncmp($path, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return false;
    }
}
