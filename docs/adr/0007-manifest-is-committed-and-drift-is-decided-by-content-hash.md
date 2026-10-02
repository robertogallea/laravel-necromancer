# The manifest is committed like `composer.lock`, and drift is decided by its content hash

`necromancer.json` is meant to be committed to git. It contains no secrets ([0003](0003-never-collect-config-values-or-read-env.md)), and committing it is what makes `necromancer:diff` across branches and the CI drift gate possible. Each scan writes `meta.content_hash`, a SHA-256 over the schema versions, the scan scope and the collected artifacts. The timestamp and environment fields are deliberately left out of the hash. Two scans of an unchanged application therefore produce the same hash. `necromancer:scan --diff --fail-on-drift` uses it as the single test for drift: the manifest is current if and only if the fresh hash equals the stored one. An artifact-by-artifact diff is used only for manifests that have no hash.

## Considered Options

- **Compare `generated_at` or file modification times** — cheap, but a re-scan with no changes would look like drift, and a change outside the watched paths would be missed. Kept only as a staleness *warning* for commands that can't afford a rescan.
- **Compare artifact IDs only** — misses changes to existing artifacts. Rejected.

## Consequences

- Every artifact's `source.hash` is part of the hash, so a formatting-only edit to a source file counts as drift. This is intended: the committed manifest really is stale.
- Adding an artifact type or changing the scan scope changes the hash, so upgrades that add a type report drift until the manifest is rescanned.
- The hash is a stable cache key for downstream work (ADR inference, OKF bundles), independent of when the scan ran.
