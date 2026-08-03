# PR5 Parallel Execution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Execute due HTTP and TCP monitors in bounded, DNS-pinned parallel waves while preserving claim leases, tick budget, result fencing, and the checker heartbeat.

**Architecture:** A parallel application runner claims one wave at a time, invokes concrete HTTP/TCP probes, persists every completed outcome, and only then considers another claim. Infrastructure provides direct curl-multi and stream-select adapters; existing policy, assertion, redaction, clock, and claim-token boundaries remain authoritative.

**Tech Stack:** PHP 8.2, Laravel 12, ext-curl curl_multi, stream_socket_client, stream_select, MySQL 8.0, SQLite tests, Docker Compose.

## Global Constraints

- Shared-hosting cron only: no worker, daemon, broker, Redis, Horizon, Octane, or runtime shell execution.
- PHP, Composer, and tests run only through scripts/stc.ps1 / Docker.
- CHECK_CONCURRENCY defaults to 10 and is capped at 50. CHECK_WAVE_SIZE defaults to concurrency and no wave exceeds concurrency.
- Every user-supplied HTTP request has an explicitly pinned CURLOPT_RESOLVE entry; redirects are manual, bounded, revalidated, and re-pinned.
- TCP uses STREAM_CLIENT_ASYNC_CONNECT plus stream_select. Heartbeat monitors remain pure database work and take no concurrency slot.
- Application and Domain use the injected Clock; Eloquent stays under app/Infrastructure/Persistence/Eloquent.
- Retain CheckExecutor's claim-token fence, pure assertion evaluation, and centralized redaction.
- TaskConnect source code, if copied, gets a source-attribution comment. Keep issue #7, STATUS.md, and BACKLOG.md current; PR6 remains untouched.

---

### Task 1: Add bounded scheduler configuration and transport contracts

**Files:**

- Create: app/Application/Scheduling/SchedulerLimits.php
- Modify: config/scheduler.php
- Create: app/Infrastructure/HttpClient/MultiPinnedHttpProbe.php
- Create: app/Infrastructure/HttpClient/MultiPinnedHttpRequest.php
- Create: app/Infrastructure/HttpClient/MultiPinnedHttpResult.php
- Create: app/Infrastructure/Tcp/PinnedTcpProbe.php
- Create: app/Infrastructure/Tcp/PinnedTcpRequest.php
- Create: app/Infrastructure/Tcp/PinnedTcpResult.php
- Create: app/Domain/Outbound/TcpTargetValidator.php
- Test: tests/Unit/SchedulerLimitsTest.php

**Interfaces:**

- Produces SchedulerLimits::fromConfig(): SchedulerLimits with public int $concurrency and public int $waveSize.
- Produces MultiPinnedHttpProbe::probe(array $requests): array and PinnedTcpProbe::probe(array $requests): array, both keyed by monitor id.

- [ ] **Step 1: Write the failing limit tests**

~~~php
public function test_concurrency_is_capped_at_fifty(): void
{
    config()->set('scheduler.check_concurrency', 99);

    $this->assertSame(50, SchedulerLimits::fromConfig()->concurrency);
}

public function test_wave_size_defaults_to_concurrency(): void
{
    config()->set('scheduler.check_concurrency', 7);
    config()->set('scheduler.check_wave_size', null);

    $this->assertSame(7, SchedulerLimits::fromConfig()->waveSize);
}
~~~

- [ ] **Step 2: Run the failing test**

Run: ./scripts/stc.ps1 test --filter SchedulerLimitsTest

Expected: FAIL because SchedulerLimits is absent.

- [ ] **Step 3: Implement the minimum contracts**

~~~php
$concurrency = min(50, max(1, (int) config('scheduler.check_concurrency', 10)));
$configuredWaveSize = config('scheduler.check_wave_size');

return new self(
    concurrency: $concurrency,
    waveSize: min($concurrency, max(1, (int) ($configuredWaveSize ?? $concurrency))),
);
~~~

Add check_concurrency using env CHECK_CONCURRENCY default 10 and nullable check_wave_size using env CHECK_WAVE_SIZE. Make all request/result DTOs readonly and free of Laravel facades.

- [ ] **Step 4: Verify and commit**

Run: ./scripts/stc.ps1 test --filter SchedulerLimitsTest

