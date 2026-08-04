# StatusConnect Backlog & Open Questions

## Active PR Units

- [x] **PR0**: Repo scaffold & Docker loop
- [x] **PR1**: Tenancy, auth, isolation
- [x] **PR2**: Outbound policy port
- [x] **PR3**: Monitors CRUD + assertions
- [x] **PR4**: Scheduler core (sequential)
- [x] **PR5**: Parallel execution ([#7](https://github.com/suporterfid/statusconnect/issues/7)) — verified with a 30-monitor / 5-second fixture in 15.22s within the 45-second tick budget; PR6 is next.
- [ ] **PR6**: Incident state machine ([#9](https://github.com/suporterfid/statusconnect/issues/9)) â€” ready for review; 92 tests / 235 assertions verified. PR7 remains blocked on PR6 merge.
- [ ] **PR7**: Rollups & retention
- [ ] **PR8**: Public status page
- [ ] **PR9**: Notifications
- [ ] **PR10**: Heartbeat monitors
- [ ] **PR11**: Maintenance windows
- [ ] **PR12**: Operator SPA
- [ ] **PR13**: GrandpaSSOn seam
- [ ] **PR14**: TaskConnect integration
- [ ] **PR15**: Release & deploy
- [ ] **PR16**: E2E & accessibility

## Open Questions (§23)

1. **Status page hosting shape**: Path-based `/status/{slug}` for v0; custom domains deferred to post-v0.
2. **Interval floor policy**: Minimum interval enforced at 60s.
3. **`degraded` in uptime maths**: Fixed in v0 (excluded from downtime) and documented.
4. **GrandpaSSOn scope vocabulary**: `status:read`, `status:write`, `status:callback` issue to be opened in `grandpasson` repo before PR13 live broker testing.
5. **Heartbeat-only tenants**: Allowed.
6. **Self-monitoring honesty**: Document external ping recipes and surface stale checker heartbeat on public status page.
7. **Retention vs. host quota**: Warn when approaching database budget in v0.
