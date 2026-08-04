# PR6 Incident State Machine Design

**Issue:** [#9](https://github.com/suporterfid/statusconnect/issues/9)

**Goal:** Turn persisted check outcomes into deterministic monitor-state transitions and idempotent incident lifecycle changes without mixing business rules with database or network I/O.

## Design

`Domain/Incidents/IncidentStateMachine` is a pure function over the previous monitor state, its persisted counters, the current `CheckOutcome` state, the check timestamp, and the monitor confirmation/recovery thresholds. It returns a transition value containing the next monitor state/counters, whether an incident must open or resolve, and the timestamp of the first failure in the confirming streak.

`blocked` produces a configuration-fault transition: it leaves the failure streak unchanged, opens no incident, and is available to the Application layer for a future operator-facing alert. `up` increments recovery only for an existing down/degraded state; `down` and `degraded` increment the failure streak and reset successes. A confirmation opens at the threshold, with `started_at` retained from the first failure and `confirmed_at` equal to the confirming check. Resolution occurs only after the configured success threshold.

`IncidentService` owns persistence. In one transaction it applies monitor counters/state, creates or resolves an incident, and uses a MySQL-compatible unique `resolved_flag` pattern (`monitor_id`, `resolved_flag`) so an overlapping tick cannot create a second open monitor incident. The service never auto-resolves an incident marked manual. It calculates `duration_seconds` from `started_at` to `resolved_at` through the injected `Clock` timestamp already carried by the check.

Flap detection is a pure policy over incidents resolved/opened inside `FLAP_WINDOW_MINUTES`; it marks the monitor flapping after more than `FLAP_THRESHOLD` lifecycle cycles and returns a notification-throttle decision. Notification dispatch itself remains PR9.

## Required proof

- Every state transition and threshold boundary is unit-tested without database or HTTP I/O.
- The first failure's timestamp becomes `started_at` after confirmation.
- `blocked` neither increments failures nor opens an incident.
- Concurrent/idempotent application attempts leave one open incident per monitor.
- Recovery resolves only after its threshold; manual incidents remain open.

## Constraints

- PHP 8.2+, MySQL 8.0+, SQLite-compatible tests, Docker-only development.
- Domain code is framework-free; time is explicit/injected, with no `now()`/`time()`/`DateTime` construction in Domain or Application.
- New Eloquent models belong in `app/Infrastructure/Persistence/Eloquent/`.
- No queue, daemon, broker, runtime shell command, or dependency is introduced.
- `STATUS.md`, `BACKLOG.md`, and issue #9 remain synchronized. PR7 does not start in this branch.
