# Inspect the bootstrapped application, not its source text

The scan runs inside Artisan against a fully booted application. Collectors read the framework's own runtime state (`Route::getRoutes()`, the event dispatcher, the Gate, the Schedule, the Router's middleware) and reflect on loaded classes, instead of parsing PHP files statically. What the framework actually registered is the truth an AI tool needs. A static parser would have to re-implement route groups, service-provider registration, attribute resolution and package auto-discovery, and would still drift from what Laravel does at runtime.

## Considered Options

- **Static source parsing** (e.g. an AST over `routes/` and `app/`) — works without booting the app, but cannot see anything registered dynamically. Rejected as the primary mechanism. Text parsing survives only where there is no runtime state to read, such as test files (`TestFileParser`).

## Consequences

- A scan needs an application that boots. Anything that runs at boot (service providers, macros) runs during a scan.
- Some introspection would trigger side effects, and the collectors are built to avoid them: [0006](0006-relationship-introspection-uses-an-in-memory-database.md), [0008](0008-service-provider-bindings-are-not-collected.md), [0017](0017-controller-middleware-is-read-without-instantiating-controllers.md).
- Things that only happen inside a method body (`Model::observe()` in a provider, `$this->middleware()` in a constructor) cannot be seen and are documented as limitations.
