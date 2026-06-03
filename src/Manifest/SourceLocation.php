<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use JsonSerializable;

final readonly class SourceLocation implements JsonSerializable
{
    public function __construct(
        public string $file,
        public int $line,
        public ?int $line_end = null,
        public ?string $hash = null,
    ) {}

    /**
     * @return array{file: string, line: int, line_end: int|null, hash: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'line_end' => $this->line_end,
            'hash' => $this->hash,
        ];
    }
}
