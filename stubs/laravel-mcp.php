<?php

declare(strict_types=1);

/**
 * Stub definitions for the optional laravel/mcp package.
 * These allow PHPStan to analyse MCP integration code
 * without requiring the package to be installed.
 */

namespace Laravel\Mcp {

    abstract class Server
    {
        protected string $name = '';

        protected string $instructions = '';

        /** @var array<class-string> */
        protected array $tools = [];
    }
}

namespace Laravel\Mcp\Server {
    use Illuminate\Contracts\JsonSchema\JsonSchema;

    abstract class Tool
    {
        abstract public function name(): string;

        abstract public function description(): string;

        /**
         * @return array<string, mixed>
         */
        abstract public function schema(JsonSchema $schema): array;
    }
}

namespace Laravel\Mcp\Facades {
    class Mcp
    {
        public static function local(string $handle, string $serverClass): void {}
    }
}
