# One scan writes the manifest; every other command renders from it

`necromancer:scan` is the only command that inspects the application. It writes `necromancer.json`, and every other command (`generate`, `audit`, `doctor`, `map`, `ask`, `prompt`, `infer`, `diff`, `okf`, `graph`, the MCP tools) reads that file and never rescans. Because every output comes from the same snapshot, the outputs can't disagree with each other. A new output only costs a renderer, and the manifest is useful on its own: it can be diffed, committed and versioned.

## Consequences

- Every downstream output is only as fresh as the last scan. Commands warn when the manifest looks stale, and `necromancer:okf`/`necromancer:graph` refuse a stale manifest unless `--allow-stale` is passed.
- The manifest schema is a public contract between the scan and every renderer, so changing its shape is a breaking change (see the schema versioning in [0015](0015-pre-schema-v1-manifests-are-rejected.md)).
