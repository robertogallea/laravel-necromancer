# Manifests older than schema v1 are rejected, not upgraded

Since 2.0, `ManifestReader::read()` treats any manifest whose `meta.manifest_schema_version` isn't `1` exactly like a missing manifest: every command fails with the message to run `necromancer:scan` first. Version 1.5 had silently promoted unversioned manifests in memory instead (assigning canonical IDs and converting legacy route metadata). A manifest is cheap to regenerate with one scan, while keeping an in-memory upgrader meant every consumer of the manifest still had to cope with an old schema.

## Consequences

- Upgrading across a schema bump needs one rescan, and CI that reads a committed manifest fails until the regenerated manifest is committed.
- A future schema version should follow the same approach: bump `manifest_schema_version` and reject older versions, rather than ship an adapter.
