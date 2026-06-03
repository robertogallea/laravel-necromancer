<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest\ArtifactPayloads;

use JsonSerializable;

final readonly class FormRequestPayload implements JsonSerializable
{
    /**
     * @param  array<string, string>  $rules
     * @param  array<string, mixed>|null  $source
     */
    public function __construct(
        public string $class,
        public array $rules,
        public ?array $source,
        public bool $stopOnFirstFailure = false,
        public ?string $errorBag = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'class' => $this->class,
            'rules' => $this->rules,
        ];

        if ($this->stopOnFirstFailure) {
            $data['stop_on_first_failure'] = true;
        }

        if ($this->errorBag !== null) {
            $data['error_bag'] = $this->errorBag;
        }

        if ($this->source !== null) {
            $data['source'] = $this->source;
        }

        return $data;
    }
}
