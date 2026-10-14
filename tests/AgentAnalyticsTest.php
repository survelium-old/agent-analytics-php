<?php

use Survelium\AgentAnalytics\AgentAnalytics;
use Survelium\AgentAnalytics\Hit;
use Survelium\AgentAnalytics\Signatures;
use Survelium\AgentAnalytics\Storage\FileStorage;
use Survelium\AgentAnalytics\Transport\FakeTransport;
use Survelium\AgentAnalytics\Transport\HttpResponse;

describe('recording', function () {
    it('buffers an AI crawler hit with its IP and status, and counts it in the total', function () {
        $analytics = analytics(new FakeTransport);

        $kind = $analytics->record(new Hit('/pricing', GPTBOT_UA, null, '20.171.206.10', 200));

        $item = array_values($analytics->storage->pending(10))[0];

        expect($kind)->toBe('event')
            ->and($item)->toMatchArray(['kind' => 'event', 'ua' => GPTBOT_UA, 'path' => '/pricing', 'status' => 200, 'ip' => '20.171.206.10'])
            ->and($analytics->storage->totals(1))->toBe([gmdate('Y-m-d') => 1]);
    });

    it('buffers a visitor from an AI assistant with only the assistant domain, never their IP', function () {
        $analytics = analytics(new FakeTransport);

        $kind = $analytics->record(new Hit('/pricing', CHROME_UA, 'https://chatgpt.com/c/abc', '81.196.1.1'));

        $item = array_values($analytics->storage->pending(10))[0];

        expect($kind)->toBe('referral')
            ->and($item)->toMatchArray(['kind' => 'referral', 'source' => 'chatgpt.com', 'path' => '/pricing'])
            ->and($item)->not->toHaveKey('ip');
    });

    it('only counts other visitors', function () {
        $analytics = analytics(new FakeTransport);

        expect($analytics->record(new Hit('/', CHROME_UA, 'https://www.google.com/')))->toBeNull()
            ->and($analytics->storage->stats()['count'])->toBe(0)
            ->and($analytics->storage->totals(1))->toBe([gmdate('Y-m-d') => 1]);
    });

    it('counts only AI crawler hits in the total when the host counts page views itself', function () {
        $analytics = analytics(new FakeTransport, ['countAllHits' => false]);

        $analytics->record(new Hit('/', CHROME_UA));
        $analytics->record(new Hit('/', CHROME_UA, 'https://chatgpt.com/'));
        $analytics->record(new Hit('/', GPTBOT_UA));

        expect($analytics->storage->stats()['count'])->toBe(2)
            ->and($analytics->storage->totals(1))->toBe([gmdate('Y-m-d') => 1]);
    });

    it('ignores denied paths entirely', function () {
        $analytics = analytics(new FakeTransport, ['denyPaths' => ['/wp-admin']]);

        expect($analytics->record(new Hit('/wp-admin/admin-ajax.php', GPTBOT_UA)))->toBeNull()
            ->and($analytics->storage->totals(1))->toBe([]);
    });

    it('skips referrals when asked to track crawlers only', function () {
        $analytics = analytics(new FakeTransport, ['trackReferrals' => false]);

        expect($analytics->record(new Hit('/', CHROME_UA, 'https://chatgpt.com/')))->toBeNull()
            ->and($analytics->record(new Hit('/', GPTBOT_UA)))->toBe('event');
    });

    it('stops buffering when the buffer is full', function () {
        $analytics = analytics(new FakeTransport, ['maxBufferedItems' => 2]);

        foreach (range(1, 3) as $i) {
            $analytics->record(new Hit("/page-{$i}", GPTBOT_UA));
        }

        expect($analytics->storage->stats()['count'])->toBe(2);
    });
});

