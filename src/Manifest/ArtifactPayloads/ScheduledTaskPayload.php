<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class ScheduledTaskPayload implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $command,
        public string $expression,
        public string $humanReadable,
        public bool $withoutOverlapping,
        public bool $runInBackground,
        public bool $evenInMaintenance,
        public ?string $timezone,
        public ?string $description,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'command' => $this->command,
            'expression' => $this->expression,
            'human_readable' => $this->humanReadable,
            'without_overlapping' => $this->withoutOverlapping,
            'run_in_background' => $this->runInBackground,
            'even_in_maintenance' => $this->evenInMaintenance,
        ];

        if ($this->timezone !== null) {
            $data['timezone'] = $this->timezone;
        }

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
