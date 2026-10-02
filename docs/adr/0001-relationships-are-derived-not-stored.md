# Relationships are derived, not stored in the manifest

Relationships are computed from each artifact's Discovered Facts and Artifact Annotations by a single in-memory resolver that every consumer (Artifact Graph, Knowledge Bundle, MCP tools, impact analysis) shares, and they are serialized only into derived outputs such as `graph.json` — never into `necromancer.json`. Every relationship is a projection of facts the manifest already holds, so storing them would record each fact twice and make one change count twice in `meta.content_hash` and `necromancer:scan --diff`.

## Considered Options

- **Top-level `relationships` array in the manifest** — readable by tools that don't have Necromancer installed, but duplicates facts and couples the manifest schema to the relationship vocabulary. Rejected.
- **Graph-only, with each consumer deriving its own** — what existed before (`RelationshipResolver` fed only part of the taxonomy, and each consumer resolved targets itself). Rejected: impact analysis and MCP need one shared primitive.

## Consequences

- Provenance can only be as fine-grained as the manifest: annotations are stored resolved, so an `annotation` relationship cannot tell a `#[Necromancer]` attribute from an exact-ID config mapping.
- Adding a relationship type never requires a manifest schema bump; changing the resolver changes `graph.json` but not `content_hash`.
