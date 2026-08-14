# 0001: Knowledge Bundle awareness never asserts what it can't verify live

Two surfaces need to talk about a Knowledge Bundle without being able to check it live: `necromancer:generate`'s Bundle Announcement (in CLAUDE.md/AGENTS.md/llms.txt) needs to say whether the bundle is fresh, and each bundle's own `README.md` needs to mention its sibling (deterministic ↔ enriched) without knowing if that sibling currently exists. In both cases we chose wording that stays true even when it's out of date, over a claim that can go stale and mislead.

For freshness, `bundle.json`'s existing `generated_at` field is copied from the manifest's own `meta.generated_at` at export time — specifically so re-exporting an unchanged manifest is byte-identical. Comparing it against the *current* manifest's `generated_at` would flag a bundle as stale after any rescan, even a no-op one that changed nothing structurally — the exact false positive `meta.content_hash` was introduced to prevent for `necromancer:infer`'s cache. So the Bundle Announcement's freshness check compares a new `content_hash` field on `bundle.json` (additive; `okf_version` is not bumped) against the manifest's current `content_hash` instead. A bundle exported before this field existed simply omits the freshness caveat — silence, not an assumed stale or fresh verdict.

For cross-bundle references, each README describes its sibling in static, unconditional prose ("an enriched sibling can be generated via `necromancer:okf-enrich`") rather than checking on export whether the sibling currently exists — `necromancer:okf`'s atomic swap only runs when that command runs, so it has no way to know if `okf-enrich` was run afterward, or the sibling was deleted since. An existence-claim there would reintroduce the same staleness trap the freshness check above exists to avoid, just one level down.

## Considered Options

- Compare `generated_at` timestamps for bundle freshness — no schema change, but produces false "stale" positives on routine no-op rescans.
- Have each README check for its sibling's existence at export time — reads as more precise, but the claim itself can silently go stale the moment the other bundle is regenerated or removed.
