# PR13 GrandpaSSOn Seam Design

## Context

StatusConnect must support opt-in GrandpaSSOn browser and machine identity without changing the default local/API-key paths. The product authority is `docs/specs/statusconnectinitialspecandbuildplan.md` §15 and §21 (PR13). The broker currently lacks the required `status:read`, `status:write`, and `status:callback` scope vocabulary; [grandpasson#116](https://github.com/suporterfid/grandpasson/issues/116) tracks that prerequisite.

## Design

`config/grandpasson.php` exposes separate inbound and outbound flags, broker URLs derived from `base_url`, RP and service-client credentials, and configurable `status:*` scope names. Both flags default to `false`.

The machine-auth path adds a small application contract for token introspection, a cached HTTP implementation, and an `Authenticatable` actor identified only by a SHA-256 token fingerprint. The HTTP client sends `client_id`, `client_secret`, and `token` as form fields; it never uses HTTP Basic authentication. Cached results are keyed by token fingerprint, use the configured TTL, and never outlive the broker `exp` claim.

The existing bearer middleware retains its native `sc_*` and session behavior. When inbound mode is enabled, a valid opaque broker token becomes a GrandpaSSOn actor. After tenant/environment resolution, a second middleware requires an active token, the configurable read or write scope for the HTTP method, and an audience matching either `env_*` or `workspace/env_*`. Failures return 403 and create a redacted audit record.

The browser path creates and verifies a session `state`, redirects to the broker login endpoint, and redeems the returned code immediately through `/session/exchange`. Any missing, mismatched, expired, malformed, or failed exchange is unauthenticated; no partial identity is trusted. A local user is linked by email. A platform administrator explicitly links each broker tenant id to one local tenant and supplies the broker-role and opaque-group-to-local-role mappings; the exchange only grants membership through that stored mapping and never provisions a tenant implicitly.

## Verification

Feature and unit tests run against Laravel HTTP fakes and a fake introspection implementation. They prove disabled-mode compatibility, body-form credentials, introspection cache/expiry bounds, raw and prefixed audiences, read/write scope selection, redacted audit denial, and fail-closed browser state/code handling. Documentation gives local bootstrap commands, network topology, configuration, 30-second revocation-latency trade-off, and the three cross-repository scope statuses.

## Non-goals

PR13 does not make a live broker mandatory, alter native API-key authorization, add a queue/broker dependency, or replace the StatusConnect tenancy model. Live verification waits for GrandpaSSOn scope issue #116 to ship.
