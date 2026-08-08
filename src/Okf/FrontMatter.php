<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

/**
 * A minimal, self-contained, deterministic YAML block-style serializer for
 * Knowledge Bundle front matter. Every string scalar is emitted through
 * json_encode(), which is valid YAML flow-scalar syntax and sidesteps the
 * usual bare/quoted-string ambiguity YAML dumpers have to resolve — the
 * same input always produces the same bytes, which the OKF exporter's
 * determinism guarantee depends on. Null scalars and empty arrays are
 * omitted rather than emitted, matching ArtifactAnnotations::jsonSerialize().
 */
final readonly class FrontMatter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function dump(array $data): string
    {
        return implode("\n", self::mapping($data, 0));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function mapping(array $data, int $indent): array
    {
        $pad = str_repeat('  ', $indent);
        $lines = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }

                $lines[] = "{$pad}{$key}:";
                $lines = [...$lines, ...self::collection($value, $indent + 1)];

                continue;
            }

            if ($value === null) {
                continue;
            }

            $lines[] = "{$pad}{$key}: ".self::scalar($value);
        }

        return $lines;
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return list<string>
     */
    private static function collection(array $value, int $indent): array
    {
        return array_is_list($value)
            ? self::sequence($value, $indent)
            : self::mapping($value, $indent);
    }

    /**
     * @param  list<mixed>  $items
     * @return list<string>
     */
    private static function sequence(array $items, int $indent): array
    {
        $pad = str_repeat('  ', $indent);
        $lines = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $nested = self::collection($item, $indent + 1);
                $first = array_shift($nested) ?? '';
                $lines[] = "{$pad}- ".ltrim($first);
                $lines = [...$lines, ...$nested];

                continue;
            }

            $lines[] = "{$pad}- ".self::scalar($item);
        }

        return $lines;
    }

    private static function scalar(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
