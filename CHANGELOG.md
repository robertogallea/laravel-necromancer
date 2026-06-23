# Changelog

All notable changes to `laravel-necromancer` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.1

### Added

- `exclude.route_uris` config key for excluding routes by URI pattern (via `Str::is`). Unlike `exclude.routes`, which only matches route names, this also catches unnamed routes such as Laravel's `/up` health-check endpoint — which is now excluded by default, so it no longer appears as an unnamed-route audit finding.
- `appends` field on the model artifact, capturing accessors appended to model serialization. Collected via `getAppends()`, so both the `$appends` property and the `#[Appends]` attribute are picked up. Rendered as an `appends: …` entry in the generated model table.

### Fixed

- `MissingFillableCheck` no longer flags models that use the guarded strategy (`$guarded` / `#[Guarded]`). A model with `guarded` set to anything other than the Eloquent default `['*']` has a defined mass-assignment surface even with an empty `fillable`.
- `ModelsWithOpenGuardCheck` no longer flags an open guard (`$guarded = []`) when a non-empty `fillable` whitelist constrains mass assignment — eliminating a false positive on `Pivot` models, which default to `$guarded = []`.

## 1.1.0

### Added

- `necromancer:generate --paths=...` to filter the generated context by source file path prefix. Matches each artifact's `source.file` (falling back to the top-level `file` for tests), is combinable with `--only`/`--except`, omits empty sections, and warns on path prefixes that match nothing.
