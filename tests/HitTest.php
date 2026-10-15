<?php

use Survelium\AgentAnalytics\Config;
use Survelium\AgentAnalytics\Hit;

it('reads the request from server variables', function () {
    $hit = Hit::fromGlobals(new Config('svk_test'), 404, [
        'REQUEST_URI' => '/blog/post?x=1',
        'HTTP_USER_AGENT' => GPTBOT_UA,
        'HTTP_REFERER' => 'https://chatgpt.com/',
        'REMOTE_ADDR' => '20.171.206.10',
    ]);

    expect($hit->path)->toBe('/blog/post?x=1')
        ->and($hit->userAgent)->toBe(GPTBOT_UA)
        ->and($hit->referrer)->toBe('https://chatgpt.com/')
        ->and($hit->ip)->toBe('20.171.206.10')
        ->and($hit->status)->toBe(404);
});

it('takes the client IP from the configured proxy header, first in the list', function () {
    $hit = Hit::fromGlobals(new Config('svk_test', ipHeader: 'HTTP_X_FORWARDED_FOR'), null, [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '20.171.206.10, 10.0.0.1',
    ]);

    expect($hit->ip)->toBe('20.171.206.10');
});

it('ignores proxy headers unless configured', function () {
    $hit = Hit::fromGlobals(new Config('svk_test'), null, [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '20.171.206.10',
    ]);

    expect($hit->ip)->toBe('10.0.0.1');
});

it('normalises full URLs to path and query', function () {
    expect((new Hit('https://example.com/a/b?c=1'))->normalizedPath())->toBe('/a/b?c=1')
        ->and((new Hit('relative'))->normalizedPath())->toBe('/relative')
        ->and((new Hit(''))->normalizedPath())->toBe('/');
});

it('drops IPs that are not IPs', function () {
    expect((new Hit('/', ip: 'unknown'))->validIp())->toBeNull()
        ->and((new Hit('/', ip: '2a06:98c0::1'))->validIp())->toBe('2a06:98c0::1');
});

it('treats only plain GET/HEAD requests as page views', function (array $server, bool $pageView) {
    expect(Hit::isPageView($server))->toBe($pageView);
})->with([
    'get' => [['REQUEST_METHOD' => 'GET'], true],
    'head' => [['REQUEST_METHOD' => 'HEAD'], true],
    'no method (cli)' => [[], true],
    'post' => [['REQUEST_METHOD' => 'POST'], false],
    'xhr' => [['REQUEST_METHOD' => 'GET', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], false],
    'prefetch' => [['REQUEST_METHOD' => 'GET', 'HTTP_SEC_PURPOSE' => 'prefetch;prerender'], false],
]);
