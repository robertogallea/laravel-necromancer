<?php

use LaravelNecromancer\Collection\SourceFileParser;
use LaravelNecromancer\Tests\TestCase;
use PhpParser\Error;
use PhpParser\Node\Expr\New_;
use PhpParser\NodeFinder;

uses(TestCase::class);

/**
 * Runs $callback with the path of a temporary file holding $source.
 */
function withTemporarySource(string $source, Closure $callback): mixed
{
    $file = sys_get_temp_dir().'/necromancer-source-'.uniqid().'.php';
    file_put_contents($file, $source);

    try {
        return $callback($file);
    } finally {
        unlink($file);
    }
}

test('parse() returns statements with names resolved through the file imports', function () {
    $statements = withTemporarySource("<?php\n\nuse App\\Models\\Order;\n\nnew Order;\n", fn (string $file): ?array => (new SourceFileParser)->parse($file));

    $new = (new NodeFinder)->findFirstInstanceOf($statements, New_::class);

    expect($new->class->toString())->toBe('App\\Models\\Order');
});

test('parse() returns null for a file that does not exist', function () {
    expect((new SourceFileParser)->parse(sys_get_temp_dir().'/necromancer-missing-'.uniqid().'.php'))->toBeNull();
});

test('parse() throws on a syntax error', function () {
    withTemporarySource("<?php\n\nfunction broken( {\n", fn (string $file): ?array => (new SourceFileParser)->parse($file));
})->throws(Error::class);
