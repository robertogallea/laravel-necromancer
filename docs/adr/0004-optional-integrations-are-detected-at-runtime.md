# Optional integrations are detected at runtime, never required

The only hard dependency is `illuminate/support`. `laravel/ai`, `laravel/mcp`, Laravel Boost and Livewire are detected with `class_exists()` at the point of use (`AiDetector`, `BoostDetector`, the MCP guard in `NecromancerServiceProvider`, `LivewireCollector`), and none of them is in `require` (`laravel/ai` and `laravel/mcp` are listed under `suggest`). Necromancer is a `require-dev` tool that has to install into any Laravel 13 application, and its core (scan, generate, audit, doctor) needs no AI provider and no network.

## Consequences

- Code that touches an optional package must check for it before using any of its classes. A missing package means that feature is skipped or the command explains what to install. It never means a fatal error at boot.
- AI-backed commands (`ask`, `infer`, `okf-enrich`, `diff --review`, `benchmark`) are unavailable until `laravel/ai` is installed, and the MCP server doesn't exist until `laravel/mcp` is installed.
- Collection that depends on a newer framework API is feature-detected the same way, e.g. `method_exists($route, 'getMetadata')` for route metadata ([0010](0010-route-metadata-reuses-laravels-native-api.md)).