describe('flushing', function () {
    it('sends events, referrals and totals with the ingest key, then empties the buffer', function () {
        $transport = new FakeTransport;
        $analytics = analytics($transport);
        freshSignatures($analytics);

        $analytics->record(new Hit('/pricing', GPTBOT_UA, null, '20.171.206.10', 200));
        $analytics->record(new Hit('/blog', CHROME_UA, 'https://perplexity.ai/'));

        expect($analytics->flush())->toBe(202);

        $request = $transport->requests[0];
        $body = $transport->lastJson();

        expect($request['url'])->toBe('https://survelium.test/api/v1/ingest/crawlers')
            ->and($request['headers']['Authorization'])->toBe('Bearer svk_test')
            ->and($body['events'][0])->toMatchArray(['ua' => GPTBOT_UA, 'path' => '/pricing', 'status' => 200, 'ip' => '20.171.206.10'])
            ->and($body['events'][0])->not->toHaveKeys(['kind', 'id'])
            ->and($body['referrals'][0])->toMatchArray(['source' => 'perplexity.ai', 'path' => '/blog'])
            ->and($body['referrals'][0])->not->toHaveKey('ip')
            ->and($body['totals'])->toBe([['day' => gmdate('Y-m-d'), 'hits' => 2]])
            ->and($analytics->storage->stats()['count'])->toBe(0)
            ->and($analytics->storage->state()['totals_sent_at'])->toBeGreaterThan(0);
    });

    it('leaves out a status or IP it does not know', function () {
        $transport = new FakeTransport;
        $analytics = analytics($transport);
        freshSignatures($analytics);

        $analytics->record(new Hit('/', GPTBOT_UA, null, 'not-an-ip'));
        $analytics->flush();

        expect($transport->lastJson()['events'][0])->not->toHaveKeys(['ip', 'status']);
    });

    it('keeps the buffer and backs off when the server is down', function () {
        $transport = new FakeTransport(new HttpResponse(503));
        $analytics = analytics($transport);
        freshSignatures($analytics);

        $analytics->record(new Hit('/', GPTBOT_UA));

        expect($analytics->flush())->toBe(503)
            ->and($analytics->storage->stats()['count'])->toBe(1)
            ->and($analytics->storage->state())->toMatchArray(['failures' => 1, 'last_error' => 'server_error'])
            ->and($analytics->storage->state()['next_attempt_at'])->toBeGreaterThan(time())
            ->and($analytics->shouldFlush())->toBeFalse();
    });

    it('treats an unreachable server like an outage', function () {
        $analytics = analytics(new FakeTransport(new HttpResponse(0)));
        freshSignatures($analytics);
        $analytics->record(new Hit('/', GPTBOT_UA));

        $analytics->flush();

        expect($analytics->storage->state()['last_error'])->toBe('unreachable');
    });

    it('pauses for an hour on a rejected key and keeps the data', function () {
        $analytics = analytics(new FakeTransport(new HttpResponse(401)));
        freshSignatures($analytics);
        $analytics->record(new Hit('/', GPTBOT_UA));

        $analytics->flush();

        expect($analytics->storage->state()['last_error'])->toBe('invalid_key')
            ->and($analytics->storage->state()['paused_until'])->toBeGreaterThanOrEqual(time() + 3599)
            ->and($analytics->storage->stats()['count'])->toBe(1);
    });

    it('pauses longer when the plan does not include it', function () {
        $analytics = analytics(new FakeTransport(new HttpResponse(402)));
        freshSignatures($analytics);
        $analytics->record(new Hit('/', GPTBOT_UA));

        $analytics->flush();

        expect($analytics->storage->state()['last_error'])->toBe('plan_required')
            ->and($analytics->storage->state()['paused_until'])->toBeGreaterThan(time() + 3600);
    });

    it('drops a batch the server rejects as invalid, so it cannot block the queue', function () {
        $analytics = analytics(new FakeTransport(new HttpResponse(422)));
        freshSignatures($analytics);
        $analytics->record(new Hit('/', GPTBOT_UA));

        $analytics->flush();

        expect($analytics->storage->stats()['count'])->toBe(0)
            ->and($analytics->storage->state()['last_error'])->toBe('rejected');
    });

    it('honours Retry-After when rate limited', function () {
        $analytics = analytics(new FakeTransport(new HttpResponse(429, ['retry-after' => '120'])));
        freshSignatures($analytics);
        $analytics->record(new Hit('/', GPTBOT_UA));

        $analytics->flush();

        expect($analytics->storage->state()['next_attempt_at'])->toBeGreaterThanOrEqual(time() + 119)
            ->and($analytics->storage->stats()['count'])->toBe(1);
    });

    it('sends more than 1000 items in several batches', function () {
        $transport = new FakeTransport;
        $analytics = analytics($transport);
        freshSignatures($analytics);

        foreach (range(1, 1500) as $i) {
            $analytics->storage->push(['kind' => 'event', 'ts' => gmdate('Y-m-d\TH:i:s\Z'), 'ua' => GPTBOT_UA, 'path' => "/{$i}"], 20000);
        }

        $analytics->flush();

        expect($transport->requests)->toHaveCount(2)
            ->and(json_decode($transport->requests[0]['body'], true)['events'])->toHaveCount(1000)
            ->and(json_decode($transport->requests[1]['body'], true)['events'])->toHaveCount(500)
            ->and($analytics->storage->stats()['count'])->toBe(0);
    });

    it('lets only one request flush at a time', function () {
        $transport = new FakeTransport;
        $analytics = analytics($transport);
        freshSignatures($analytics);
        $analytics->record(new Hit('/', GPTBOT_UA));

        $other = new FileStorage($this->storagePath);
        expect($other->acquireFlushLock())->toBeTrue();

        expect($analytics->flush())->toBeNull()
            ->and($transport->requests)->toBe([]);

        $other->releaseFlushLock();
    });
});