Expected: PASS.

~~~powershell
git add config/scheduler.php app/Application/Scheduling/SchedulerLimits.php app/Infrastructure/HttpClient/MultiPinnedHttpProbe.php app/Infrastructure/HttpClient/MultiPinnedHttpRequest.php app/Infrastructure/HttpClient/MultiPinnedHttpResult.php app/Infrastructure/Tcp/PinnedTcpProbe.php app/Infrastructure/Tcp/PinnedTcpRequest.php app/Infrastructure/Tcp/PinnedTcpResult.php tests/Unit/SchedulerLimitsTest.php
git commit -m "feat(PR5): add bounded parallel probe contracts"
~~~

### Task 2: Implement the DNS-pinned curl-multi HTTP probe

**Files:**

- Create: app/Infrastructure/HttpClient/CurlMultiPinnedProbe.php
- Create: app/Infrastructure/HttpClient/CurlMultiHandleFactory.php
- Create: app/Infrastructure/HttpClient/NativeCurlMultiHandleFactory.php
- Modify: app/Providers/AppServiceProvider.php
- Test: tests/Unit/CurlMultiPinnedProbeTest.php
- Test: tests/Feature/ParallelHttpProbeTest.php

**Interfaces:**

- Consumes MultiPinnedHttpRequest, PinnedHttpRequest, OutboundPolicy.
- Produces one MultiPinnedHttpResult for every submitted monitor id, each carrying a PinnedHttpResponse.

- [ ] **Step 1: Write the failing pinning and concurrency tests**

~~~php
public function test_every_created_handle_has_a_resolve_entry(): void
{
    $this->probe->probe([
        new MultiPinnedHttpRequest(101, $this->requestFor('example.test', 443, '203.0.113.8')),
        new MultiPinnedHttpRequest(102, $this->requestFor('example.test', 443, '203.0.113.9')),
    ]);

    $this->assertSame(['example.test:443:203.0.113.8'], $this->factory->optionsFor(101)[CURLOPT_RESOLVE]);
    $this->assertSame(['example.test:443:203.0.113.9'], $this->factory->optionsFor(102)[CURLOPT_RESOLVE]);
}

public function test_thirty_delayed_targets_finish_concurrently(): void
{
    $startedAt = hrtime(true);
    $results = app(MultiPinnedHttpProbe::class)->probe($this->thirtyTargetRequests('/delay/5000'));

    $this->assertCount(30, $results);
    $this->assertLessThan(9000, (hrtime(true) - $startedAt) / 1000000);
}
~~~

The unit test uses an injectable handle factory that records curl options. The feature test targets Docker service target and may skip only if that service cannot be reached.

- [ ] **Step 2: Run the tests to verify failure**

Run: ./scripts/stc.ps1 test --filter "CurlMultiPinnedProbeTest|ParallelHttpProbeTest"

Expected: FAIL because CurlMultiPinnedProbe is absent.

- [ ] **Step 3: Implement the direct multi loop**

~~~php
$resolve = sprintf('%s:%d:%s', $endpoint->host, $endpoint->port, $endpoint->pinnedIp);
$this->factory->setoptArray($handle, [
    CURLOPT_RESOLVE => [$resolve],
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
    CURLOPT_TIMEOUT => $totalTimeout,
]);
~~~

Create one easy handle per request, drive curl_multi_exec and curl_multi_select until every handle completes, bound collected bodies to the configured profile limit, and close every handle/multi handle. Convert connection failures to PinnedHttpResponse transport errors. For each redirect, call OutboundPolicy::validateUrl again, choose its validated pinned IP, and enqueue a new handle until the profile redirect limit; never permit curl automatic redirects. Bind the interface as a singleton.

- [ ] **Step 4: Verify and commit**

Run: ./scripts/stc.ps1 test --filter "CurlMultiPinnedProbeTest|ParallelHttpProbeTest"

Expected: PASS, including the 30 target concurrency proof and resolve-entry assertions.

~~~powershell
git add app/Infrastructure/HttpClient/CurlMultiPinnedProbe.php app/Infrastructure/HttpClient/CurlMultiHandleFactory.php app/Infrastructure/HttpClient/NativeCurlMultiHandleFactory.php app/Providers/AppServiceProvider.php tests/Unit/CurlMultiPinnedProbeTest.php tests/Feature/ParallelHttpProbeTest.php
git commit -m "feat(PR5): add DNS-pinned curl multi probe"
~~~

