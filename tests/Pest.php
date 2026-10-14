<?php

use Survelium\AgentAnalytics\AgentAnalytics;
use Survelium\AgentAnalytics\Config;
use Survelium\AgentAnalytics\Storage\FileStorage;
use Survelium\AgentAnalytics\Transport\FakeTransport;

const GPTBOT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';
const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

function temporaryStoragePath(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'survelium-test-'.bin2hex(random_bytes(6));
    mkdir($path);

    return $path;
}

function removeDirectory(string $path): void
{
    foreach (glob($path.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        is_dir($file) ? removeDirectory($file) : unlink($file);
    }

    @rmdir($path);
}

/**
 * @param  array<string, mixed>  $config
 */
function analytics(FakeTransport $transport, array $config = []): AgentAnalytics
{
    $path = test()->storagePath;

    return AgentAnalytics::make(
        new Config(...array_merge(['apiKey' => 'svk_test', 'endpoint' => 'https://survelium.test', 'storagePath' => $path], $config)),
        new FileStorage($path),
        $transport,
    );
}

uses()
    ->beforeEach(function () {
        $this->storagePath = temporaryStoragePath();
    })
    ->afterEach(function () {
        removeDirectory($this->storagePath);
    })
    ->in(__DIR__);
