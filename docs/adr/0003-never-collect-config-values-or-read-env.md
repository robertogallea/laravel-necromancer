# Never collect configuration values or read `.env`

The manifest records configuration *keys* only (`ConfigurationSummary`), never their values, and nothing in the package reads `.env`. Sensitive data is kept out of collection entirely rather than collected and then masked or redacted. This rule is what makes the manifest safe to commit to git ([0007](0007-manifest-is-committed-and-drift-is-decided-by-content-hash.md)) and to send to an AI provider.

## Considered Options

- **Collect values and redact secrets** — would surface more useful context (queue drivers, cache stores), but redaction depends on recognising what a secret looks like, and one miss leaks it into a committed file. Rejected.

## Consequences

- Facts that live only in configuration values (which queue driver is used, which mail transport) are invisible to every output.
- Class names and route paths are allowed, because they describe structure and are already visible through `php artisan route:list`.
- Any new collector that touches configuration must keep to keys only. The same rule constrains AI prompts ([0014](0014-okf-enrichment-is-private-by-construction.md)).
