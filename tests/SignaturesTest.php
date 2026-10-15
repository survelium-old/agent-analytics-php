<?php

use Survelium\AgentAnalytics\Signatures;

it('ships a bundled catalogue', function () {
    $signatures = Signatures::bundled();

    expect($signatures->bots)->not->toBeEmpty()
        ->and($signatures->referrers)->not->toBeEmpty()
        ->and($signatures->matchBot(GPTBOT_UA)['name'])->toBe('GPTBot');
});

it('prefers the longest pattern, whatever order it was given in', function () {
    $signatures = Signatures::fromArray(['bots' => [
        ['pattern' => 'Applebot', 'name' => 'Applebot'],
        ['pattern' => 'Applebot-Extended', 'name' => 'Applebot-Extended'],
    ]]);

    expect($signatures->matchBot('Mozilla/5.0 (compatible; Applebot-Extended/0.1)')['name'])->toBe('Applebot-Extended')
        ->and($signatures->matchBot('Mozilla/5.0 (compatible; Applebot/0.1)')['name'])->toBe('Applebot');
});

it('matches user agents case-sensitively, like the server', function () {
    $signatures = Signatures::fromArray(['bots' => [['pattern' => 'GPTBot', 'name' => 'GPTBot']]]);

    expect($signatures->matchBot('gptbot'))->toBeNull()
        ->and($signatures->matchBot(CHROME_UA))->toBeNull()
        ->and($signatures->matchBot(null))->toBeNull();
});

it('ignores malformed entries', function () {
    $signatures = Signatures::fromArray(['bots' => [['name' => 'no pattern'], ['pattern' => ''], 'junk'], 'referrers' => 'junk']);

    expect($signatures->bots)->toBe([])->and($signatures->referrers)->toBe([]);
});

it('recognises AI referrers by host, without www and in any case', function (?string $referrer, ?string $uri, ?string $expected) {
    $signatures = Signatures::fromArray(['referrers' => [
        ['domain' => 'chatgpt.com', 'name' => 'ChatGPT'],
        ['domain' => 'perplexity.ai', 'name' => 'Perplexity'],
    ]]);

    expect($signatures->matchReferrer($referrer, $uri))->toBe($expected);
})->with([
    'referrer host' => ['https://chatgpt.com/', '/pricing', 'chatgpt.com'],
    'www and upper case' => ['https://WWW.Perplexity.ai/search?q=x', '/', 'perplexity.ai'],
    'utm_source fallback' => [null, '/pricing?utm_source=chatgpt.com', 'chatgpt.com'],
    'subdomain is not the domain' => ['https://evil.chatgpt.com.example.org/', '/', null],
    'unknown referrer' => ['https://www.google.com/', '/', null],
    'nothing at all' => [null, '/', null],
]);

it('tells automated clients from browsers', function (?string $userAgent, bool $automated) {
    expect(Signatures::looksAutomated($userAgent))->toBe($automated);
})->with([
    'chrome' => [CHROME_UA, false],
    'safari on iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', false],
    'firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', false],
    'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36 Edg/141.0', false],
    'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true],
    'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', true],
    'petalbot' => ['Mozilla/5.0 (Linux; Android 7.0;) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)', true],
    'headless chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/141.0 Safari/537.36', true],
    'facebook preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', true],
    'uptime robot' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)', true],
    'curl' => ['curl/8.4.0', true],
    'python' => ['python-requests/2.32.3', true],
    'guzzle' => ['GuzzleHttp/7', true],
    'empty' => ['', true],
    'missing' => [null, true],
]);
