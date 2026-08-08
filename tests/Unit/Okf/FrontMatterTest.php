<?php

use LaravelNecromancer\Okf\FrontMatter;

test('dump() renders scalar fields in insertion order', function () {
    $yaml = FrontMatter::dump(['title' => 'Order', 'tries' => 3, 'queued' => true]);

    expect($yaml)->toBe("title: \"Order\"\ntries: 3\nqueued: true");
});

test('dump() renders a nested map as an indented block', function () {
    $yaml = FrontMatter::dump([
        'necromancer' => ['schema_version' => 1, 'id' => 'jobs:App\\Jobs\\SendInvoice'],
    ]);

    expect($yaml)->toBe("necromancer:\n  schema_version: 1\n  id: \"jobs:App\\\\Jobs\\\\SendInvoice\"");
});

test('dump() renders a list of scalars as a block sequence', function () {
    $yaml = FrontMatter::dump(['tags' => ['billing', 'invoice.send']]);

    expect($yaml)->toBe("tags:\n  - \"billing\"\n  - \"invoice.send\"");
});

test('dump() omits null scalar values entirely', function () {
    $yaml = FrontMatter::dump(['summary' => null, 'title' => 'Order']);

    expect($yaml)->toBe('title: "Order"');
});

test('dump() omits empty array values entirely', function () {
    $yaml = FrontMatter::dump(['tags' => [], 'title' => 'Order']);

    expect($yaml)->toBe('title: "Order"');
});

test('dump() escapes backslashes and quotes in strings so values round-trip exactly via JSON decoding', function () {
    $yaml = FrontMatter::dump(['id' => 'jobs:App\\Jobs\\"Weird"Name']);

    $value = trim(explode(': ', $yaml, 2)[1]);
    expect(json_decode($value, true, 512, JSON_THROW_ON_ERROR))->toBe('jobs:App\\Jobs\\"Weird"Name');
});

test('dump() does not escape forward slashes in string values', function () {
    $yaml = FrontMatter::dump(['file' => 'app/Jobs/SendInvoice.php']);

    expect($yaml)->toBe('file: "app/Jobs/SendInvoice.php"');
});

test('dump() is deterministic across repeated calls with the same input', function () {
    $data = ['b' => 2, 'a' => ['x' => 1, 'y' => [1, 2, 3]], 'c' => null];

    expect(FrontMatter::dump($data))->toBe(FrontMatter::dump($data));
});

test('dump() renders a list of maps as a block sequence of nested mappings', function () {
    $yaml = FrontMatter::dump([
        'relationships' => [
            ['type' => 'hasMany', 'related' => 'Item'],
        ],
    ]);

    expect($yaml)->toBe("relationships:\n  - type: \"hasMany\"\n    related: \"Item\"");
});
