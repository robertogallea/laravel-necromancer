# Service provider bindings are not collected

`ServiceProviderCollector` records each application provider from `bootstrap/providers.php` and whether it is deferred, but always writes `bindings: []` and `singletons: []`. Knowing which provider registered which binding means running each provider's `register()` method in isolation and watching the container change. That is application code with arbitrary side effects. A scan must stay a read-only inspection, so the fields exist in the schema and stay empty.

## Consequences

- The manifest can't answer "where is this interface bound?". AI tools have to read the providers' source.
- The empty fields are deliberate. Filling them needs a way to attribute bindings without executing `register()`, not a quick fix.
