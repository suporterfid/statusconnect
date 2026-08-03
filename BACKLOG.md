# StatusConnect Backlog & Open Questions

## Active PR Units

- [ ] **PR0**: Repo scaffold & Docker loop
- [ ] **PR1**: Tenancy, auth, isolation
- [ ] **PR2**: Outbound policy port
- [ ] **PR3**: Monitors CRUD + assertions
- [ ] **PR4**: Scheduler core (sequential)
- [ ] **PR5**: Parallel execution
- [ ] **PR6**: Incident state machine
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
