# Model relationship introspection runs against an in-memory SQLite connection

`ModelCollector` finds relationships by invoking each public, parameterless model method and checking whether it returns a `Relation`. Building a relation creates a query builder, which opens the model's real database connection. If that database is unreachable, the TCP connect blocks and the scan hangs. For the duration of relationship introspection, the collector therefore swaps Eloquent's connection resolver for `InMemoryConnectionResolver` (SQLite `:memory:`), and restores the original in a `finally` block. No query is ever executed, and the relationship type and related class are fully accurate without a database.

## Considered Options

- **Parse relationship methods from source text** — no database needed, but it misses relationships built dynamically or through traits, which goes against [0002](0002-runtime-introspection-over-static-parsing.md). Rejected.

## Consequences

- A scan works in CI and on machines with no database configured.
- The swap is process-wide while it lasts. Any other code that needs the real connection must not run in the middle of relationship introspection.
