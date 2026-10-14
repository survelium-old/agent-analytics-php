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
