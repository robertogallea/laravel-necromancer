<?php

declare(strict_types=1);

namespace LaravelNecromancer\Routing;

use Closure;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Routing\PendingSingletonResourceRegistration;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\RouteRegistrar;
use LaravelNecromancer\Metadata\RouteMetadataFactory;
use RuntimeException;

/**
 * Registers the withNecromancer() route macros.
 *
 * Each macro carries the full named-argument signature so editors and static
 * analysis can validate field names at every chain position; keep the five
 * signatures in sync when adding a route metadata field. The macros delegate to
 * Laravel's native route metadata API immediately, so routes keep storing plain,
 * cache-friendly arrays under the configured namespace — this is a shorthand for
 * ->metadata(['necromancer' => [...]]), never a parallel metadata system.
 */
final class RouteMetadataMacros
{
    /**
     * Register the withNecromancer() macro on every routing surface.
     */
    public function register(): void
    {
        Router::macro('withNecromancer', $this->routerMacro());
        RouteRegistrar::macro('withNecromancer', $this->registrarMacro());
        Route::macro('withNecromancer', $this->routeMacro());
        PendingResourceRegistration::macro('withNecromancer', $this->pendingResourceMacro());
        PendingSingletonResourceRegistration::macro('withNecromancer', $this->pendingSingletonMacro());
    }

    /**
     * Recover the typed routing object a macro closure was bound to.
     *
     * Macroable rebinds each macro closure at call time, so inside the closure
     * $this is the routing object rather than this registrar.
     *
     * @template TScope of object
     *
     * @param  class-string<TScope>  $scope
     * @return TScope
     */
    public static function bound(mixed $context, string $scope): object
    {
        assert($context instanceof $scope);

        return $context;
    }

    /**
     * Build the metadata payload for the supplied fields.
     *
     * @param  string|list<string>|null  $externalServices
     * @return array<string, array<string, mixed>>
     */
    public static function payload(
        ?string $domain = null,
        ?string $flow = null,
        ?string $capability = null,
        ?string $summary = null,
        ?string $risk = null,
        string|array|null $externalServices = null,
        ?string $adr = null,
    ): array {
        return app(RouteMetadataFactory::class)->forMetadata(
            domain: $domain,
            flow: $flow,
            capability: $capability,
            summary: $summary,
            risk: $risk,
            externalServices: $externalServices,
            adr: $adr,
        );
    }

    /**
     * Fail loudly when the installed framework predates native route metadata.
     *
     * The package supports Laravel ^13.0, but Route::metadata() only exists from
     * 13.17 onwards. Collection degrades silently on older versions because it is
     * passive; an explicit withNecromancer() call must not.
     */
    public static function ensureSupported(object $target): void
    {
        if (! method_exists($target, 'metadata')) {
            throw new RuntimeException(sprintf(
                'withNecromancer() requires Laravel 13.17 or newer, which introduced native route metadata support (%s::metadata()).',
                $target::class,
            ));
        }
    }

    /**
     * Create the withNecromancer() macro for group-position calls on the router,
     * such as Route::withNecromancer(...)->prefix('billing')->group(...).
     */
    protected function routerMacro(): Closure
    {
        /**
         * @param  string|list<string>|null  $externalServices
         */
        return function (
            ?string $domain = null,
            ?string $flow = null,
            ?string $capability = null,
            ?string $summary = null,
            ?string $risk = null,
            string|array|null $externalServices = null,
            ?string $adr = null,
        ): RouteRegistrar {
            $registrar = new RouteRegistrar(RouteMetadataMacros::bound($this, Router::class));

            RouteMetadataMacros::ensureSupported($registrar);

            return $registrar->metadata(RouteMetadataMacros::payload(
                domain: $domain,
                flow: $flow,
                capability: $capability,
                summary: $summary,
                risk: $risk,
                externalServices: $externalServices,
                adr: $adr,
            ));
        };
    }

    /**
     * Create the withNecromancer() macro for chained group-position calls, such
     * as Route::prefix('billing')->withNecromancer(...)->group(...).
     */
    protected function registrarMacro(): Closure
    {
        /**
         * @param  string|list<string>|null  $externalServices
         */
        return function (
            ?string $domain = null,
            ?string $flow = null,
            ?string $capability = null,
            ?string $summary = null,
            ?string $risk = null,
            string|array|null $externalServices = null,
            ?string $adr = null,
        ): RouteRegistrar {
            $registrar = RouteMetadataMacros::bound($this, RouteRegistrar::class);

            RouteMetadataMacros::ensureSupported($registrar);

            return $registrar->metadata(RouteMetadataMacros::payload(
                domain: $domain,
                flow: $flow,
                capability: $capability,
                summary: $summary,
                risk: $risk,
                externalServices: $externalServices,
                adr: $adr,
            ));
        };
    }

    /**
     * Create the withNecromancer() macro for individual routes, such as
     * Route::post('/billing/cancel', [...])->withNecromancer(...).
     */
    protected function routeMacro(): Closure
    {
        /**
         * @param  string|list<string>|null  $externalServices
         */
        return function (
            ?string $domain = null,
            ?string $flow = null,
            ?string $capability = null,
            ?string $summary = null,
            ?string $risk = null,
            string|array|null $externalServices = null,
            ?string $adr = null,
        ): Route {
            $route = RouteMetadataMacros::bound($this, Route::class);

            RouteMetadataMacros::ensureSupported($route);

            return $route->metadata(RouteMetadataMacros::payload(
                domain: $domain,
                flow: $flow,
                capability: $capability,
                summary: $summary,
                risk: $risk,
                externalServices: $externalServices,
                adr: $adr,
            ));
        };
    }

    /**
     * Create the withNecromancer() macro for resource registrations, such as
     * Route::resource('posts', PostController::class)->withNecromancer(...).
     */
    protected function pendingResourceMacro(): Closure
    {
        /**
         * @param  string|list<string>|null  $externalServices
         */
        return function (
            ?string $domain = null,
            ?string $flow = null,
            ?string $capability = null,
            ?string $summary = null,
            ?string $risk = null,
            string|array|null $externalServices = null,
            ?string $adr = null,
        ): PendingResourceRegistration {
            $registration = RouteMetadataMacros::bound($this, PendingResourceRegistration::class);

            RouteMetadataMacros::ensureSupported($registration);

            return $registration->metadata(RouteMetadataMacros::payload(
                domain: $domain,
                flow: $flow,
                capability: $capability,
                summary: $summary,
                risk: $risk,
                externalServices: $externalServices,
                adr: $adr,
            ));
        };
    }

    /**
     * Create the withNecromancer() macro for singleton resource registrations,
     * such as Route::singleton('profile', ProfileController::class)->withNecromancer(...).
     */
    protected function pendingSingletonMacro(): Closure
    {
        /**
         * @param  string|list<string>|null  $externalServices
         */
        return function (
            ?string $domain = null,
            ?string $flow = null,
            ?string $capability = null,
            ?string $summary = null,
            ?string $risk = null,
            string|array|null $externalServices = null,
            ?string $adr = null,
        ): PendingSingletonResourceRegistration {
            $registration = RouteMetadataMacros::bound($this, PendingSingletonResourceRegistration::class);

            RouteMetadataMacros::ensureSupported($registration);

            return $registration->metadata(RouteMetadataMacros::payload(
                domain: $domain,
                flow: $flow,
                capability: $capability,
                summary: $summary,
                risk: $risk,
                externalServices: $externalServices,
                adr: $adr,
            ));
        };
    }
}
