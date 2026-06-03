<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use ReflectionClass;
use ReflectionMethod;

final class AttributeReader
{
    /**
     * @template T of object
     * @param  class-string<T>  $attributeClass
     * @return T|null
     */
    public static function first(ReflectionClass|ReflectionMethod $reflection, string $attributeClass): ?object
    {
        $attrs = $reflection->getAttributes($attributeClass);

        return empty($attrs) ? null : $attrs[0]->newInstance();
    }

    /**
     * @template T of object
     * @param  class-string<T>  $attributeClass
     * @return list<T>
     */
    public static function all(ReflectionClass|ReflectionMethod $reflection, string $attributeClass): array
    {
        return array_map(fn ($a) => $a->newInstance(), $reflection->getAttributes($attributeClass));
    }
}
