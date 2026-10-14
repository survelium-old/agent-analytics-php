<?php

namespace Survelium\AgentAnalytics;

/**
 * The catalogue served at GET /api/v1/signatures. Matching mirrors the server
 * exactly; it is only a prefilter, since the server re-matches every event.
 */
final class Signatures
{
    /**
     * @param  list<array{pattern: string, name: string, operator?: ?string, category?: ?string}>  $bots  Longest pattern first.
     * @param  list<array{domain: string, name: string}>  $referrers
     */
    public function __construct(
        public readonly array $bots,
        public readonly array $referrers,
        public readonly ?string $version = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $bots = array_values(array_filter(
            is_array($payload['bots'] ?? null) ? $payload['bots'] : [],
            fn ($bot): bool => is_array($bot) && is_string($bot['pattern'] ?? null) && $bot['pattern'] !== '',
        ));

        // Re-sort in case a hand-edited copy lost the server's order.
        usort($bots, fn (array $a, array $b): int => [mb_strlen($b['pattern']), $a['pattern']] <=> [mb_strlen($a['pattern']), $b['pattern']]);

        $referrers = array_values(array_filter(
            is_array($payload['referrers'] ?? null) ? $payload['referrers'] : [],
            fn ($referrer): bool => is_array($referrer) && is_string($referrer['domain'] ?? null) && $referrer['domain'] !== '',
        ));

        return new self($bots, $referrers, is_string($payload['version'] ?? null) ? $payload['version'] : null);
    }

    /**
     * The copy shipped with the package, used until the first successful fetch.
     */
    public static function bundled(): self
    {
        $json = file_get_contents(__DIR__.'/../resources/signatures.json');
        $payload = $json === false ? null : json_decode($json, true);

        return self::fromArray(is_array($payload) ? $payload : []);
    }

    /**
     * @return array{pattern: string, name: string, operator?: ?string, category?: ?string}|null
     */
    public function matchBot(?string $userAgent): ?array
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        foreach ($this->bots as $bot) {
            if (str_contains($userAgent, $bot['pattern'])) {
                return $bot;
            }
        }

        return null;
    }

    /**
     * The AI assistant domain a visitor came from: the Referer host first,
     * then a utm_source on the landing URL (ChatGPT adds utm_source=chatgpt.com).
     */
    public function matchReferrer(?string $referrer, ?string $requestUri = null): ?string
    {
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

        if (is_string($host) && ($domain = $this->knownDomain($host)) !== null) {
            return $domain;
        }

        $query = $requestUri ? parse_url($requestUri, PHP_URL_QUERY) : null;

        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $params);

        return is_string($params['utm_source'] ?? null) ? $this->knownDomain($params['utm_source']) : null;
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?://#i', '', $domain);
        $domain = (string) preg_replace('#^www\.#i', '', $domain);

        return rtrim(explode('/', $domain)[0], '.');
    }

    private function knownDomain(string $value): ?string
    {
        $domain = self::normalizeDomain($value);

        foreach ($this->referrers as $referrer) {
            if ($referrer['domain'] === $domain) {
                return $domain;
            }
        }

        return null;
    }
}
