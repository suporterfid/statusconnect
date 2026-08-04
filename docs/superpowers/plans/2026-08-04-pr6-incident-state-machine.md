# PR6 Incident State Machine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Persist deterministic confirmation, recovery, incident lifecycle, and flap decisions for monitor checks.

**Architecture:** Domain values calculate monitor and incident transitions without Laravel, database, or network access. An Application service applies each transition transactionally and relies on a MySQL-compatible unique open-incident constraint; CheckExecutor delegates state/counter persistence to that service.

**Tech Stack:** PHP 8.2, Laravel 12, MySQL 8.0, SQLite tests, Docker Compose.

## Global Constraints

- Docker-only PHP/Composer/test execution; no worker, broker, daemon, Redis, or runtime shell execution.
- Domain/Application time is explicit through Clock; no now(), time(), or DateTime construction there.
- Domain rules remain pure and unit-tested without I/O.
- Eloquent models live only in app/Infrastructure/Persistence/Eloquent.
- Open incidents must be idempotent through a database constraint, not an application pre-check.
- blocked never increments failures or opens an incident.
- Keep issue #9, STATUS.md, and BACKLOG.md accurate; do not start PR7.

---

### Task 1: Pure state-machine values and threshold transitions

**Files:**

- Create: app/Domain/Incidents/IncidentStateMachine.php
- Create: app/Domain/Incidents/MonitorTransition.php
- Create: app/Domain/Incidents/IncidentAction.php
- Test: tests/Unit/IncidentStateMachineTest.php

**Interfaces:**

- Produces IncidentStateMachine::transition(MonitorSnapshot $monitor, CheckState $outcome, DateTimeImmutable $checkedAt): MonitorTransition.
- MonitorTransition exposes nextState, consecutiveFailures, consecutiveSuccesses, firstFailureAt, and IncidentAction (none/open/resolve/configurationFault).

- [ ] **Step 1: Write failing transition tests**

~~~php
public function test_confirmation_opens_at_the_threshold_with_the_first_failure_time(): void
{
    $transition = $this->machine->transition(
        new MonitorSnapshot(CheckState::UP, 2, 0, new DateTimeImmutable('2026-08-04T10:00:00Z')),
        CheckState::DOWN,
        new DateTimeImmutable('2026-08-04T10:02:00Z'),
        confirmationThreshold: 3,
        recoveryThreshold: 2,
    );

    $this->assertSame(IncidentAction::OPEN, $transition->action);
    $this->assertEquals(new DateTimeImmutable('2026-08-04T10:00:00Z'), $transition->firstFailureAt);
}
~~~

Add independent tests for below-threshold failure, recovery boundary, degraded severity, and blocked preserving both streaks.

- [ ] **Step 2: Verify RED**

Run: ./scripts/stc.ps1 test --filter IncidentStateMachineTest

Expected: FAIL because IncidentStateMachine is absent.

- [ ] **Step 3: Implement pure transition rules**

~~~php
if ($outcome === CheckState::BLOCKED) {
    return new MonitorTransition($monitor->state, $monitor->failures, $monitor->successes, $monitor->firstFailureAt, IncidentAction::CONFIGURATION_FAULT);
}
~~~

Retain firstFailureAt on every non-up check in an unconfirmed streak; reset it only after confirmed recovery or a successful check that breaks an unconfirmed streak.

- [ ] **Step 4: Verify GREEN and commit**

Run: ./scripts/stc.ps1 test --filter IncidentStateMachineTest

Expected: PASS.

~~~powershell
git add app/Domain/Incidents tests/Unit/IncidentStateMachineTest.php
git commit -m "feat(PR6): add pure incident state machine"
~~~

### Task 2: Incident schema and Eloquent persistence models

**Files:**

