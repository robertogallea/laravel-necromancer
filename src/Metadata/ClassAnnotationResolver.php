<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

use InvalidArgumentException;
use LaravelNecromancer\Attributes\Necromancer;

/**
 * Converts a directly-declared #[Necromancer] attribute into Annotation Schema v1.
 */
final readonly class ClassAnnotationResolver
{
    public function resolve(?Necromancer $attribute): ArtifactAnnotations
    {
        if ($attribute === null) {
            return new ArtifactAnnotations;
        }

        return new ArtifactAnnotations(
            domain: $this->scalar('domain', $attribute->domain),
            flow: $this->scalar('flow', $attribute->flow),
            capability: $this->scalar('capability', $attribute->capability),
            summary: $this->scalar('summary', $attribute->summary),
            risk: $attribute->risk,
            externalServices: $this->list('external_services', $attribute->externalServices),
            adrs: $this->list('adrs', $attribute->adrs),
        );
    }

    private function scalar(string $field, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException("Invalid Necromancer annotation {$field}: strings must not be empty.");
        }

        return $trimmed;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function list(string $field, array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("Invalid Necromancer annotation {$field}: list items must be non-empty strings.");
            }

            $trimmed = trim($value);

            if (! in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }
}