### Task 3: Implement the non-blocking pinned TCP probe

**Files:**

- Create: app/Infrastructure/Tcp/StreamSelectPinnedTcpProbe.php
- Create: app/Infrastructure/Tcp/SocketFactory.php
- Create: app/Infrastructure/Tcp/NativeSocketFactory.php
- Create: app/Infrastructure/Tcp/StreamSelector.php
- Create: app/Infrastructure/Tcp/NativeStreamSelector.php
- Test: tests/Unit/StreamSelectPinnedTcpProbeTest.php
- Test: tests/Feature/ParallelTcpProbeTest.php

**Interfaces:**

- Consumes PinnedTcpRequest only after TcpTargetValidator has parsed host:port, resolved it once, classified every resolved IP, enforced the configured TCP port policy, and created a validated, pinned endpoint.
- Produces PinnedTcpResult with monitor id, latency milliseconds, successful boolean, and nullable error.

- [ ] **Step 1: Write the failing TCP batch test**

~~~php
public function test_uses_async_connect_and_select_for_all_pending_sockets(): void
{
    $results = $this->probe->probe([$this->request(1), $this->request(2)]);

    $this->assertSame(STREAM_CLIENT_ASYNC_CONNECT, $this->socketFactory->flagsFor(1));
    $this->assertSame(STREAM_CLIENT_ASYNC_CONNECT, $this->socketFactory->flagsFor(2));
    $this->assertGreaterThanOrEqual(1, $this->selector->callCount());
    $this->assertCount(2, $results);
}
~~~

- [ ] **Step 2: Run the test to verify failure**

Run: ./scripts/stc.ps1 test --filter "StreamSelectPinnedTcpProbeTest|ParallelTcpProbeTest"

Expected: FAIL because StreamSelectPinnedTcpProbe is absent.

- [ ] **Step 3: Implement async connection completion**

~~~php
$socket = stream_socket_client(
    sprintf('tcp://%s:%d', $request->endpoint->pinnedIp, $request->endpoint->port),
    $errorCode,
    $errorMessage,
    0,
    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
);
stream_set_blocking($socket, false);
~~~

First add TcpTargetValidator under Domain/Outbound. It accepts host:port, rejects malformed targets and disallowed ports, resolves the host through DnsResolverInterface once, applies IpClassifier to every answer, and returns one ValidatedEndpoint using its selected safe IP. CheckExecutor must map an OutboundPolicyViolation from this validator to CheckOutcome::blocked. Then map every writable stream to its monitor request and deadline. Call stream_select with the nearest deadline, use socket_get_option with SOL_SOCKET and SO_ERROR to distinguish connected/error sockets, emit a timeout result for expired work, and close every socket. The TCP target must be the pinned IP, never the hostname.

- [ ] **Step 4: Verify and commit**

Run: ./scripts/stc.ps1 test --filter "StreamSelectPinnedTcpProbeTest|ParallelTcpProbeTest"

Expected: PASS; the factory observes async flags and the selector receives a batch.

~~~powershell
git add app/Domain/Outbound/TcpTargetValidator.php app/Infrastructure/Tcp/StreamSelectPinnedTcpProbe.php app/Infrastructure/Tcp/SocketFactory.php app/Infrastructure/Tcp/NativeSocketFactory.php app/Infrastructure/Tcp/StreamSelector.php app/Infrastructure/Tcp/NativeStreamSelector.php tests/Unit/StreamSelectPinnedTcpProbeTest.php tests/Feature/ParallelTcpProbeTest.php
git commit -m "feat(PR5): add asynchronous pinned TCP probe"
~~~

### Task 4: Refactor outcome preparation and retain the persistence fence

**Files:**

- Modify: app/Application/Scheduling/CheckExecutor.php
- Create: app/Application/Scheduling/PreparedMonitorCheck.php
- Test: tests/Feature/CheckExecutorTest.php

**Interfaces:**

- Produces CheckExecutor::prepare(Monitor): PreparedMonitorCheck, CheckExecutor::outcomeFromHttpResponse(...): CheckOutcome, CheckExecutor::outcomeFromTcpResult(...): CheckOutcome, and CheckExecutor::persist(Monitor, CheckOutcome): ?CheckResult.
- Existing execute(Monitor) remains a compatibility wrapper until Task 5 migrates the command.

