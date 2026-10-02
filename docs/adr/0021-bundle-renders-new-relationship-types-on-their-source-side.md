# The Knowledge Bundle renders new Relationship types on their source side

An Artifact Concept's `## Relationships` section follows two placement rules. The lines it already rendered before typed Relationships (`controller`, Eloquent relationships, `policy`, `observers`, `listeners`, `handles`, `model`, `operates_on`) keep the evidence-holder rule: they appear only on the artifact that holds the fact, under that fact's label. The types added after them (`uses_middleware`, `validates_with`, a route's `authorized_by`, `dispatches`, `tested_by`) render on the Relationship's `from` artifact, labelled by type name, after the legacy lines. Their evidence often lives on the other end: a route's form request is evidenced by its controller's action parameters, and an artifact's tests by the test's `subject`. Under the evidence-holder rule, a route would never show its form request, and a model would never show its tests.

## Considered Options

- **Evidence-holder placement for every type** — one rule, but `validates_with` lands on the controller and `tested_by` on the test, the opposite of how either is read. Rejected.
- **Move every line to source-side placement and type-name labels** — one consistent rule, but it rewrites every existing bundle and loses fact-level labels such as Eloquent method names. Rejected in favour of keeping existing output byte-stable, so the only change is the added lines.
- **Render incoming Relationships on the target too** — navigation in both directions, but it roughly doubles the change to every concept. Deferred.

## Consequences

- Grouping and reference types (`belongs_to_domain`, `belongs_to_flow`, `references_adr`) stay in `## Architectural Context` and are not repeated under `## Relationships`.
- New-type targets are linked by Artifact ID, not by class name, because their targets include middleware registrations, gates, and tests.
- Enrichment prompts are unchanged: per [0014](0014-okf-enrichment-is-private-by-construction.md), adding Relationships to a prompt would be a separate, explicit decision.
