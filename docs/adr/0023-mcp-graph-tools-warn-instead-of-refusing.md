# MCP graph tools warn instead of refusing

The MCP graph tools (`get_artifact`, `get_relationships`, `get_impact`, `get_affected_tests`) answer from a stale or partial-scope manifest and report the condition in a `warnings` list, where `necromancer:impact`, `necromancer:graph`, and `necromancer:okf` refuse by default. An MCP caller has no `--allow-stale`/`--allow-partial` to pass, so a refusal could not be overridden, and a coding agent makes the manifest stale with its first edit: refusing would disable the tools for most of a session. `get_impact` and `get_affected_tests` also clamp `depth` to at most 3, adding a warning when they do, where the CLI accepts any depth: an agent can ask for depth 10, and the answer lands in its context window rather than a terminal.

## Considered Options

- **Refuse with `ManifestScopeGuard`'s message, as the CLI does** — consistent, but the refusal can't be overridden over MCP, and the tools stop working as soon as the agent edits a file. Rejected.
- **Ignore staleness and scope, as the older MCP tools (`query_routes`, `query_models`, `query_artifacts`, `search_artifacts`) do** — never blocks, but an Impact computed from a partial scan silently misses whatever wasn't scanned. Rejected.
- **Reject a `depth` above the cap** — explicit, but costs the agent a retry for no benefit. Rejected in favour of clamping.

## Consequences

- The CLI and MCP surfaces deliberately differ on stale/partial input. The refusal wording stays in `ManifestScopeGuard`, used by the CLI only.
- Errors that are not about manifest freshness (ambiguous or unknown start, a concept ID passed to `get_artifact`, an unknown `types` value, a missing manifest) are still returned as MCP errors, since no answer exists to warn about.
