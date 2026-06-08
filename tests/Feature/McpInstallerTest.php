<?php

use Illuminate\Support\Facades\File;
use LaravelNecromancer\Integrations\McpInstaller;

beforeEach(function () {
    $this->mcpPath = tempnam(sys_get_temp_dir(), 'necromancer_mcp_');
    File::delete($this->mcpPath);
});

afterEach(function () {
    File::delete($this->mcpPath);
});

test('creates .mcp.json with necromancer entry when file does not exist', function () {
    (new McpInstaller($this->mcpPath))->ensureRegistered();

    $config = json_decode(File::get($this->mcpPath), true);

    expect($config['mcpServers']['laravel-necromancer'])->toBe([
        'command' => 'php',
        'args' => ['artisan', 'mcp:start', 'necromancer'],
    ]);
});

test('adds necromancer entry to existing .mcp.json without removing other servers', function () {
    File::put($this->mcpPath, json_encode([
        'mcpServers' => [
            'laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']],
        ],
    ], JSON_PRETTY_PRINT));

    (new McpInstaller($this->mcpPath))->ensureRegistered();

    $config = json_decode(File::get($this->mcpPath), true);

    expect($config['mcpServers'])->toHaveKeys(['laravel-boost', 'laravel-necromancer']);
});

test('does not overwrite existing necromancer entry', function () {
    $existing = [
        'command' => 'php',
        'args' => ['artisan', 'mcp:start', 'necromancer'],
    ];

    File::put($this->mcpPath, json_encode([
        'mcpServers' => ['laravel-necromancer' => $existing],
    ], JSON_PRETTY_PRINT));

    $originalMtime = filemtime($this->mcpPath);
    sleep(1);

    (new McpInstaller($this->mcpPath))->ensureRegistered();

    expect(filemtime($this->mcpPath))->toBe($originalMtime);
});

test('handles malformed .mcp.json gracefully by overwriting it', function () {
    File::put($this->mcpPath, 'not valid json');

    (new McpInstaller($this->mcpPath))->ensureRegistered();

    $config = json_decode(File::get($this->mcpPath), true);

    expect($config['mcpServers']['laravel-necromancer'])->toBeArray();
});
