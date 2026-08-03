# PR4 Scheduler Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make PR4's sequential scheduler meet its §21 Definition of Done before any PR5 parallel-execution work begins.

**Architecture:** Scheduler configuration controls a clock-bounded command loop. A portable transactional claimer atomically advances schedule state and leases exactly one monitor at a time; the executor records only the completed result. Named database heartbeats describe checker and maintenance progress, while maintenance releases expired leases in bounded batches.

**Tech Stack:** Laravel 12, PHP 8.2, MySQL 8 / SQLite in-memory tests, Docker Compose through `scripts/stc.ps1`.

## Global Constraints

- Production assumes only PHP 8.2+, MySQL 8.0+, per-minute cron, and `public/` document root.
- Scheduled work uses MySQL claim leases plus short-lived Artisan commands; no broker, daemon, queue worker, or Redis requirement.
- Do not call `exec`, `shell_exec`, `proc_open`, or `popen` at runtime.
- Run PHP, Composer, and Node only through the `stc` Docker wrapper.
- Time in Domain/Application code comes from injected `Clock`.
- New Eloquent models live in `app/Infrastructure/Persistence/Eloquent/`.
- Copied TaskConnect patterns include a source-attribution comment.
- Keep GitHub issue #5, `STATUS.md`, and `BACKLOG.md` current.

---

### Task 1: Establish failing scheduler-contract tests

**Files:**
- Modify: `tests/Unit/DueMonitorClaimerTest.php`
- Create: `tests/Unit/TickBudgetTest.php`
- Create: `tests/Feature/MonitorCheckDueCommandTest.php`
- Create: `tests/Feature/MonitorMaintenanceCommandTest.php`

**Interfaces:**
- Consumes: `DueMonitorClaimer::claimNext(): ?Monitor`, `Clock`, monitor persistence.
- Produces: executable specifications for claim atomicity, claim-time interval drift, budget stopping, and heartbeat/recovery behavior.

- [x] **Step 1: Write the claimer regression tests**

Assert that a first call leases one due monitor and a second claimer gets no result; assert its `next_check_at` is `12:01:00` when a monitor previously due at `12:00:00` has a 60-second interval and the clock is `12:00:30`; assert a monitor last due at `11:00:00` advances to `12:00:30`, not a sequence of backfilled runs.

- [x] **Step 2: Run only the claimer tests and verify they fail**

Run: `.\scripts\stc.ps1 test --filter=DueMonitorClaimerTest`

Expected: FAIL because the existing bulk claimer neither exposes the single-claim contract nor advances schedule state at claim time.

- [x] **Step 3: Write budget and command behavior tests**

Assert a controllable `TickBudget` becomes exhausted at its limit; assert `monitor:check-due` leaves a second due monitor unclaimed after a zero-length budget and writes `checker` heartbeat metadata with `budget_stopped: true`; assert `monitor:maintenance` clears only an expired lease and writes a `maintenance` heartbeat.

- [x] **Step 4: Run those new tests and verify they fail**

Run: `.\scripts\stc.ps1 test --filter='TickBudgetTest|MonitorCheckDueCommandTest|MonitorMaintenanceCommandTest'`

Expected: FAIL because the budget, commands, heartbeat model, and recovery service do not exist.

### Task 2: Implement portable claiming and scheduling semantics

**Files:**
- Create: `config/scheduler.php`
- Modify: `app/Application/Scheduling/DueMonitorClaimer.php`
- Modify: `app/Application/Scheduling/CheckExecutor.php`
- Modify: `tests/Unit/DueMonitorClaimerTest.php`

**Interfaces:**
- Consumes: `Clock::nowUtc()`, `config('scheduler.claim_ttl_minutes')`.
- Produces: `DueMonitorClaimer::claimNext(): ?Monitor`; a claimed monitor has a UUID token, a five-minute configured lease, and an already-advanced `next_check_at`.

- [x] **Step 1: Run the failing claimer tests again**

Run: `.\scripts\stc.ps1 test --filter=DueMonitorClaimerTest`

Expected: the failures from Task 1 remain reproducible.

- [x] **Step 2: Add minimal scheduler config and claim implementation**

Use `target_duration_seconds=45`, `budget_safety_margin_seconds=5`, and `claim_ttl_minutes=5`. In a transaction, select candidates ordered by `next_check_at`, lock with `FOR UPDATE SKIP LOCKED` only on MySQL and `lockForUpdate()` on SQLite, then conditionally update each candidate using the same due/lease predicate. Use `(string) Str::uuid()` and update `next_check_at` with `max(now, previous_next_check_at + interval_seconds)` in that conditional update.

