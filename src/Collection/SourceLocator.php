<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use LaravelNecromancer\Manifest\SourceLocation;
use ReflectionClass;
use ReflectionFunctionAbstract;

final readonly class SourceLocator
{
    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $reflection
     */
    public function forClass(ReflectionClass $reflection): ?SourceLocation
    {
        $file = $reflection->getFileName();
        $line = $reflection->getStartLine();

        if (! is_string($file) || $line === false) {
            return null;
        }

        return new SourceLocation(
            file: $this->relativePath($file),
            line: $line,
            line_end: $reflection->getEndLine() ?: null,
            hash: is_file($file) ? (md5_file($file) ?: null) : null,
        );
    }

    public function forFunction(ReflectionFunctionAbstract $reflection): ?SourceLocation
    {
        $file = $reflection->getFileName();
        $line = $reflection->getStartLine();

        if (! is_string($file) || $line === false) {
            return null;
        }

        return new SourceLocation(
            file: $this->relativePath($file),
            line: $line,
            line_end: $reflection->getEndLine() ?: null,
            hash: is_file($file) ? (md5_file($file) ?: null) : null,
        );
    }

    private function relativePath(string $path): string
    {
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $basePath)) {
            return substr($path, strlen($basePath));
        }

        return $path;
    }
}
