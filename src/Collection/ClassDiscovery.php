<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class ClassDiscovery
{
    /**
     * @param  list<array{path: string, namespace: string}>  $roots
     */
    public function __construct(private array $roots) {}

    /**
     * @return list<string>
     */
    public function classes(): array
    {
        $classes = [];

        foreach ($this->roots as $root) {
            if (! is_dir($root['path'])) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root['path'], RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $classes[] = $this->classFromFile($file, $root);
            }
        }

        $classes = array_values(array_unique(array_filter($classes)));
        sort($classes);

        return $classes;
    }

    /**
     * @param  array{path: string, namespace: string}  $root
     */
    private function classFromFile(SplFileInfo $file, array $root): string
    {
        $relativePath = substr($file->getPathname(), strlen(rtrim($root['path'], DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR));
        $classPath = substr($relativePath, 0, -4);

        return rtrim($root['namespace'], '\\').'\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $classPath);
    }
}
