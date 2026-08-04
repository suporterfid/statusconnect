# StatusConnect Backlog & Open Questions

## Active PR Units

- [x] **Remediation #14**: Local email/password login, logout, and session-based `/v1/me` restored independently of GrandpaSSOn in [PR #15](https://github.com/suporterfid/statusconnect/pull/15); verified with 101 tests / 284 assertions.

- [x] **PR0**: Repo scaffold & Docker loop
- [x] **PR1**: Tenancy, auth, isolation
- [x] **PR2**: Outbound policy port
- [x] **PR3**: Monitors CRUD + assertions
- [x] **PR4**: Scheduler core (sequential)
- [x] **PR5**: Parallel execution ([#7](https://github.com/suporterfid/statusconnect/issues/7)) — verified with a 30-monitor / 5-second fixture in 15.22s within the 45-second tick budget; PR6 is next.
- [x] **PR6**: Incident state machine ([#9](https://github.com/suporterfid/statusconnect/issues/9)) — merged in [PR #10](https://github.com/suporterfid/statusconnect/pull/10); main verified with 98 tests / 272 assertions. PR7 is next.
- [ ] **PR7**: Rollups & retention
- [ ] **PR8**: Public status page
- [ ] **PR9**: Notifications
- [ ] **PR10**: Heartbeat monitors
- [ ] **PR11**: Maintenance windows
- [ ] **PR12**: Operator SPA
- [ ] **PR13**: GrandpaSSOn seam ([#13](https://github.com/suporterfid/statusconnect/issues/13)) — the explicit administrator-managed tenant/role/group mapping is implemented, and conflicting role/group resolution fails closed; local-auth prerequisite completed in [PR #15](https://github.com/suporterfid/statusconnect/pull/15); blocked only on the broker scope-vocabulary proposal [grandpasson#116](https://github.com/suporterfid/grandpasson/issues/116).
- [ ] **PR14**: TaskConnect integration
- [ ] **PR15**: Release & deploy
- [ ] **PR16**: E2E & accessibility

## Open Questions (§23)

1. **GrandpaSSOn scope vocabulary**: `status:read`, `status:write`, and `status:callback` are proposed in [grandpasson#116](https://github.com/suporterfid/grandpasson/issues/116). The StatusConnect side must not enable live broker traffic until the broker accepts and implements them.

## Resolved Decisions

- **GrandpaSSOn delegated tenancy mapping**: A platform administrator explicitly maps each broker tenant to a local StatusConnect tenant and defines `owner`/`admin`/`member` plus opaque groups to local `owner`/`admin`/`viewer` roles. Browser exchange never auto-provisions a tenant.
- **GrandpaSSOn role/group precedence**: A mismatch between any mapped broker role or mapped group roles fails the delegated login closed. Unmapped groups are ignored; no resolved explicit mapping also fails closed.
- **Status page hosting shape**: Path-based `/status/{slug}` for v0; custom domains deferred to post-v0.
- **Interval floor policy**: Minimum interval enforced at 60s.
- **`degraded` in uptime maths**: Excluded from downtime.
- **Heartbeat-only tenants**: Allowed.
- **Self-monitoring honesty**: Document external ping recipes and surface stale checker heartbeat on the public status page.
- **Retention vs. host quota**: Warn when approaching the database budget in v0.
