# Bindings are read from the booted container

Code that type-hints `PaymentGateway` doesn't say which implementation Laravel provides; the booted container does. Boot has already run every provider's `register()`, so the scan reads the container's binding map, stored instances, and scoped keys afterwards and records each application **Binding** (abstract or concrete in the application namespace) as a `bindings` artifact: its concrete class, how that concrete was learned, its lifetime, and, where declared, the provider that registered it. This narrows [0008](0008-service-provider-bindings-are-not-collected.md) rather than reversing it: nothing is executed to attribute a binding, and a provider is recorded only from declarations that need no execution, its `$bindings`/`$singletons` properties or a deferred provider's `provides()`. Every other binding has `provider: null`.

A class that matches no collected artifact but matches a binding's abstract resolves to that binding, so an Action's `operates_on PaymentGateway` reaches `bindings:PaymentGateway` and, through `resolved_as`, the concrete class. A collected artifact always wins, so no Relationship that resolves today changes target.

## Considered Options

- **Fill `service_providers.bindings`/`singletons`** — needs per-provider attribution for every binding, which is exactly what 0008 forbids. Rejected; the always-empty fields are removed.
- **Attribute providers by reading `$this->app->bind(...)` calls from source text** — would cover most `register()` methods, but it is the general provider-parsing mechanism [0020](0020-dispatch-facts-are-read-from-source-text.md) rejected because it drifts from what Laravel registers. Rejected.
- **Call factory closures to learn their concrete** — executes application code during a scan. Rejected: a closure binding's concrete comes from its declared return type, or is `null`.
- **Give the binding artifact `class: <abstract>`** — would put bindings in the class index directly, but `singleton(SomeAction::class)` would then collide with the Action of the same class. Rejected in favor of the fallback above.

## Consequences

- A binding reflects the environment the scan ran in. `environment('production') ? Live : Fake` records whichever the scanning environment chose, so generated context labels the section with `meta.app_env`.
- A binding an application deferred provider provides is marked `deferred: true`. Artisan loads every deferred provider before a command runs, so at scan time those bindings are already in the binding map and report their real concrete and lifetime. Only a scan run outside the console kernel can meet an unloaded deferred provider; its abstracts then come from `provides()` alone, with the concrete and lifetime its properties declare, or `null`.
- `#[Bind]`/`#[Singleton]`/`#[Scoped]` attributes are resolved lazily by Laravel, so they are found only on application types already reached by collected facts (Action entrypoint and controller action parameters, test class references).
- Aliases and `extend()` decorators are not represented. Contextual bindings are covered by [0026](0026-contextual-bindings-are-bindings-scoped-to-a-consumer.md).
- Removing `service_providers.bindings`/`singletons` and adding `bindings` artifacts makes the first scan after upgrading report drift for every application.