describe('deciding when to flush', function () {
    it('waits for a full batch while the oldest item is young', function () {
        $analytics = analytics(new FakeTransport, ['batchSize' => 3]);
        freshSignatures($analytics);
        $analytics->storage->putState(['totals_sent_at' => time()]);

        $analytics->record(new Hit('/1', GPTBOT_UA));
        $analytics->record(new Hit('/2', GPTBOT_UA));
        expect($analytics->shouldFlush())->toBeFalse();

        $analytics->record(new Hit('/3', GPTBOT_UA));
        expect($analytics->shouldFlush())->toBeTrue();
    });

    it('flushes once the oldest item has waited long enough', function () {
        $analytics = analytics(new FakeTransport, ['flushIntervalSeconds' => 60]);
        freshSignatures($analytics);
        $analytics->storage->putState(['totals_sent_at' => time()]);

        $analytics->storage->push(['kind' => 'event', 'ts' => gmdate('Y-m-d\TH:i:s\Z', time() - 120), 'ua' => GPTBOT_UA, 'path' => '/'], 100);
        // push() stamps the buffer time as now; a rewrite recomputes it from the item timestamps.
        $analytics->storage->remove([]);

        expect($analytics->shouldFlush())->toBeTrue();
    });

    it('sends totals on their own every 15 minutes', function () {
        $analytics = analytics(new FakeTransport);
        freshSignatures($analytics);
        $analytics->storage->putState(['totals_sent_at' => time() - AgentAnalytics::TOTALS_INTERVAL_SECONDS]);

        expect($analytics->shouldFlush())->toBeTrue();
    });
});

describe('signatures', function () {
    it('falls back to the bundled list before the first fetch', function () {
        expect(analytics(new FakeTransport)->signatures()->bots)->not->toBeEmpty();
    });

    it('fetches the catalogue, stores its etag and revalidates with it', function () {
        $transport = new FakeTransport(
            new HttpResponse(200, ['etag' => '"abc"'], json_encode(['version' => 'v2', 'ttl' => 86400, 'bots' => [['pattern' => 'NewBot', 'name' => 'NewBot']], 'referrers' => []])),
            new HttpResponse(304),
        );
        $analytics = analytics($transport);

        $analytics->refreshSignatures();

        expect($transport->requests[0]['url'])->toBe('https://survelium.test/api/v1/signatures')
            ->and($analytics->signatures()->matchBot('NewBot/1.0')['name'])->toBe('NewBot')
            ->and($analytics->storage->signatures()['etag'])->toBe('"abc"');

        $analytics->refreshSignatures();

        expect($transport->requests[1]['headers']['If-None-Match'])->toBe('"abc"')
            ->and(analytics(new FakeTransport)->signatures()->version)->toBe('v2');
    });

    it('keeps the bundled list and waits a day when the server cannot be reached', function () {
        $analytics = analytics(new FakeTransport(new HttpResponse(0)));

        $analytics->refreshSignatures();

        expect($analytics->signatures()->matchBot(GPTBOT_UA))->not->toBeNull()
            ->and($analytics->storage->signatures()['checked_at'])->toBeGreaterThanOrEqual(time() - 1);
    });

    it('refreshes stale signatures during a flush', function () {
        $transport = new FakeTransport(new HttpResponse(304), new HttpResponse(202));
        $analytics = analytics($transport);
        $analytics->storage->putSignatures(['payload' => ['bots' => [], 'referrers' => [], 'ttl' => 86400], 'etag' => '"old"', 'checked_at' => time() - 90000]);

        $analytics->flush();

        expect($transport->requests[0]['method'])->toBe('GET')
            ->and($analytics->storage->signatures()['checked_at'])->toBeGreaterThanOrEqual(time() - 1);
    });
});

function freshSignatures(AgentAnalytics $analytics): void
{
    $bundled = Signatures();
    $analytics->storage->putSignatures(['payload' => ['bots' => $bundled->bots, 'referrers' => $bundled->referrers], 'etag' => null, 'checked_at' => time()]);
}

function Signatures(): Signatures
{
    return Signatures::bundled();
}