- [ ] **Step 1: Write the failing persistence test**

~~~php
public function test_persists_each_completed_wave_outcome_with_its_original_claim_token(): void
{
    $first = $this->claimedHttpMonitor('first');
    $second = $this->claimedHttpMonitor('second');

    $executor->persist($first, new CheckOutcome(statusCode: 200, latencyMs: 25));
    $executor->persist($second, new CheckOutcome(statusCode: 503, latencyMs: 30));

    $this->assertSame(2, CheckResult::query()->count());
    $this->assertNull($first->fresh()->claim_token);
    $this->assertNull($second->fresh()->claim_token);
}
~~~

- [ ] **Step 2: Run the test to verify failure**

Run: ./scripts/stc.ps1 test --filter CheckExecutorTest

Expected: FAIL because the batch preparation/persist methods do not exist.

- [ ] **Step 3: Refactor with the original safety boundary**

Build HTTP and TCP request DTOs only after OutboundPolicy validation. Keep assertion evaluation in the existing pure evaluator and execute monitor/update/result creation in the existing database transaction. Preserve the condition that update uses both monitor id and the original claim token; create no CheckResult when the update affects zero rows. Redact non-up excerpts exactly as today.

- [ ] **Step 4: Verify and commit**

Run: ./scripts/stc.ps1 test --filter CheckExecutorTest

Expected: PASS, including the existing stale-executor fence test.

~~~powershell
git add app/Application/Scheduling/CheckExecutor.php app/Application/Scheduling/PreparedMonitorCheck.php tests/Feature/CheckExecutorTest.php
git commit -m "refactor(PR5): prepare and fence batch check outcomes"
~~~

### Task 5: Add the wave runner and prove the PR definition of done

**Files:**

- Create: app/Application/Scheduling/ParallelMonitorCheckRunner.php
- Modify: app/Console/Commands/MonitorCheckDueCommand.php
- Modify: app/Providers/AppServiceProvider.php
- Delete: app/Application/Scheduling/SequentialMonitorCheckRunner.php
- Test: tests/Feature/ParallelMonitorCheckRunnerTest.php
- Test: tests/Feature/ParallelExecutionLoadFixtureTest.php
- Modify: tests/Feature/MonitorCheckDueCommandTest.php
- Delete: tests/Feature/SequentialMonitorCheckRunnerTest.php

**Interfaces:**

- Consumes DueMonitorClaimer::claimDueMonitors(int, array, ?int), SchedulerLimits, TickBudget, both probe interfaces, CheckExecutor, and HeartbeatWriter.
- Produces the existing stats shape array{claimed:int, executed:int, budget_stopped:bool} and one checker heartbeat per invocation.

- [ ] **Step 1: Write failing wave and load tests**

~~~php
public function test_completes_and_persists_a_wave_before_claiming_the_next(): void
{
    config()->set('scheduler.check_concurrency', 2);
    config()->set('scheduler.check_wave_size', 2);

    $stats = app(ParallelMonitorCheckRunner::class)->run($this->tenSecondBudget());

    $this->assertSame(4, $stats['claimed']);
    $this->assertSame(4, $stats['executed']);
    $this->assertSame([2, 2], $this->claimerSpy->claimLimits());
    $this->assertSame(4, CheckResult::query()->count());
}

public function test_thirty_five_second_monitors_finish_inside_one_tick_budget(): void
{
    config()->set('scheduler.check_concurrency', 10);
    config()->set('scheduler.check_wave_size', 10);

    $stats = app(ParallelMonitorCheckRunner::class)->run($this->fortyFiveSecondBudget());

    $this->assertSame(30, $stats['executed']);
    $this->assertFalse($stats['budget_stopped']);
}
~~~

Create 30 due monitors pointing to TARGET_URL plus /delay/5000 with 10-second per-monitor timeouts. Measure elapsed work with hrtime and require less than 20 seconds, conservative for three waves of 10 and inside the 45-second tick target. Add tests that a depleted budget releases unstarted claims and writes budget_stopped.

- [ ] **Step 2: Run the tests to verify failure**

