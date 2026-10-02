# The Knowledge Bundle is a deterministic, read-only projection of the manifest

`necromancer:okf` turns the manifest into an OKF Knowledge Bundle without rescanning, and Necromancer never reads the bundle back. The manifest stays the only source of truth, and the bundle can be deleted and regenerated at any time. Output is deterministic: `generated_at` is copied from the manifest's `meta.generated_at` rather than the export time, and `bundle.json` carries the manifest's `content_hash`, so an unchanged manifest always exports byte-identical files. The command refuses a stale manifest or a partial-scope one (an `--only` scan) unless `--allow-stale`/`--allow-partial` is passed. It builds the whole bundle in a temporary directory and swaps it into place only once every file has been written, so a failed export never damages a previous bundle.

`necromancer:graph` follows the same rules for the Artifact Graph.

## Consequences

- A bundle can't be edited in place to add knowledge. Hand edits are lost on the next export, and AI enrichment goes into a separate sibling bundle ([0014](0014-okf-enrichment-is-private-by-construction.md)).
- A missing local ADR referenced by an annotation fails the export before anything is written, the same way a stale manifest does.
- Refusing by default means CI or scripts that export after code changes must rescan first or opt out explicitly.