- Create: database/migrations/YYYY_MM_DD_HHMMSS_create_incidents_tables.php
- Create: app/Infrastructure/Persistence/Eloquent/Incident.php
- Create: app/Infrastructure/Persistence/Eloquent/IncidentUpdate.php
- Modify: app/Infrastructure/Persistence/Eloquent/Monitor.php
- Test: tests/Feature/IncidentPersistenceTest.php

**Interfaces:**

- Incidents carry monitor_id nullable, manual boolean, resolved_flag boolean, started_at, confirmed_at, resolved_at, duration_seconds, severity, and summary.
- The unique index on (monitor_id, resolved_flag) prevents two unresolved monitor incidents; manual incidents use null monitor_id.

- [ ] **Step 1: Write failing persistence tests**

~~~php
public function test_unique_open_incident_constraint_prevents_a_second_open_monitor_incident(): void
{
    Incident::factory()->create(['monitor_id' => $this->monitor->id, 'resolved_flag' => false]);

    $this->expectException(QueryException::class);
    Incident::factory()->create(['monitor_id' => $this->monitor->id, 'resolved_flag' => false]);
}
~~~

- [ ] **Step 2: Verify RED**

Run: ./scripts/stc.ps1 test --filter IncidentPersistenceTest

Expected: FAIL because the table/model are absent.

- [ ] **Step 3: Add migration/model**

Use boolean resolved_flag default false and set it true only when resolved. Add monitor and updates relations. Add monitor first_failure_at and flapping_since timestamps required by later tasks.

- [ ] **Step 4: Verify GREEN and commit**

Run: ./scripts/stc.ps1 test --filter IncidentPersistenceTest

Expected: PASS on SQLite and the migration creates a MySQL-compatible unique index.

~~~powershell
git add database/migrations app/Infrastructure/Persistence/Eloquent tests/Feature/IncidentPersistenceTest.php
git commit -m "feat(PR6): persist incidents with an open-incident constraint"
~~~

### Task 3: Transactional IncidentService and CheckExecutor integration

**Files:**

- Create: app/Application/Incidents/IncidentService.php
- Modify: app/Application/Scheduling/CheckExecutor.php
- Modify: app/Providers/AppServiceProvider.php
- Test: tests/Feature/IncidentServiceTest.php
- Modify: tests/Feature/CheckExecutorTest.php

**Interfaces:**

- IncidentService::record(Monitor $monitor, CheckOutcome $outcome): ?CheckResult applies monitor state, result, and incident lifecycle in one DB transaction.
- CheckExecutor::persist delegates to IncidentService instead of calculating counters itself.

- [ ] **Step 1: Write failing lifecycle tests**

~~~php
public function test_opened_incident_starts_at_the_first_failing_check(): void
{
    $this->service->record($this->monitorWithThreshold(3), $this->downOutcome('10:00'));
    $this->service->record($this->monitor->fresh(), $this->downOutcome('10:01'));
    $this->service->record($this->monitor->fresh(), $this->downOutcome('10:02'));

    $incident = Incident::query()->sole();
    $this->assertEquals('10:00', $incident->started_at->format('H:i'));
    $this->assertEquals('10:02', $incident->confirmed_at->format('H:i'));
}
~~~

Add a blocked test that asserts zero incidents and unchanged failure count; add recovery threshold and stale claim-token fence tests.

- [ ] **Step 2: Verify RED**

Run: ./scripts/stc.ps1 test --filter "IncidentServiceTest|CheckExecutorTest"

Expected: FAIL because IncidentService is absent.

- [ ] **Step 3: Implement the transaction boundary**

Apply the pure transition first, update Monitor only where the claim token still matches, create CheckResult, then use firstOrCreate on the database-protected open incident. Resolve only automatic monitor incidents and calculate duration_seconds from the transition timestamps.

- [ ] **Step 4: Verify GREEN and commit**

Run: ./scripts/stc.ps1 test --filter "IncidentServiceTest|CheckExecutorTest"

Expected: PASS.

