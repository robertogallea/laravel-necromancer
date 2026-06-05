<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class MailablePayload implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public ?string $subject,
        public bool $queued,
        public ?string $queue,
        public ?string $view,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'subject' => $this->subject,
            'queued' => $this->queued,
            'queue' => $this->queue,
            'view' => $this->view,
        ];

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
