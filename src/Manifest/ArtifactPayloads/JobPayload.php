<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class JobPayload implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public ?string $queue,
        public ?string $connection,
        public string|int|null $tries,
        public ?int $timeout,
        public ?array $source,
        public array|int|null $backoff = null,
        public ?int $maxExceptions = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'tries' => $this->tries,
            'timeout' => $this->timeout,
        ];

        if ($this->backoff !== null) {
            $data['backoff'] = $this->backoff;
        }

        if ($this->maxExceptions !== null) {
            $data['max_exceptions'] = $this->maxExceptions;
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
