# Actions are discovered by location and shape, under `app/Actions` only

Actions have no marker interface in Laravel, so `ActionCollector` finds them by convention. It collects a concrete class under `app/Actions` (recursively) that declares at least one Entrypoint: a public, non-static method declared on the class itself, where `__invoke` counts and other magic methods don't. Abstract classes, interfaces, traits, enums and classes with no Entrypoint (DTOs, exceptions, helpers sharing the folder) are skipped silently. The shape rule keeps that folder's supporting classes out of the manifest without asking the developer to mark anything.

## Considered Options

- **Detect `lorisleiva/laravel-actions`' `AsAction` trait** — accurate for that package, but ties core discovery to a third-party convention. Not done.
- **Also scan domain-folder layouts (`app/Domain/*/Actions`)** — no single convention to follow without guessing. Not done.

## Consequences

- Actions living anywhere other than `app/Actions` aren't discovered. Controllers handle the same problem differently: `ControllerCollector` also follows routes into the application namespace, because a route gives it a reliable second source.
- A public helper method on a supporting class under `app/Actions` makes that class an Action.
- Only a class-level `#[Necromancer]` applies. An Action is one artifact with one set of annotations, so a method-level attribute on an Entrypoint is ignored.
