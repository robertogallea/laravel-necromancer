# Annotation precedence runs from the most specific declaration, and config mappings are exact-ID and fill-only

When several sources declare the same field for an artifact, the most specific one wins, per field. For routes, native route metadata (including `withNecromancer()`) wins over a method-level `#[Necromancer]` on the controller action, which wins over a class-level one. When native metadata overrides a different controller-derived value, `necromancer:scan` emits `AN_SOURCE_CONFLICT` so the disagreement isn't silent. A declaration that sits next to the code it describes is more trustworthy than a default inherited from further away.

The `necromancer.annotations` config map is the only source for things that have no class or method to carry an attribute: closures, test files, gates and scheduled tasks. Its keys must be exact canonical Artifact IDs, with no wildcards or patterns. It only *fills* fields no other source set, and when it disagrees with an attribute or route metadata, the existing value wins with an `AN_SOURCE_CONFLICT` warning. List fields (`external_services`, `adrs`) are appended and deduplicated. An invalid mapping fails the scan before anything is written.

## Considered Options

- **Namespace and `Str::is()` pattern mappings in config** (proposed in the artifact-metadata spec) — gives broad `domain` coverage in a few lines, but a pattern can quietly annotate artifacts nobody looked at, and can make up IDs that match nothing. Rejected for exact IDs.
- **Config overrides attributes** — would let a central file silently contradict the code. Rejected: config fills gaps only.

## Consequences

- Annotating a whole domain means annotating each class or registration individually.
- A mapping whose ID matches nothing in scope emits `AN_CONFIG_UNMATCHED`. One for a type outside a `--only` scan is skipped, so a partial scan never fails because of an unrelated mistake.
- A method-level `#[Necromancer]` refines route annotations only. It is never copied onto the controller artifact, and it is ignored on an Action.
