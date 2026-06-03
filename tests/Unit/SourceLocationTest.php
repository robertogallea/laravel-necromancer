<?php

declare(strict_types=1);

use LaravelNecromancer\Manifest\SourceLocation;

test('SourceLocation serialises line_end and hash when set', function () {
    $loc = new SourceLocation(
        file: 'app/Models/Order.php',
        line: 10,
        line_end: 45,
        hash: 'abc123def456abc123def456abc12345',
    );

    $arr = $loc->jsonSerialize();

    expect($arr)->toHaveKey('line_end', 45)
        ->and($arr)->toHaveKey('hash', 'abc123def456abc123def456abc12345');
});

test('SourceLocation serialises null line_end and hash when omitted', function () {
    $loc = new SourceLocation(file: 'app/Foo.php', line: 1);

    $arr = $loc->jsonSerialize();

    expect($arr['line_end'])->toBeNull()
        ->and($arr['hash'])->toBeNull();
});

test('SourceLocation preserves all four fields', function () {
    $loc = new SourceLocation(file: 'app/Bar.php', line: 5, line_end: 20, hash: 'deadbeef');

    expect($loc->file)->toBe('app/Bar.php')
        ->and($loc->line)->toBe(5)
        ->and($loc->line_end)->toBe(20)
        ->and($loc->hash)->toBe('deadbeef');
});
