<?php

namespace Survelium\AgentAnalytics;

/**
 * One request to the host site, as far as we need it.
 */
final class Hit
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $userAgent = null,
        public readonly ?string $referrer = null,
        public readonly ?string $ip = null,
        public readonly ?int $status = null,
        public readonly ?int $time = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $server  Defaults to $_SERVER.
     */
    public static function fromGlobals(Config $config, ?int $status = null, ?array $server = null): self
    {
        $server ??= $_SERVER;

        $ip = null;

        if ($config->ipHeader !== null && is_string($server[$config->ipHeader] ?? null)) {
            // X-Forwarded-For style lists put the client first.
            $ip = trim(explode(',', $server[$config->ipHeader])[0]);
        }

        if ($ip === null || $ip === '') {
            $ip = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : null;
        }

        return new self(
            path: is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/',
            userAgent: is_string($server['HTTP_USER_AGENT'] ?? null) ? $server['HTTP_USER_AGENT'] : null,
            referrer: is_string($server['HTTP_REFERER'] ?? null) ? $server['HTTP_REFERER'] : null,
            ip: $ip,
            status: $status,
        );
    }

    /**
     * Crawlers fetch pages with GET/HEAD; form posts, XHR calls (a shop's cart
     * widget, for instance) and prefetches would inflate the daily total.
     *
     * @param  array<string, mixed>  $server
     */
    public static function isPageView(array $server): bool
    {
        $header = fn (string $key): string => is_string($server[$key] ?? null) ? $server[$key] : '';
        $method = strtoupper($header('REQUEST_METHOD') ?: 'GET');

        return in_array($method, ['GET', 'HEAD'], true)
            && strcasecmp($header('HTTP_X_REQUESTED_WITH'), 'XMLHttpRequest') !== 0
            && stripos($header('HTTP_SEC_PURPOSE').$header('HTTP_PURPOSE'), 'prefetch') === false;
    }

    /**
     * Path and query only, even if a full URL was passed.
     */
    public function normalizedPath(): string
    {
        $path = $this->path;

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            $parts = parse_url($path);
            $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        $path = $path === '' || $path[0] !== '/' ? '/'.$path : $path;

        return mb_substr($path, 0, 2048);
    }

    public function validIp(): ?string
    {
        return $this->ip !== null && filter_var($this->ip, FILTER_VALIDATE_IP) !== false ? $this->ip : null;
    }

    public function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $this->time ?? time());
    }
}
