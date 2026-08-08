<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

/**
 * A serialized manifest artifact reduced to what audit/Doctor need to reason
 * about its resolved Artifact Annotations generically, regardless of family.
 */
final readonly class AnnotatedArtifact
{
    /**
     * @param  array<string, mixed>  $annotations
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $label,
        public ?string $subject,
        public array $annotations,
        public ?string $source,
    ) {}

    /**
     * Flattens every artifact family into the subset that declares
     * Annotation Schema v1 data, so audit checks and the Doctor coverage
     * dimension can reason about developer intent uniformly instead of
     * special-casing routes.
     *
     * @param  array<string, mixed>  $artifacts
     * @return list<self>
     */
    public static function collect(array $artifacts): array
    {
        $collected = [];

        foreach ($artifacts as $type => $items) {
            if (! is_string($type) || ! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item) || empty($item['annotations']) || ! is_array($item['annotations'])) {
                    continue;
                }

                $collected[] = new self(
                    type: $type,
                    id: (string) ($item['id'] ?? ''),
                    label: self::label($type, $item),
                    subject: self::subject($type, $item),
                    annotations: $item['annotations'],
                    source: isset($item['source']['file'])
                        ? $item['source']['file'].':'.($item['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return $collected;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function label(string $type, array $item): string
    {
        return match ($type) {
            'routes' => trim(($item['method'] ?? '').' '.($item['uri'] ?? '')),
            'tests' => (string) ($item['file'] ?? $item['id'] ?? ''),
            'gates' => 'gate:'.($item['ability'] ?? ''),
            'scheduled_tasks' => 'scheduled task: '.($item['command'] ?? ''),
            'middleware' => ($item['class'] ?? '').' ('.($item['scope'] ?? '').')',
            default => (string) ($item['class'] ?? $item['id'] ?? $type),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function subject(string $type, array $item): ?string
    {
        $value = match ($type) {
            'routes' => $item['controller'] ?? null,
            'tests' => $item['subject'] ?? null,
            default => $item['class'] ?? null,
        };

        return is_string($value) && $value !== '' ? $value : null;
    }
}
