# AI enrichment can only add prose, and what reaches the provider is limited by what the prompt builder is given

`necromancer:okf-enrich` writes a separate `okf-enriched/` bundle and never modifies the deterministic one. Enrichment can only add a `description` and an "AI-Enriched Summary" section. The model's output is never given access to a concept's facts, annotations, Artifact ID or links, so it can't change them.

Privacy is enforced by what `EnrichmentPromptBuilder` accepts, not by filtering its output afterwards. A domain or flow concept's prompt is built from its value and member IDs only. An ADR concept's prompt is built from its path and the IDs referencing it, and there is no parameter for the ADR's file content at all. An artifact's prompt is built from manifest artifact data, minus the keys `ArtifactConceptBuilder::EXCLUDED_FACT_KEYS` removes (source file paths and hashes, raw `route_metadata`). Configuration never reaches it, because the manifest never holds configuration values ([0003](0003-never-collect-config-values-or-read-env.md)).

## Considered Options

- **Build the full concept, then redact before sending** — one forgotten redaction leaks. Rejected in favour of builders that never receive the sensitive input.

## Consequences

- Any new input to an enrichment prompt has to be added to a builder's signature explicitly, which makes it visible in review.
- For artifact concepts, the protection is a list of excluded keys over manifest data. A new manifest field that should stay private has to be added to `EXCLUDED_FACT_KEYS`.
- Each enriched concept records its provider, model, prompt version, privacy policy and cache key, so a reader can tell AI-written prose from manifest facts.
