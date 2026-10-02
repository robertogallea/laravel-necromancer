<?php

use LaravelNecromancer\Collection\TestReferenceFactResolver;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class);

/**
 * Writes a test file to a temporary path and resolves its references.
 * Written at runtime so Pint can't rewrite the name forms under test.
 *
 * @return array{0: array<string, mixed>, 1: list<string>}
 */
function resolveTestReferences(string $source): array
{
    $file = sys_get_temp_dir().'/necromancer-test-references-'.uniqid().'.php';
    file_put_contents($file, $source);

    try {
        [$artifacts, $diagnostics] = (new TestReferenceFactResolver('App\\'))->apply([
            'tests' => [['id' => 'tests:tests/Feature/OrderTest.php', 'file' => 'tests/Feature/OrderTest.php', 'source' => ['file' => $file, 'line' => 1]]],
        ]);
    } finally {
        unlink($file);
    }

    return [$artifacts['tests'][0], $diagnostics];
}

test('a Pest test records the app classes it uses in code and the route names it passes to route(), sorted by kind then target', function () {
    [$test, $diagnostics] = resolveTestReferences(<<<'PHP'
        <?php

        use App\Actions\PlaceOrder;
        use App\Models\Order;
        use App\Models\User as Customer;

        it('places an order', function () {
            $customer = Customer::factory()->create();

            $this->actingAs($customer)->post(route('orders.store'), ['class' => Order::class]);

            (new PlaceOrder)->handle();
            $this->get(route('orders.index'));
            $this->get(route('orders.store'));
        });
        PHP);

    expect($test['references'])->toBe([
        ['kind' => 'class', 'target' => 'App\\Actions\\PlaceOrder'],
        ['kind' => 'class', 'target' => 'App\\Models\\Order'],
        ['kind' => 'class', 'target' => 'App\\Models\\User'],
        ['kind' => 'route', 'target' => 'orders.index'],
        ['kind' => 'route', 'target' => 'orders.store'],
    ])->and($diagnostics)->toBe([]);
});

test('an import alone, a class outside the app namespace, and a non-literal route name are not references', function () {
    [$test] = resolveTestReferences(<<<'PHP'
        <?php

        namespace Tests\Feature;

        use App\Models\Unused;
        use Illuminate\Support\Facades\Mail;
        use Tests\TestCase;

        class OrderTest extends TestCase
        {
            public function test_it_mails(): void
            {
                Mail::fake();
                $name = 'orders.index';
                $this->get(route($name));
                $this->get(\route("orders.{$name}"));
                $this->assertInstanceOf(\App\Models\Order::class, null);
            }
        }
        PHP);

    expect($test['references'])->toBe([
        ['kind' => 'class', 'target' => 'App\\Models\\Order'],
    ]);
});

test('a namespaced Pest file resolves route() and partially-qualified names through its namespace imports', function () {
    [$test] = resolveTestReferences(<<<'PHP'
        <?php

        namespace Tests\Feature;

        use App\Models;

        it('lists orders', function () {
            Models\Order::factory()->create();
            $this->get(route('orders.index'));
            expect(Order::class)->toBeString();
        });
        PHP);

    expect($test['references'])->toBe([
        ['kind' => 'class', 'target' => 'App\\Models\\Order'],
        ['kind' => 'route', 'target' => 'orders.index'],
    ]);
});

test('a test file referencing nothing gets no references key', function () {
    [$test] = resolveTestReferences(<<<'PHP'
        <?php

        test('adds', fn () => expect(1 + 1)->toBe(2));
        PHP);

    expect($test)->not->toHaveKey('references');
});

test('an unparseable test file yields a TR_PARSE_FAILED diagnostic and no references, without failing', function () {
    [$test, $diagnostics] = resolveTestReferences("<?php\n\nit('breaks', function () {\n");

    expect($test)->not->toHaveKey('references')
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0])->toStartWith('TR_PARSE_FAILED: tests:tests/Feature/OrderTest.php ');
});

test('artifacts other than tests are left untouched', function () {
    $artifacts = ['models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order']]];

    expect((new TestReferenceFactResolver('App\\'))->apply($artifacts))->toBe([$artifacts, []]);
});