- [x] **Step 3: Remove completion-time rescheduling from the executor**

Keep result persistence, counters, and conditional release of the claim in `CheckExecutor`; remove its `now + interval` schedule calculation so a crashed executor cannot create drift or a hot loop.

- [x] **Step 4: Run claimer and existing executor tests**

Run: `.\scripts\stc.ps1 test --filter='DueMonitorClaimerTest|CheckExecutorTest'`

Expected: PASS.

### Task 3: Implement tick budget, heartbeats, and maintenance recovery

**Files:**
- Create: `app/Application/Scheduling/TickBudget.php`
- Create: `app/Application/Scheduling/HeartbeatWriter.php`
- Create: `app/Application/Scheduling/StaleClaimRecovery.php`
- Create: `app/Infrastructure/Persistence/Eloquent/SystemHeartbeat.php`
- Create: `database/migrations/*_create_system_heartbeats_table.php`
- Create: `app/Console/Commands/MonitorCheckDueCommand.php`
- Create: `app/Console/Commands/MonitorMaintenanceCommand.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `tests/Unit/TickBudgetTest.php`
- Modify: `tests/Feature/MonitorCheckDueCommandTest.php`
- Modify: `tests/Feature/MonitorMaintenanceCommandTest.php`

**Interfaces:**
- Consumes: `Clock`, scheduler config, `DueMonitorClaimer`, `CheckExecutor`.
- Produces: `monitor:check-due`, `monitor:maintenance`, and named `SystemHeartbeat` records.

- [x] **Step 1: Confirm the Task 1 budget/command tests fail**

Run: `.\scripts\stc.ps1 test --filter='TickBudgetTest|MonitorCheckDueCommandTest|MonitorMaintenanceCommandTest'`

Expected: FAIL due to missing production types/commands.

- [x] **Step 2: Implement the minimal budget and heartbeat persistence**

Mirror the TaskConnect `TickBudget` wall-clock pattern with injectable fractional-time closure; cap the configured target by `max_execution_time - safety_margin`. Add `system_heartbeats(name unique, last_seen_at, meta_json)` and `HeartbeatWriter::record(string $name, array $meta = [])` using injected `Clock`.

- [x] **Step 3: Implement the two commands and stale lease recovery**

`monitor:check-due` repeatedly calls `claimNext()` only while `TickBudget::canClaimMore()`; after each completed sequential check it records `checker` with claimed/executed counts and `budget_stopped`. `monitor:maintenance` releases at most the configured batch of monitor claims whose expiry is strictly before now, then records `maintenance` with the recovered count.

- [x] **Step 4: Run the focused command tests**

Run: `.\scripts\stc.ps1 test --filter='TickBudgetTest|MonitorCheckDueCommandTest|MonitorMaintenanceCommandTest'`

Expected: PASS.

### Task 4: Verify the PR4 contract and update tracking

**Files:**
- Modify: `STATUS.md`
- Modify: `BACKLOG.md`
- Modify: `docs/superpowers/specs/2026-08-03-pr4-scheduler-remediation-design.md`
- Modify: `docs/superpowers/plans/2026-08-03-pr4-scheduler-remediation.md`

**Interfaces:**
- Consumes: the completed focused tests and full suite.
- Produces: honest issue and repository tracking evidence for the corrective PR.

- [x] **Step 1: Run the full PHPUnit suite**

Run: `.\scripts\stc.ps1 test`

Expected: PASS with no failures.

- [x] **Step 2: Inspect the command registry and migration status through Docker**

Run: `.\scripts\stc.ps1 artisan list --raw` and `.\scripts\stc.ps1 artisan migrate:status`

Expected: `monitor:check-due` and `monitor:maintenance` are listed; the system-heartbeat migration has run in the development database.

- [ ] **Step 3: Update tracking only with observed evidence**

Mark PR4 completed in `STATUS.md` and `BACKLOG.md` only after Step 1 passes. State that PR5 is next and remains unstarted. Add the actual test total and the verified scheduler behaviors to issue #5, then close it only after this branch's linked pull request is merged.

- [ ] **Step 4: Commit**

```bash
git add app config database tests STATUS.md BACKLOG.md docs
git commit -m "fix(PR4): complete sequential scheduler safeguards"
```
