# Dispatch facts are read from source text

A dispatch of a job, event, or mailable exists only inside a method body, so a booted application holds no runtime state that records it. The scan therefore reads the source of each artifact it has already collected, with `nikic/php-parser`, and records the classes that artifact dispatches as a Discovered Fact with `source` provenance. This narrows [0002](0002-runtime-introspection-over-static-parsing.md) rather than reversing it: source text is consulted only for facts that have no runtime or reflected form, it never discovers artifacts, and it never overrides a fact read at runtime or by reflection.

## Considered Options

- **Close as out of scope under 0002** — keeps the scan purely runtime, but leaves flows (what happens after an order is placed) invisible to every consumer. Rejected.
- **Source analysis as a general second mechanism** — would also cover `$this->middleware()` in constructors and `Model::observe()` in providers, but grows a parallel collector world that drifts from what Laravel registers, which is what 0002 exists to prevent. Rejected.
- **`PhpToken`/regex scanning instead of a parser** — no new dependency, but import resolution, chained calls, and `new X` arguments would be hand-rolled and fragile. Rejected: the package is installed as `require-dev`, and default Laravel skeletons already pull `nikic/php-parser` in through PHPUnit and PsySH.

## Consequences

- Only dispatches whose target is written as a class name are seen; dynamic dispatches (`dispatch($job)`) are skipped, and so are dispatches inherited from parent classes or traits, since each is attributed to the file it is written in.
- Dispatch facts are collected by default, so the first scan after upgrading reports drift for every artifact that dispatches something.