Run: ./scripts/stc.ps1 test --filter "ParallelMonitorCheckRunnerTest|ParallelExecutionLoadFixtureTest|MonitorCheckDueCommandTest"

Expected: FAIL because ParallelMonitorCheckRunner is absent.

- [ ] **Step 3: Implement the wave lifecycle**

~~~php
while ($budget->canClaimMore()) {
    $wave = $claimer->claimDueMonitors($limits->waveSize, $claimedIds, $maxTimeoutMs);
    if ($wave->isEmpty()) {
        break;
    }

    $prepared = $wave->map(fn (Monitor $monitor) => $executor->prepare($monitor));
    $completed = $this->runHttpAndTcpConcurrently($prepared, $limits->concurrency);

    foreach ($completed as $result) {
        $executor->persist($result->monitor, $result->outcome);
    }
}
~~~

Calculate maxTimeoutMs from remaining budget minus the configured reserve as PR4 does. Process heartbeat monitors via their database comparison without placing them in HTTP/TCP probe inputs. Persist every completed wave before testing the budget again; never pre-claim a following wave. Release a monitor claim when its timeout cannot fit remaining time. Make MonitorCheckDueCommand depend on ParallelMonitorCheckRunner, record the existing heartbeat payload, and remove the sequential runner/tests after migration.

- [ ] **Step 4: Run focused and full verification**

Run: ./scripts/stc.ps1 test --filter "ParallelMonitorCheckRunnerTest|ParallelExecutionLoadFixtureTest|MonitorCheckDueCommandTest"

Expected: PASS; 30 delayed monitors finish below 20 seconds and produce 30 persisted results.

Run: ./scripts/stc.ps1 test

Expected: PASS.

- [ ] **Step 5: Commit the scheduler integration**

~~~powershell
git add app/Application/Scheduling/ParallelMonitorCheckRunner.php app/Application/Scheduling/CheckExecutor.php app/Console/Commands/MonitorCheckDueCommand.php app/Providers/AppServiceProvider.php tests/Feature/ParallelMonitorCheckRunnerTest.php tests/Feature/ParallelExecutionLoadFixtureTest.php tests/Feature/MonitorCheckDueCommandTest.php
git rm app/Application/Scheduling/SequentialMonitorCheckRunner.php tests/Feature/SequentialMonitorCheckRunnerTest.php
git commit -m "feat(PR5): execute due monitors in bounded waves"
~~~

### Task 6: Record tracking and perform final evidence collection

**Files:**

- Modify: STATUS.md
- Modify: BACKLOG.md
- Modify: docs/superpowers/specs/2026-08-03-pr5-parallel-execution-design.md only if implementation changes an approved design detail

**Interfaces:**

- Consumes actual final verification output.
- Produces an accurate PR5 status, issue link, fixture duration, pinning evidence, and the next PR in sequence.

- [ ] **Step 1: Update documentation after verification**

Mark PR5 completed only after all tests pass. Record issue #7, the measured 30-monitor/5-second fixture duration, the every-handle resolve assertion, and the final test/assertion totals. Leave PR6 pending.

- [ ] **Step 2: Run final quality gates**

Run: git diff --check

Expected: no output.

Run: ./scripts/stc.ps1 test

Expected: PASS.

- [ ] **Step 3: Commit docs and publish evidence**

~~~powershell
git add STATUS.md BACKLOG.md docs/superpowers/specs/2026-08-03-pr5-parallel-execution-design.md
git commit -m "docs(PR5): record parallel execution verification"
~~~

Push codex/pr5-parallel-execution, open a PR that links Closes #7 and reports exact verification output, then merge only after review. Retest main and comment on the closed issue with the same evidence.

## Plan self-review

- **Spec coverage:** Tasks 1 and 5 cover bounded concurrency, waves, tick-budget stops, and heartbeat behavior. Task 2 covers concurrent HTTP, every-handle pinning, and redirects. Task 3 covers non-blocking TCP. Task 4 preserves policy, assertions, redaction, and token fencing. Task 6 covers tracking and linked-issue closure.
- **Placeholder scan:** Every task gives concrete files, contracts, a failing test, a verification command, and an exact implementation or behavior target.
- **Type consistency:** SchedulerLimits precedes the runner; probe contracts precede implementations; CheckExecutor preparation/persistence precedes its use by ParallelMonitorCheckRunner.
