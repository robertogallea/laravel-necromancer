# Changelog

All notable changes to `laravel-necromancer` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.1

### Added

- `exclude.route_uris` config key for excluding routes by URI pattern (via `Str::is`). Unlike `exclude.routes`, which only matches route names, this also catches unnamed routes such as Laravel's `/up` health-check endpoint — which is now excluded by default, so it no longer appears as an unnamed-route audit finding.

## 1.1.0

### Added

- `necromancer:generate --paths=...` to filter the generated context by source file path prefix. Matches each artifact's `source.file` (falling back to the top-level `file` for tests), is combinable with `--only`/`--except`, omits empty sections, and warns on path prefixes that match nothing.
