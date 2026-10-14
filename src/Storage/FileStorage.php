<?php

namespace Survelium\AgentAnalytics\Storage;

/**
 * JSON files in one folder. Every method swallows I/O failures: a full disk
 * or a read-only folder must never break the site that hosts us.
 */
final class FileStorage implements Storage
{
    private const TOTALS_KEEP_DAYS = 8;

    /** @var resource|null */
    private $flushLock = null;

    public function __construct(private readonly string $path) {}

    public function push(array $item, int $maxItems): bool
    {
        return (bool) $this->withLock('buffer', function () use ($item, $maxItems): bool {
            $meta = $this->readJson('buffer.meta.json');
            $count = (int) ($meta['count'] ?? 0);

            if ($count >= $maxItems) {
                return false;
            }

            $item['id'] ??= bin2hex(random_bytes(8));
            $line = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($line === false || @file_put_contents($this->file('buffer.jsonl'), $line."\n", FILE_APPEND) === false) {
                return false;
            }

            $this->writeJson('buffer.meta.json', ['count' => $count + 1, 'oldest' => $meta['oldest'] ?? time()]);

            return true;
        });
    }

    public function stats(): array
    {
        $meta = $this->readJson('buffer.meta.json');

        return ['count' => (int) ($meta['count'] ?? 0), 'oldest' => isset($meta['oldest']) ? (int) $meta['oldest'] : null];
    }

    public function pending(int $limit): array
    {
        $file = $this->file('buffer.jsonl');
        $handle = is_file($file) ? @fopen($file, 'r') : false;

        if ($handle === false) {
            return [];
        }

        $items = [];

        while (count($items) < $limit && ($line = fgets($handle)) !== false) {
            $item = json_decode($line, true);

            if (is_array($item) && isset($item['id'])) {
                $items[(string) $item['id']] = $item;
            }
        }

        fclose($handle);

        return $items;
    }

    public function remove(array $ids): void
    {
        $this->withLock('buffer', function () use ($ids): void {
            $file = $this->file('buffer.jsonl');
            $lines = is_file($file) ? (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
            $drop = array_flip($ids);
            $kept = [];
            $oldest = null;

            foreach ($lines as $line) {
                $item = json_decode($line, true);

                if (! is_array($item) || isset($drop[(string) ($item['id'] ?? '')])) {
                    continue;
                }

                $kept[] = $line;
                $oldest ??= isset($item['ts']) ? (strtotime((string) $item['ts']) ?: time()) : time();
            }

            @file_put_contents($this->file('buffer.jsonl'), $kept === [] ? '' : implode("\n", $kept)."\n");
            $this->writeJson('buffer.meta.json', ['count' => count($kept), 'oldest' => $oldest]);
        });
    }

    public function incrementTotal(string $day, int $by = 1): void
    {
        $this->withLock('totals', function () use ($day, $by): void {
            $totals = $this->readJson('totals.json');
            $totals[$day] = (int) ($totals[$day] ?? 0) + $by;

            $cutoff = gmdate('Y-m-d', time() - self::TOTALS_KEEP_DAYS * 86400);
            $totals = array_filter($totals, fn ($hits, $key): bool => (string) $key >= $cutoff, ARRAY_FILTER_USE_BOTH);

            $this->writeJson('totals.json', $totals);
        });
    }

    public function totals(int $days): array
    {
        $totals = array_map('intval', $this->readJson('totals.json'));
        krsort($totals);

        return array_slice($totals, 0, $days, true);
    }

    public function state(): array
    {
        return $this->readJson('state.json');
    }

    public function putState(array $state): void
    {
        $this->writeJson('state.json', $state);
    }

    public function signatures(): ?array
    {
        $cached = $this->readJson('signatures.json');

        return is_array($cached['payload'] ?? null) ? $cached : null;
    }

    public function putSignatures(array $signatures): void
    {
        $this->writeJson('signatures.json', $signatures);
    }

    public function acquireFlushLock(): bool
    {
        if (! $this->ensureDirectory()) {
            return false;
        }

        $handle = @fopen($this->file('flush.lock'), 'c');

        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->flushLock = $handle;

        return true;
    }

    public function releaseFlushLock(): void
    {
        if ($this->flushLock !== null) {
            flock($this->flushLock, LOCK_UN);
            fclose($this->flushLock);
            $this->flushLock = null;
        }
    }

    private function withLock(string $name, callable $callback): mixed
    {
        if (! $this->ensureDirectory()) {
            return null;
        }

        $handle = @fopen($this->file($name.'.lock'), 'c');

        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, LOCK_EX);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $name): array
    {
        $file = $this->file($name);
        $contents = is_file($file) ? @file_get_contents($file) : false;
        $decoded = $contents === false ? null : json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $name, array $data): void
    {
        if (! $this->ensureDirectory()) {
            return;
        }

        // Write-then-rename, so a reader never sees half a file.
        $temporary = $this->file($name.'.'.bin2hex(random_bytes(4)).'.tmp');

        if (@file_put_contents($temporary, (string) json_encode($data, JSON_UNESCAPED_SLASHES)) === false) {
            return;
        }

        if (! @rename($temporary, $this->file($name))) {
            @unlink($temporary);
        }
    }

    private function ensureDirectory(): bool
    {
        return is_dir($this->path) || @mkdir($this->path, 0775, true) || is_dir($this->path);
    }

    private function file(string $name): string
    {
        return $this->path.DIRECTORY_SEPARATOR.$name;
    }
}
