<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final readonly class TestFileParser
{
    public function __construct(private string $absolutePath) {}

    public function isPestFunctional(): bool
    {
        $content = $this->content();

        return (bool) preg_match('/^\s*(test|it)\s*\(/m', $content);
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        if ($this->isPestFunctional()) {
            return $this->parsePestMethods();
        }

        return $this->parseClassMethods();
    }

    public function declaredClass(): ?string
    {
        $content = $this->content();

        if (! preg_match('/^namespace\s+([\w\\\\]+)/m', $content, $nsMatch)) {
            $namespace = '';
        } else {
            $namespace = $nsMatch[1].'\\';
        }

        if (! preg_match('/^class\s+(\w+)/m', $content, $classMatch)) {
            return null;
        }

        return $namespace.$classMatch[1];
    }

    /**
     * Returns the first non-TestCase, non-Tests-namespace class from uses() declarations.
     */
    public function usesSubject(): ?string
    {
        $content = $this->content();
        $imports = $this->importMap($content);

        preg_match_all('/uses\s*\(\s*\\\\?([A-Za-z][A-Za-z0-9\\\\]*?)::class/m', $content, $matches);

        foreach ($matches[1] as $captured) {
            // Resolve short name via import map if available
            $fqcn = $imports[$captured] ?? $captured;

            if ($this->isTestCaseOrTrait($fqcn)) {
                continue;
            }

            return $fqcn;
        }

        return null;
    }

    /**
     * Returns a map of short name → FQCN for all `use` import statements in the content.
     *
     * @return array<string,string>
     */
    private function importMap(string $content): array
    {
        $map = [];

        // Match: use Foo\Bar\Baz; and use Foo\Bar\Baz as Alias;
        preg_match_all('/^use\s+([\w\\\\]+?)(?:\s+as\s+(\w+))?\s*;/m', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $fqcn = $match[1];
            $alias = ($match[2] ?? '') !== '' ? $match[2] : class_basename($fqcn);
            $map[$alias] = $fqcn;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function parsePestMethods(): array
    {
        $content = $this->content();
        $methods = [];

        preg_match_all('/(?:^|\n)\s*(?:test|it)\s*\(\s*([\'"])(.*?)\1/s', $content, $matches);

        foreach ($matches[2] as $name) {
            $name = trim($name);
            if ($name !== '') {
                $methods[] = $name;
            }
        }

        return $methods;
    }

    /**
     * @return list<string>
     */
    private function parseClassMethods(): array
    {
        $fqcn = $this->declaredClass();

        if ($fqcn !== null && class_exists($fqcn)) {
            try {
                return $this->reflectionMethods($fqcn);
            } catch (Throwable) {
                // fall through to regex fallback
            }
        }

        return $this->regexClassMethods();
    }

    /**
     * @return list<string>
     */
    private function reflectionMethods(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test')) {
                $methods[] = $method->getName();

                continue;
            }

            // Use getAttributes() for presence detection only — does not call newInstance(),
            // so PHPUnit\Framework\Attributes\Test does not need to be loadable.
            if ($method->getAttributes(Test::class) !== []) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    /**
     * @return list<string>
     */
    private function regexClassMethods(): array
    {
        $content = $this->content();
        $methods = [];

        preg_match_all('/public\s+function\s+(test\w+)\s*\(/m', $content, $matches);

        foreach ($matches[1] as $name) {
            $methods[] = $name;
        }

        return $methods;
    }

    private function isTestCaseOrTrait(string $fqcn): bool
    {
        $basename = class_basename($fqcn);

        // Skip TestCase classes (e.g. Tests\TestCase, Illuminate\Testing\TestCase)
        if (str_contains($basename, 'TestCase')) {
            return true;
        }

        // Skip classes whose short name ends with Test — they are test helpers, not subjects
        if (str_ends_with($basename, 'Test')) {
            return true;
        }

        // Skip the standard Laravel app test namespace (e.g. Tests\TestCase)
        if ($fqcn === 'Tests\\TestCase' || $fqcn === 'TestCase') {
            return true;
        }

        return false;
    }

    private function content(): string
    {
        return (string) file_get_contents($this->absolutePath);
    }
}
