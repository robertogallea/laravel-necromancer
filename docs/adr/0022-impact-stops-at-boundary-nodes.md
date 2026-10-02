# Impact follows Relationships both ways and stops at Boundary Nodes

An Impact is computed by walking Relationships in both directions from the starting artifact, because the coupling worth reporting points both ways: a model's policy and observers are outgoing, but the actions operating on it and the artifacts dispatching to it are incoming. Domains, Flows, ADRs, middleware, and tests reached during the walk are Boundary Nodes: they are reported, but the walk never continues past them. Each connects to a large share of the application (every member of a flow, every route in the `web` group, every subject a namespace-matched test covers), so walking through them answers "what shares a label with this", not "what is coupled to this". The start itself always expands, even when it is a middleware or a test.

## Considered Options

- **Outgoing relationships only** — matches the canonical Relationship direction, but misses every action and dispatcher touching a model. Rejected.
- **Walk every node uniformly and let `--depth` bound the result** — one rule, but depth 2 through a flow or middleware group returns most of the application. Rejected.
- **Only Domains, Flows, and ADRs as boundaries** — keeps synthesized concepts from fanning out, but middleware groups and namespace-matched tests fan out just as badly. Rejected.

## Consequences

- Every consumer of Impact (`necromancer:impact`, the MCP impact tool, affected-test discovery) shares this rule, so none of them can reach a test's other subjects or a middleware's other routes by raising the depth.
- A route reaches a model only through its policy (`route → authorized_by → policy ← authorized_by ← model`), so application surfaces appear at distance 2, not 1.