~~~powershell
git add app/Application/Incidents app/Application/Scheduling/CheckExecutor.php app/Providers/AppServiceProvider.php tests/Feature
git commit -m "feat(PR6): persist confirmed incident lifecycles"
~~~

### Task 4: Flap policy and manual incidents

**Files:**

- Create: app/Domain/Incidents/FlapPolicy.php
- Modify: app/Application/Incidents/IncidentService.php
- Test: tests/Unit/FlapPolicyTest.php
- Test: tests/Feature/IncidentServiceTest.php

**Interfaces:**

- FlapPolicy::evaluate(int $recentCycles, int $threshold): bool.
- IncidentService records flapping_since and returns notificationAllowed=false when the configured window throttle applies.

- [ ] **Step 1: Write failing tests**

~~~php
public function test_more_than_five_cycles_in_the_window_marks_the_monitor_flapping(): void
{
    $this->assertTrue((new FlapPolicy())->evaluate(recentCycles: 6, threshold: 5));
}

public function test_manual_incident_is_never_auto_resolved(): void
{
    $manual = Incident::factory()->manual()->create(['monitor_id' => $this->monitor->id]);
    $this->service->record($this->monitor, $this->upOutcome());

    $this->assertNull($manual->fresh()->resolved_at);
}
~~~

- [ ] **Step 2: Verify RED**

Run: ./scripts/stc.ps1 test --filter "FlapPolicyTest|IncidentServiceTest"

Expected: FAIL because FlapPolicy is absent.

- [ ] **Step 3: Implement policy and service behavior**

Count monitor lifecycle cycles whose timestamps are inside FLAP_WINDOW_MINUTES; mark flapping only after more than FLAP_THRESHOLD. Keep notification throttling as a returned decision/metadata because PR9 owns delivery.

- [ ] **Step 4: Verify GREEN and commit**

Run: ./scripts/stc.ps1 test --filter "FlapPolicyTest|IncidentServiceTest"

Expected: PASS.

~~~powershell
git add app/Domain/Incidents/FlapPolicy.php app/Application/Incidents/IncidentService.php tests
git commit -m "feat(PR6): detect flapping and protect manual incidents"
~~~

### Task 5: Final tracking and Definition of Done evidence

**Files:**

- Modify: STATUS.md
- Modify: BACKLOG.md
- Test: tests/Unit/IncidentStateMachineTest.php
- Test: tests/Feature/IncidentServiceTest.php

- [ ] **Step 1: Add exhaustive boundary coverage**

Cover pending/up/degraded/down paths, confirmation/recovery thresholds one below/equal/above, blocked, first failure timestamp, open-incident idempotency, flapping, and manual incidents.

- [ ] **Step 2: Run verification**

Run: git diff --check

Expected: no output.

Run: ./scripts/stc.ps1 test

Expected: PASS.

- [ ] **Step 3: Record only actual results and commit**

Update STATUS.md and BACKLOG.md with issue #9, exact final test totals, and PR7 as next only after all tests pass.

~~~powershell
git add STATUS.md BACKLOG.md tests
git commit -m "docs(PR6): record incident state verification"
~~~

- [ ] **Step 4: Publish PR evidence**

Push codex/pr6-incident-state-machine, open a PR with Closes #9, include the exact test total and the started_at/blocked proofs, request review, merge only after approval, retest main, and close the issue with that evidence.

## Plan self-review

- **Spec coverage:** Tasks 1/3 implement confirmation, recovery, blocked, severity, and first-failure timestamps; Task 2 provides the required unique open-incident constraint; Task 4 covers flapping and manual incidents; Task 5 enforces the PR6 Definition of Done and tracking.
- **Placeholder scan:** Every task has concrete paths, APIs, a failing test, a verification command, and an implementation target.
- **Type consistency:** IncidentStateMachine returns MonitorTransition; IncidentService consumes it; CheckExecutor calls IncidentService; FlapPolicy remains pure and is consumed only by IncidentService.
