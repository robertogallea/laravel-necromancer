<?php

declare(strict_types=1);

namespace LaravelNecromancer\Integrations;

final class McpInstaller
{
    private const SERVER_KEY = 'laravel-necromancer';

    public function __construct(private readonly string $mcpJsonPath) {}

    public function ensureRegistered(): void
    {
        $config = $this->readConfig();

        if (isset($config['mcpServers'][self::SERVER_KEY])) {
            return;
        }

        $config['mcpServers'][self::SERVER_KEY] = [
            'command' => 'php',
            'args' => ['artisan', 'mcp:start', 'necromancer'],
        ];

        file_put_contents(
            $this->mcpJsonPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    /** @return array<string, mixed> */
    private function readConfig(): array
    {
        if (! is_file($this->mcpJsonPath)) {
            return [];
        }

        $content = file_get_contents($this->mcpJsonPath);

        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
