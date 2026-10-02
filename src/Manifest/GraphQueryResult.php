<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

/**
 * The answer to a graph query: either a JSON-serializable payload or an
 * error whose body is {"error": <code>, "message": <string>, ...extra}.
 */
final readonly class GraphQueryResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    private function __construct(
        public array $body,
        public bool $isError,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function success(array $payload): self
    {
        return new self($payload, isError: false);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function error(string $code, string $message, array $extra = []): self
    {
        return new self(['error' => $code, 'message' => $message, ...$extra], isError: true);
    }

    /**
     * The body as the MCP graph tools encode it.
     */
    public function toJson(): string
    {
        return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
