<?php

namespace Survelium\AgentAnalytics\Storage;

/**
 * Where the core keeps what it has not sent yet. The file implementation
 * suits plain PHP; the WordPress plugin can back it with a table instead.
 */
interface Storage
{
    /**
     * @param  array<string, mixed>  $item  An event or referral, with a "kind" key.
     * @return bool False when the buffer is full and the item was dropped.
     */
    public function push(array $item, int $maxItems): bool;

    /**
     * @return array{count: int, oldest: ?int} Oldest is a Unix timestamp.
     */
    public function stats(): array;

    /**
     * @return array<string, array<string, mixed>> Oldest first, keyed by item id.
     */
    public function pending(int $limit): array;

    /**
     * @param  list<string>  $ids
     */
    public function remove(array $ids): void;

    public function incrementTotal(string $day, int $by = 1): void;

    /**
     * @return array<string, int> Day (Y-m-d) => hits, newest days only.
     */
    public function totals(int $days): array;

    /**
     * @return array<string, mixed>
     */
    public function state(): array;

    /**
     * @param  array<string, mixed>  $state
     */
    public function putState(array $state): void;

    /**
     * @return array{payload: array<string, mixed>, etag: ?string, checked_at: int}|null
     */
    public function signatures(): ?array;

    /**
     * @param  array{payload: array<string, mixed>, etag: ?string, checked_at: int}  $signatures
     */
    public function putSignatures(array $signatures): void;

    /**
     * Non-blocking: false when another request is already flushing.
     */
    public function acquireFlushLock(): bool;

    public function releaseFlushLock(): void;
}
