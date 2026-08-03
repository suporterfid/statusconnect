# PR4 Scheduler Remediation Design

**Issue:** [#5](https://github.com/suporterfid/statusconnect/issues/5)

**Goal:** Bring the already-merged scheduler core into compliance with the PR4 definition of done before parallel execution starts.

## Scope

This corrective PR keeps execution sequential. It adds the missing scheduler safety mechanisms and corrects claim semantics; `curl_multi` waves and asynchronous TCP stay exclusively in PR5.

## Design

`DueMonitorClaimer` selects due monitors inside a transaction using `FOR UPDATE SKIP LOCKED` on MySQL and `lockForUpdate()` on SQLite. Each candidate receives a UUID claim token only through a conditional update that reasserts the due and expired-or-unclaimed predicate. The same claim update advances `next_check_at` to `max(now, previous_next_check_at + interval_seconds)`, so a delayed tick neither shifts phase nor backfills missed checks.

`MonitorCheckDueCommand` creates a `TickBudget` from scheduler configuration and claims one sequential monitor at a time. It stops before a new claim when the configured target duration or PHP execution-time safety ceiling is reached, persists a `checker` heartbeat containing the stop state, and exits successfully. `CheckExecutor` only persists the result, counters, and release of the claim it owns; it no longer calculates the next scheduled time.

`SystemHeartbeat` persists named latest-seen records. `MonitorMaintenanceCommand` uses `StaleClaimRecovery` to release expired monitor leases in bounded batches and records a maintenance heartbeat. This is deliberately limited to stale lease recovery; retention belongs to PR7.

## Required proof

- A second claimant cannot claim the monitor after the first conditional update succeeds.
- Claim-time scheduling preserves phase and does not create a burst after a late tick.
- Budget exhaustion prevents another claim and records it in the checker heartbeat.
- Maintenance releases only expired claims and records its heartbeat.
- The documented `monitor:check-due` command exists and runs the sequential path.

## Constraints

- PHP 8.2+, MySQL 8.0+, SQLite test portability, Docker-only development.
- No runtime shell execution, daemon, broker, Redis, or queue worker.
- Time in Domain/Application code comes from the injected `Clock`.
- TaskConnect-derived patterns carry source attribution comments.
- `STATUS.md` and `BACKLOG.md` remain accurate; PR5 stays pending.
