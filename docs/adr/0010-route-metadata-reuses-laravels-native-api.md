# Route annotations use Laravel's native route metadata, and `withNecromancer()` is only a shortcut

Laravel 13.17 added `Route::metadata()`/`getMetadata()`, with group inheritance and per-field merging. Necromancer reads its route annotations from a reserved `necromancer` key in that metadata (configurable via `route_metadata.namespace`) instead of adding a parallel metadata system. Metadata other packages store under different keys is kept untouched in `route_metadata.raw` and never treated as a Necromancer signal. The `withNecromancer()` macro (registered on `Router`, `RouteRegistrar`, `Route` and both pending resource registrations) builds that array and passes it to native `->metadata()`, so it produces exactly the raw-array form.

Collection and declaration react differently to an older framework. `RouteCollector` feature-detects `getMetadata()` and silently omits route metadata on older Laravel, because collection is passive. The macro throws a `RuntimeException` naming Laravel 13.17, because a developer explicitly calling it must not have the declaration silently dropped.

## Considered Options

- **`Necromancer::forMetadata()` facade** (1.3) — built the array, but the developer still had to wrap it in `->metadata()` and import a facade. Replaced by the macro in 1.4.
- **A Necromancer-owned route registry** — duplicates what the framework now provides and fights its group merging. Rejected.

## Consequences

- Group-level fields are inherited and route-level fields win per field, through Laravel's own merging rather than Necromancer's.
- The raw `->metadata(['necromancer' => [...]])` form stays supported for good, since it is the framework's API.
