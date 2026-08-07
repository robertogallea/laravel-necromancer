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
    /**
     * @param  string  $context  A human-readable declaration site (e.g. a FQCN or
     *                           "Controller::action") included in validation errors
     *                           so a fatal failure can be traced back to its source.
     */
    public function resolve(?Necromancer $attribute, string $context = ''): ArtifactAnnotations
    {
        if ($attribute === null) {
            return new ArtifactAnnotations;
        }

        return new ArtifactAnnotations(
            domain: $this->scalar('domain', $attribute->domain, $context),
            flow: $this->scalar('flow', $attribute->flow, $context),
            capability: $this->scalar('capability', $attribute->capability, $context),
            summary: $this->scalar('summary', $attribute->summary, $context),
            risk: $attribute->risk,
            externalServices: $this->list('external_services', $attribute->externalServices, $context),
            adrs: $this->list('adrs', $attribute->adrs, $context),
        );
    }

    private function scalar(string $field, ?string $value, string $context): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException($this->message($context, "{$field} must not be an empty string."));
        }

        return $trimmed;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function list(string $field, array $values, string $context): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException($this->message($context, "{$field} list items must be non-empty strings."));
            }

            $trimmed = trim($value);

            if (! in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }

    private function message(string $context, string $detail): string
    {
        return $context === ''
            ? "Invalid Necromancer annotation: {$detail}"
            : "Invalid Necromancer annotation on {$context}: {$detail}";
    }
}
