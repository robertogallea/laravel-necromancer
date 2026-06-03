<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use JsonSerializable;

final readonly class ConfigurationSummary implements JsonSerializable
{
    /**
     * @param  list<string>  $keys
     */
    private function __construct(public array $keys) {}

    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function fromArray(array $configuration): self
    {
        return new self(self::keysFrom($configuration));
    }

    /**
     * @return array{keys: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'keys' => $this->keys,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    private static function keysFrom(array $configuration, string $prefix = ''): array
    {
        $keys = [];

        foreach ($configuration as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            $keys[] = $path;

            if (is_array($value)) {
                array_push($keys, ...self::keysFrom($value, $path));
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_NATURAL);

        return $keys;
    }
}
