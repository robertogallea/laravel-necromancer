<?php

use LaravelNecromancer\Collection\DispatchFactResolver;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class);

/**
 * @return array<string, mixed>
 */
function dispatchingArtifact(string $class, string $file, string $id = 'actions:x'): array
{
    return ['id' => $id, 'class' => $class, 'source' => ['file' => $file, 'line' => 1]];
}

/**
 * @param  array<string, mixed>  $artifact
 * @return array{0: array<string, mixed>, 1: list<string>}
 */
function resolveDispatches(array $artifact, string $type = 'actions'): array
{
    [$artifacts, $diagnostics] = (new DispatchFactResolver)->apply([$type => [$artifact]]);

    return [$artifacts[$type][0], $diagnostics];
}

test('a static X::dispatch() call is a queued dispatch of the imported class', function () {
    [$artifact] = resolveDispatches(dispatchingArtifact(
        'LaravelNecromancer\\Tests\\Fixtures\\Dispatches\\NecromancerPlaceOrder',
        'tests/Fixtures/Dispatches/NecromancerPlaceOrder.php',
    ));

    expect($artifact['dispatches'])->toBe([
        ['target' => 'LaravelNecromancer\\Tests\\Fixtures\\Jobs\\NecromancerQueuedJob', 'method' => 'handle', 'mode' => 'queued'],
    ]);
});

test('every supported call shape is recorded with its target, method and mode, sorted by method then target', function () {
    [$artifact] = resolveDispatches(dispatchingArtifact(
        'LaravelNecromancer\\Tests\\Fixtures\\Dispatches\\NecromancerCheckout',
        'tests/Fixtures/Dispatches/NecromancerCheckout.php',
    ));

    $target = fn (string $short): string => "LaravelNecromancer\\Tests\\Fixtures\\Dispatches\\Targets\\{$short}";

    expect($artifact['dispatches'])->toBe([
        ['target' => $target('ChargeCard'), 'method' => 'chained', 'mode' => 'queued'],
        ['target' => $target('ShipOrder'), 'method' => 'chained', 'mode' => 'queued'],
        ['target' => $target('ChargeCard'), 'method' => 'events', 'mode' => null],
        ['target' => $target('OrderPlaced'), 'method' => 'events', 'mode' => null],
        ['target' => $target('ShipOrder'), 'method' => 'events', 'mode' => null],
        ['target' => $target('OrderReceipt'), 'method' => 'mailQueued', 'mode' => 'queued'],
        ['target' => $target('ShipOrder'), 'method' => 'mailQueued', 'mode' => 'queued'],
        ['target' => $target('ChargeCard'), 'method' => 'mailSync', 'mode' => 'sync'],
        ['target' => $target('OrderReceipt'), 'method' => 'mailSync', 'mode' => 'sync'],
        ['target' => $target('ChargeCard'), 'method' => 'queuedBus', 'mode' => 'queued'],
        ['target' => $target('ShipOrder'), 'method' => 'queuedBus', 'mode' => 'queued'],
        ['target' => $target('ChargeCard'), 'method' => 'queuedHelper', 'mode' => 'queued'],
        ['target' => $target('ChargeCard'), 'method' => 'queuedStatic', 'mode' => 'queued'],
        ['target' => $target('OrderPlaced'), 'method' => 'queuedStatic', 'mode' => 'queued'],
        ['target' => $target('ShipOrder'), 'method' => 'queuedStatic', 'mode' => 'queued'],
        ['target' => $target('ChargeCard'), 'method' => 'syncHelper', 'mode' => 'sync'],
        ['target' => $target('OrderPlaced'), 'method' => 'syncHelper', 'mode' => 'sync'],
        ['target' => $target('ShipOrder'), 'method' => 'syncHelper', 'mode' => 'sync'],
    ]);
});

test('imported, aliased and fully-qualified names resolve to the same target', function () {
    [$importedAndAliased] = resolveDispatches(dispatchingArtifact(
        'LaravelNecromancer\\Tests\\Fixtures\\Dispatches\\NecromancerNameForms',
        'tests/Fixtures/Dispatches/NecromancerNameForms.php',
    ));

    // Written at runtime: Pint would rewrite a fully-qualified name in a fixture into an import.
    $file = sys_get_temp_dir().'/necromancer-dispatch-'.uniqid().'.php';
    file_put_contents($file, <<<'PHP'
        <?php

        namespace LaravelNecromancer\Tests\Fixtures\Dispatches;

        final class NecromancerQualifiedName
        {
            public function qualified(): void
            {
                \LaravelNecromancer\Tests\Fixtures\Dispatches\Targets\ChargeCard::dispatch();
            }
        }
        PHP);

    try {
        [$qualified] = resolveDispatches(dispatchingArtifact('LaravelNecromancer\\Tests\\Fixtures\\Dispatches\\NecromancerQualifiedName', $file));
    } finally {
        unlink($file);
    }

    $target = 'LaravelNecromancer\\Tests\\Fixtures\\Dispatches\\Targets\\ChargeCard';

    expect($importedAndAliased['dispatches'])->toBe([
        ['target' => $target, 'method' => 'aliased', 'mode' => 'queued'],
        ['target' => $target, 'method' => 'imported', 'mode' => 'queued'],
    ])->and($qualified['dispatches'])->toBe([
        ['target' => $target, 'method' => 'qualified', 'mode' => 'queued'],
    ]);
});

test('dynamic, string, Livewire browser-event, closure and anonymous-class dispatches leave no dispatches key', function () {
    [$artifact, $diagnostics] = resolveDispatches(dispatchingArtifact(
        'LaravelNecromancer\\Tests\\Fixtures\\Dispatches\\NecromancerDynamicDispatches',
        'tests/Fixtures/Dispatches/NecromancerDynamicDispatches.php',
    ));

    expect($artifact)->not->toHaveKey('dispatches')
        ->and($diagnostics)->toBe([]);
});

test('dispatches written in a parent class or a trait are not attributed to the child artifact', function () {
    [$artifact] = resolveDispatches(dispatchingArtifact(
        'LaravelNecromancer\\Tests\\Fixtures\\Dispatches\\NecromancerInheritingOperation',
        'tests/Fixtures/Dispatches/NecromancerInheritingOperation.php',
    ));

    expect($artifact)->not->toHaveKey('dispatches');
});

test('an unparseable source file yields a DS_PARSE_FAILED diagnostic and no dispatches, without failing', function () {
    $file = sys_get_temp_dir().'/necromancer-dispatch-'.uniqid().'.php';
    file_put_contents($file, "<?php\n\nfinal class Broken {\n    public function handle( {\n");

    try {
        [$artifact, $diagnostics] = resolveDispatches(dispatchingArtifact('Broken', $file, 'jobs:Broken'), 'jobs');
    } finally {
        unlink($file);
    }

    expect($artifact)->not->toHaveKey('dispatches')
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0])->toStartWith('DS_PARSE_FAILED: jobs:Broken ');
});

test('artifact types that are not class-backed never gain dispatches, even when they carry a class', function () {
    [$artifact] = resolveDispatches(dispatchingArtifact(
        'LaravelNecromancer\\Tests\\Fixtures\\Dispatches\\NecromancerPlaceOrder',
        'tests/Fixtures/Dispatches/NecromancerPlaceOrder.php',
        'tests:tests/Fixtures/Dispatches/NecromancerPlaceOrder.php',
    ), 'tests');

    expect($artifact)->not->toHaveKey('dispatches');
});
