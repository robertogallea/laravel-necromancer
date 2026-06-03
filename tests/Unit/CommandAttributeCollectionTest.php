<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use LaravelNecromancer\Collection\CommandCollector;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Tests\Fixtures\Commands\CommandWithAliases;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('command-attributes');

test('CommandCollector reads #[Aliases] into aliases field', function () {
    app(Kernel::class)->registerCommand(new CommandWithAliases);

    $collector = new CommandCollector(
        app: app(),
        roots: [[
            'path' => base_path('tests/Fixtures/Commands'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Commands\\',
        ]],
    );

    $artifacts = $collector->collect();
    $data = collect($artifacts)
        ->map(fn ($a) => $a->jsonSerialize())
        ->firstWhere('class', CommandWithAliases::class);

    expect($data)->not->toBeNull()
        ->and($data['aliases'])->toContain('oc')
        ->and($data['aliases'])->toContain('orders:clean');
});

test('aliases field is omitted from JSON when no #[Aliases] attribute is present', function () {
    $artifact = StructuralArtifact::command(
        class: 'App\\Console\\Commands\\PruneOrders',
        signature: 'orders:prune',
        description: 'Prune orders',
    );
    expect($artifact->jsonSerialize())->not->toHaveKey('aliases');
});
