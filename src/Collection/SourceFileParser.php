<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Reads a PHP source file into name-resolved statements, for the scan's
 * source-text facts (ADR 0020, ADR 0024). Callers own caching and their
 * diagnostics.
 */
final readonly class SourceFileParser
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    /**
     * @param  string  $file  absolute, or relative to the application base path
     * @return array<Node>|null null when the file doesn't exist or can't be read
     *
     * @throws Error when the source isn't valid PHP
     */
    public function parse(string $file): ?array
    {
        $path = str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : base_path($file);
        $source = is_file($path) ? file_get_contents($path) : false;

        if ($source === false) {
            return null;
        }

        return (new NodeTraverser(new NameResolver))->traverse($this->parser->parse($source) ?? []);
    }
}
