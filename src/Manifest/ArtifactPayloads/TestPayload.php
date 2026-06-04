<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class TestPayload implements JsonSerializable
{
    /**
     * @param  list<string>  $methods
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $file,
        public string $testType,
        public ?string $class,
        public ?string $subject,
        public array $methods,
        public ?array $source,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'file' => $this->file,
            'type' => $this->testType,
        ];

        if ($this->class !== null) {
            $data['class'] = $this->class;
        }

        if ($this->subject !== null) {
            $data['subject'] = $this->subject;
        }

        if ($this->methods !== []) {
            $data['methods'] = $this->methods;
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
