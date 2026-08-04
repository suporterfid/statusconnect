# StatusConnect — Initial Spec & First-Implementation Build Plan

> Uptime monitoring and public status pages for the cPanel your grandpa never gave up.

## Document Metadata

- **Version:** 1.0
- **Status:** Implementation-ready v0 specification + build plan
- **Target audience:** AI coding agents (Claude Code CLI), architects, maintainers, contributors
- **Proposed repository:** `suporterfid/statusconnect` (public, MIT)
- **Primary deployment target:** PHP + MySQL shared hosting with cron (Hostinger, cPanel/LiteSpeed)
- **Reference deployment profile:** shared hosting without VPS, Redis, long-running workers, or process supervisors
- **Development environment:** Docker only (no host PHP/Composer/Node)
- **Initial interface languages:** English (`en`) and Brazilian Portuguese (`pt-BR`)
- **Sibling projects:** [`taskconnect`](https://github.com/suporterfid/taskconnect) (architecture donor), [`grandpasson`](https://github.com/suporterfid/grandpasson) (identity), [`jotter`](https://github.com/suporterfid/jotter)

---

## 0. How a coding agent should use this document

1. Read this document end to end **before** writing code. It is the authority for v0.
2. Implement in the **PR sequence of §21**. One PR unit at a time. Do not start PR *n+1* before PR *n* is green.
3. Every requirement uses RFC-2119 keywords (§2). A **MUST** that is not met blocks the PR.
4. When this document and a later `README.md`/`STATUS.md` disagree, **this document wins** until it is explicitly amended.
5. The **hard constraints of §4 are non-negotiable.** Reject any dependency or design that violates them, and say so rather than silently working around them.
6. Keep `STATUS.md` and `BACKLOG.md` current as scope changes.
7. Where this document says *"mirror TaskConnect"*, read the referenced TaskConnect file before implementing — the intent is a deliberate, reviewed copy of a proven pattern, not a fresh invention.

---

## 1. Executive Summary

StatusConnect is an open-source, multi-tenant **uptime monitor and public status page** that runs on commodity PHP + MySQL shared hosting.

A per-minute cron invocation of a short-lived PHP process claims due monitors from MySQL, performs checks in parallel via `curl_multi`, records results, opens and closes incidents according to a confirmation/recovery state machine, and dispatches notifications. A public, unauthenticated status page renders current component state, uptime percentages, and an incident timeline from pre-aggregated data.

### 1.1 Why this project exists (the gap)

Every mature self-hosted uptime monitor assumes infrastructure that shared hosting does not have:

| Project | Runtime | Blocker on shared hosting |
|---|---|---|
| Uptime Kuma | Node.js | Requires an always-on process |
| Gatus | Go | Single long-running binary |
| Kener | SvelteKit / Node | Requires Node runtime |
| openstatus | TypeScript + Postgres/Tinybird | Requires managed services |
| Cachet | PHP | **Archived / unmaintained**; status page only, no checker |
| Checkmk / Zabbix / Nagios | Daemons | Far outside the profile |

There is currently **no maintained PHP + MySQL uptime monitor with a public status page that installs on a €3/month Hostinger plan.** StatusConnect fills exactly that gap.

### 1.2 Why this architecture already exists

StatusConnect is the architectural sibling of TaskConnect. The hard parts — cron-driven MySQL claim leases, wall-clock tick budgeting, SSRF-safe DNS-pinned outbound HTTP, secret encryption and redaction, multi-tenant isolation, the shared-hosting release pipeline — are **already solved and tested** in `suporterfid/taskconnect`. This spec reuses those patterns deliberately and by name.

The genuinely new engineering in StatusConnect is:

1. **Parallel checking** (`curl_multi`) instead of TaskConnect's sequential delivery — the check-throughput ceiling is the defining constraint of this product (§8).
2. **Rollups and retention** — an uptime monitor writes 1,440 rows per monitor per day at 60s cadence; raw retention would exceed shared-hosting database quotas within weeks (§13).
3. **An unauthenticated, high-traffic public surface** — the status page must be cheap and must never leak tenant internals (§11).
4. **A flap-resistant incident state machine** — confirmation and recovery thresholds, maintenance windows (§10).

---

## 2. Normative Language

The terms **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**, and **MAY** express requirement priority:

- **MUST / MUST NOT** — mandatory for the stated release; a violation blocks merge.
- **SHOULD / SHOULD NOT** — strongly recommended; deviation requires a documented reason in the PR description.
- **MAY** — optional.

Unless explicitly marked deferred, requirements in this document apply to **v0**.

---

## 3. Mission and Scope

### 3.1 Mission

Give a self-hoster on commodity shared hosting a credible replacement for a paid uptime-monitoring SaaS: monitor endpoints, get alerted when they break, and publish a trustworthy public status page — with no VPS, no container runtime, and no monthly bill.

### 3.2 Primary goals

- Minute-granularity uptime monitoring of HTTP(S) and TCP endpoints.
- Confirmed incident detection that does not page on a single transient blip.
- A fast, public, unauthenticated status page with uptime history and an incident timeline.
- Multi-tenant isolation strong enough to host several unrelated projects on one installation.
- Deployable by uploading a zip and setting one cron line.
- First-class delegated identity via GrandpaSSOn and first-class interoperation with TaskConnect.

### 3.3 Secondary goals

- Dead-man's-switch (heartbeat) monitors, so cron-driven jobs elsewhere can prove they ran.
- TLS certificate expiry warnings derived from existing HTTPS checks.
- Response-time history, not just up/down.
- Brazilian Portuguese and English UI from day one.

### 3.4 Non-goals (v0 — explicit, prevents scope creep)

StatusConnect v0 **MUST NOT** attempt:

- Sub-minute check intervals. The floor is 60 seconds and it is a property of cron.
- Distributed multi-region checking. One installation checks from one place.
- Synthetic browser monitoring, Playwright journeys, or JavaScript rendering.
- Log ingestion, APM, tracing, or metrics scraping (Prometheus/OpenTelemetry).
- SMS, telephony, or paid push providers as *required* dependencies.
- On-call scheduling, rotations, or escalation policies.
- A hosted/SaaS control plane.
- Real-time push to the status page (WebSockets/SSE). Polling only.
- ICMP ping monitors. Raw sockets are unavailable to the PHP user on shared hosting; §7.2 explains the substitute.

---

## 4. Hard constraints (the walls)

These are project invariants, inherited from TaskConnect's `CLAUDE.md`. Reject any change or dependency that would violate them.

### 4.1 Must stay deployable on commodity shared hosting

Production capability assumed is exactly: **PHP 8.2+, MySQL 8.0+, a per-minute cron, and a document root pointed at `public/`.**

- **No dependency on an always-on process, daemon, or broker.** This rules out Redis, Memcached, RabbitMQ, Beanstalkd, Kafka, Laravel Horizon, Reverb, Supervisor-managed `queue:work`, Octane, and equivalents. None of these may become *required*.
- **No dependency on a paid managed cloud service or a VPS** for core functionality.
- All asynchronous, scheduled, and retry work **MUST** use MySQL-backed claiming plus per-minute cron.
- `QUEUE_CONNECTION`, `CACHE_STORE`, and `SESSION_DRIVER` defaults **MUST** remain database/file/sync-friendly. A Redis driver **MAY** exist only as opt-in, never as default.
- Any PHP extension assumed **MUST** be commonly available on shared hosting. `ext-curl` (including `curl_multi_*`) is assumed and **MUST** be declared in `composer.json` and `docs/deployment/requirements.md`.
- `exec()`, `shell_exec()`, `proc_open()`, and `popen()` **MUST NOT** be used at runtime. Hostinger disables them. (Note the consequence: `php artisan storage:link` cannot be relied upon; deploys create the symlink with `ln -sfn`, as TaskConnect's `scripts/deploy.sh` already does.)

### 4.2 Development is Docker-only

PHP, Composer, Node, and npm **MUST NOT** be installed or run on the host. Everything runs through containers via the `stc` wrapper (§17).

### 4.3 Track all work on GitHub issues

Every feature request, user story, plan, task, and bug **MUST** be represented and kept current as a GitHub issue in `suporterfid/statusconnect`. Open or find the issue before starting non-trivial work; link the PR; close with a reason. Planning docs under `docs/` **MUST** stay in sync with the issue, and the issue is canonical.

### 4.4 Licence and provenance

- The repository **MUST** be MIT licensed.
- Any pattern copied from TaskConnect (also MIT, same owner) **SHOULD** carry a short comment naming the source file, e.g. `// Mirrors taskconnect app/Application/Scheduling/DueTaskClaimer.php`.
- Dependencies **MUST** be permissively licensed (MIT/BSD/Apache-2.0). Copyleft dependencies **MUST NOT** be introduced without an explicit decision recorded in `docs/`.

---

## 5. Architecture and stack decisions

| Concern | Decision | Rationale |
|---|---|---|
| Backend | Laravel 12, modular / DDD-ish layering | Same as TaskConnect; enables direct pattern reuse |
| Frontend (operator UI) | Vue 3 + TypeScript + Vite + Pinia + vue-router + vue-i18n + Tailwind v4 | Same as TaskConnect and Jotter |
| Public status page | **Server-rendered Blade**, no SPA, no build-time dependency | §11 — must be fast, cacheable, and work with JS disabled |
| Database | MySQL 8.0+ in production; SQLite `:memory:` in tests | Mirrors TaskConnect's `phpunit.xml` |
| Async model | MySQL claim leases + per-minute cron | Hard constraint §4.1 |
| Check concurrency | `curl_multi` inside a single tick | §8 — the throughput ceiling |
| Identity | Local email/password **plus** GrandpaSSOn (dual-mode, opt-in) | §15 |
| Outbound safety | DNS-pinned transport + `OutboundPolicy`, ported from TaskConnect | §9 |
| Dev environment | Docker Compose, `stc` wrapper | §17 |
| Production packaging | Multi-stage Dockerfile → `dist/statusconnect-release.zip` | §18 |
| CI | None. Local verification through Docker | Mirrors TaskConnect |

### 5.1 Layering

Mirror TaskConnect's deliberate split (not Laravel's default convention):

```text
app/
  Domain/            pure business logic, framework-free — no Eloquent, no facades
    Monitoring/        CheckOutcome, CheckKind, MonitorState, Assertion evaluation
    Incidents/         IncidentStateMachine, ConfirmationPolicy, MaintenanceWindow
    Outbound/          OutboundPolicy, UrlValidator, IpClassifier, DnsResolverInterface
    StatusPage/        UptimeCalculator, ComponentRollup
    Secrets/           SecretRedactor
    Shared/            Clock, PublicId, TenantRole
  Application/       orchestration + transaction boundaries
    Checks/            DueMonitorClaimer, CheckExecutor, StaleClaimRecovery, TickBudget
    Incidents/         IncidentService, MaintenanceWindowService
    Notifications/     NotificationDispatcher, EmailChannel, WebhookChannel, TaskConnectChannel
    Rollups/           RollupService, RetentionService
    StatusPages/       StatusPageService, StatusPageCacheWriter
    Monitors/, Members/, ApiKeys/, Audit/, Tenancy/, Secrets/, Auth/
    GrandpaSson/       token + introspection clients (ported)
  Infrastructure/
    Persistence/Eloquent/   ALL Eloquent models live here, not app/Models/
    HttpClient/             GuzzlePinnedHttpTransport, CurlMultiPinnedProbe
    Tcp/                    PinnedTcpProbe
    Dns/
  Http/
    Controllers/Api/V1/     thin controllers
    Controllers/Public/     status page (Blade)
    Resources/, Middleware/, Support/
  Console/Commands/
  Policies/
```

### 5.2 Coding rules

- Controllers **MUST NOT** contain core business logic.
- Check evaluation (assertions → outcome) **MUST** be pure and testable without HTTP or a database.
- Incident state transitions **MUST** be explicit, deterministic, and unit-tested in isolation.
- Time **MUST** come from the injected `Clock` interface so tests can freeze it. `now()`, `time()`, and `new DateTime()` **MUST NOT** appear in Domain or Application code.
- New Eloquent models **MUST** go in `app/Infrastructure/Persistence/Eloquent/`, never `app/Models/`.
- Domain and Application code **MUST** constructor-inject bound interfaces (`Clock`, `DnsResolverInterface`, `OutboundPolicy`) rather than using facades or `new`.
- Tenant scoping **MUST NOT** depend on a developer remembering to add a `where` clause. Use global scopes plus explicit isolation tests.
- Secret redaction **MUST** be centralized in `Domain/Secrets/SecretRedactor`.
- SSRF validation **MUST** be reusable by tests, the "test this monitor now" action, and production execution.
- User-facing text **MUST NOT** be hard-coded in Vue components or Blade templates.

---

## 6. Domain model

```text
Tenant
└── Environment                  (a.k.a. workspace; the isolation + status-page boundary)
    ├── Monitor                  what to check
    │   ├── MonitorAssertion     conditions that define "up"
    │   ├── CheckResult          raw per-check outcome (short retention)
    │   ├── CheckRollup          hourly/daily aggregate (long retention)
    │   └── Incident             a confirmed period of degraded/down state
    │       └── IncidentUpdate   operator-authored timeline entry
    ├── MaintenanceWindow        planned suppression of alerting + status-page banner
    ├── NotificationChannel      email / webhook / taskconnect
    ├── StatusPage               public presentation of selected monitors
    │   └── StatusPageComponent  grouping + ordering + display name
    ├── Secret                   encrypted credential referenced by monitors
    └── ApiKey
```

### 6.1 Entity definitions

- **Tenant** — top-level billing/ownership boundary. Owns members and API keys.
- **Environment (workspace)** — isolation boundary for monitors, incidents, and status pages. A tenant **MUST** have at least one. Cross-environment reads **MUST** be impossible.
- **Monitor** — a single thing to check: kind, target, interval, timeout, assertions, notification wiring, and current state. Owns its claim lease.
- **MonitorAssertion** — one condition evaluated against a check outcome (§7.3). All assertions on a monitor **MUST** pass for the check to be `up`.
- **CheckResult** — the outcome of one execution: state, latency, status code, redacted failure reason. High volume, short retention.
- **CheckRollup** — aggregate over a bucket (hour or day) per monitor: counts by state, latency percentiles, downtime seconds. Long retention; the status page and uptime percentages read **only** from rollups plus the current partial bucket.
- **Incident** — a confirmed degraded/down period, with `started_at`, `confirmed_at`, `resolved_at`, severity, and cause summary. Created by the state machine (§10); **MAY** also be created manually by an operator.
- **IncidentUpdate** — an operator-authored, publicly visible timeline entry.
- **MaintenanceWindow** — a scheduled period during which named monitors do not alert and the status page shows a maintenance banner.
- **NotificationChannel** — a delivery target: `email`, `webhook`, or `taskconnect` (§16.3).
- **StatusPage** — a public presentation: slug, custom domain (optional), theme, visibility, and selected components.
- **StatusPageComponent** — display grouping mapping one or more monitors to a public-facing name. The public name **MUST** be independent of the monitor's internal target.

### 6.2 Identifier strategy

Mirror TaskConnect's `app/Domain/Shared/PublicId.php`:

- Every table **MUST** have an auto-increment `id` (internal, never exposed) and a unique `public_id` string (ULID, optionally prefixed) used in all API responses and URLs.
- Prefixes: `ten_`, `env_`, `mon_`, `inc_`, `chn_`, `page_`, `mw_`, `sec_`, `key_`.
- Public status page URLs **MUST NOT** expose `public_id` for monitors; they expose the status page slug and component slugs only.

---

## 7. Monitor types and check semantics

### 7.1 Supported kinds (v0)

| Kind | Target | Definition of success |
|---|---|---|
| `http` | URL | Assertions on status code, latency, body, headers |
| `tcp` | host:port | TCP connect completes within timeout |
| `heartbeat` | inbound token URL | A ping was received within the grace period |

`keyword` is **not** a separate kind — it is a `body_contains` assertion on an `http` monitor (§7.3).

### 7.2 On ICMP ping

An `icmp` kind **MUST NOT** be implemented. Raw ICMP sockets require `CAP_NET_RAW` or root, and shelling out to `/bin/ping` requires `exec()`, which is forbidden by §4.1 and disabled on Hostinger. Users wanting host-level reachability **MUST** use a `tcp` monitor against a known-open port. `docs/` **MUST** state this explicitly so it is not repeatedly re-proposed.

### 7.3 Assertions

Each `http` monitor has one or more assertions. All **MUST** pass for `up`.

| Assertion | Operators | Notes |
|---|---|---|
| `status_code` | `equals`, `in`, `between`, `lt`, `gte` | Default when none specified: `between 200 299` |
| `latency_ms` | `lt`, `lte` | Exceeding produces `degraded`, not `down` (§7.6) |
| `body_contains` | `contains`, `not_contains` | Case-sensitivity configurable; evaluated on the truncated body |
| `body_matches` | `regex` | §7.7 — bounded execution |
| `header` | `equals`, `contains`, `exists` | Named header, case-insensitive name |
| `json_path` | `equals`, `contains`, `exists` | Dot-path only (`data.status`); no JSONPath expression language |
| `tls_expires_in_days` | `gte` | HTTPS only; derived from the handshake (§7.8) |

Assertion evaluation **MUST** be a pure function `(CheckOutcome, Assertion[]) → CheckState + reason` in `Domain/Monitoring/`, unit-tested with no I/O.

### 7.4 Response body handling

- The response body **MUST** be truncated at `OUTBOUND_RESPONSE_BODY_LIMIT` (default 65536 bytes) during transfer, not after.
- The full body **MUST NOT** be persisted. Only a bounded excerpt (default 512 bytes, `CHECK_FAILURE_EXCERPT_BYTES`) is stored, and only on a failing check.
- The stored excerpt **MUST** pass through `SecretRedactor` before persistence (§9.4).

### 7.5 Timeouts

- Per-monitor `timeout_ms`, default 10000, **MUST** be capped by `CHECK_MAX_TIMEOUT_MS` (default 30000). A tick budget of ~45s cannot accommodate an unbounded per-monitor timeout.
- Connect timeout defaults to `OUTBOUND_CONNECT_TIMEOUT` (5s) and **MUST** be ≤ the total timeout.

### 7.6 Check states

A single check produces exactly one of:

| State | Meaning |
|---|---|
| `up` | All assertions passed |
| `degraded` | Reachable, but a latency assertion failed while all correctness assertions passed |
| `down` | An assertion failed, a transport error occurred, or the timeout elapsed |
| `blocked` | The outbound policy refused the request (§9). **Never** reported as `down` |
| `skipped` | Inside a maintenance window, or the monitor was paused mid-tick |

`blocked` **MUST** be surfaced distinctly in the operator UI as a configuration problem, and **MUST NOT** appear on the public status page or count against uptime — a policy refusal is not target downtime.

### 7.7 Regex safety

`body_matches` is the only user-supplied-pattern surface and is a ReDoS risk on a shared host.

- Patterns **MUST** be validated at write time (`preg_match` against a short probe string with error checking) and rejected on compile failure.
- `pcre.backtrack_limit` and `pcre.jit` behaviour **MUST NOT** be assumed; the executor **MUST** check `preg_last_error()` after every match and treat `PREG_BACKTRACK_LIMIT_ERROR` as an assertion failure with reason `regex_limit_exceeded`, never as a fatal error.
- Pattern length **MUST** be capped (default 500 characters).

### 7.8 TLS certificate expiry

- For HTTPS monitors, the executor **SHOULD** capture the peer certificate's `notAfter` from the completed handshake and store `tls_expires_at` on the monitor.
- A `tls_expires_in_days` assertion failing **MUST** produce `degraded`, not `down` — a soon-to-expire certificate is a warning, not an outage.
- Certificate capture **MUST NOT** require a second connection.

### 7.9 Heartbeat (dead-man's switch) monitors

A `heartbeat` monitor inverts the direction: the monitored system calls StatusConnect.

- Each heartbeat monitor exposes `POST|GET /ping/{token}` on the **public** (unauthenticated) surface.
- `token` **MUST** be a high-entropy random string (≥128 bits), distinct from `public_id`, and regenerable.
- Receiving a ping records `last_ping_at` and **MUST** be cheap: a single indexed `UPDATE`, no check row, no synchronous notification.
- The scheduler evaluates heartbeat monitors on each tick: if `now - last_ping_at > interval + grace_seconds`, the check state is `down`.
- The ping endpoint **MUST** be rate-limited per token and **MUST** return `204 No Content` on success with no body.
- This is the integration point for TaskConnect's cron (§16.2).

---

## 8. The scheduler — claiming, budget, and parallel execution

This is the core loop and the primary point of reuse from TaskConnect.

### 8.1 Cron entry points

Three artisan commands, mirroring TaskConnect's `scheduler:*`:

| Command | Cadence | Responsibility |
|---|---|---|
| `monitor:check-due` | every minute | Claim due monitors, execute checks, evaluate incidents, dispatch notifications |
| `monitor:rollup` | every minute | Fold closed raw buckets into `check_rollups`; refresh status-page caches |
| `monitor:maintenance` | hourly | Recover stale claims, apply retention, prune rollups, open/close maintenance windows |

Production cron (mirrors `docs/deployment/cron.md` in TaskConnect):

```cron
* * * * * /opt/alt/php83/usr/bin/php /home/account/app/artisan monitor:check-due >/dev/null 2>&1
* * * * * /opt/alt/php83/usr/bin/php /home/account/app/artisan monitor:rollup >/dev/null 2>&1
23 * * * * /opt/alt/php83/usr/bin/php /home/account/app/artisan monitor:maintenance >/dev/null 2>&1
```

Docs **MUST** warn that the default `php` on Hostinger is often 7.4 and that the CLI PHP version is configured separately from the website's PHP version in hPanel.

### 8.2 Claim leases

`Application/Checks/DueMonitorClaimer` **MUST** mirror `taskconnect app/Application/Scheduling/DueTaskClaimer.php`:

- Selection inside `DB::transaction`, using `FOR UPDATE SKIP LOCKED` on MySQL and `lockForUpdate()` on SQLite (driver-detected, exactly as TaskConnect does — the test suite runs on SQLite).
- Candidate predicate: `enabled = true AND next_check_at <= now AND (claim_token IS NULL OR claim_expires_at < now)`.
- Claiming writes `claim_token` (UUID), `claimed_at`, and `claim_expires_at = now + CHECK_CLAIM_TTL_MINUTES` (default 5) via a **conditional `UPDATE` re-asserting the same predicate**; a zero-row result means another tick won the race and the monitor **MUST** be skipped.
- `next_check_at` **MUST** be advanced at claim time, not at completion time, so a crashed tick cannot cause a hot loop.
- Overlapping ticks **MUST** be safe. Two concurrent cron invocations **MUST NOT** double-check a monitor within one interval.
- `StaleClaimRecovery` in `monitor:maintenance` **MUST** release leases whose `claim_expires_at` has passed.

### 8.3 Interval drift

- `next_check_at` **MUST** be computed as `max(now, previous_next_check_at + interval_seconds)` so a late cron does not permanently shift a monitor's phase, while a very late cron does not queue a burst of missed checks.
- Missed occurrences **MUST NOT** be backfilled. An uptime monitor reports what it observed; fabricating checks for a window in which it was not running is a correctness violation. Gaps **MUST** be represented explicitly as `no_data` in rollups and rendered as a distinct (not "up", not "down") state on the status page.

### 8.4 Tick budget

Mirror `taskconnect app/Application/Scheduling/TickBudget.php`:

- A tick **MUST** stop claiming new work at `CHECK_TARGET_DURATION_SECONDS` (default 45).
- The budget **MUST** additionally be capped by PHP `max_execution_time - CHECK_BUDGET_SAFETY_MARGIN_SECONDS` (default margin 5).
- Work **MUST** be claimed in waves (§8.5), not in one large batch, so a budget stop does not strand a large set of leases.
- The command **MUST** exit cleanly on budget exhaustion and record the stop in the heartbeat, never by hitting the PHP time limit.

### 8.5 Parallel execution — the defining design decision

**This is where StatusConnect diverges from TaskConnect.** TaskConnect delivers sequentially; that is correct for at-least-once webhook delivery with per-task idempotency, but fatal here.

Sequential arithmetic: a 45-second budget with a 10-second timeout yields a worst case of **4 checks per tick**. That is not a product.

Therefore:

- The executor **MUST** perform HTTP checks concurrently using `curl_multi_*` (`ext-curl`, universally available on shared hosting, no daemon, no extra dependency).
- Concurrency **MUST** be bounded by `CHECK_CONCURRENCY` (default 10, cap 50). Shared hosts enforce process and connection limits; unbounded concurrency will get the account throttled or suspended.
- The executor **MUST** process monitors in waves: claim up to `CHECK_WAVE_SIZE` (default = `CHECK_CONCURRENCY`), run the wave to completion, persist results, then check the budget before claiming the next wave.
- Every handle in the multi stack **MUST** carry the same SSRF protections as the sequential path (§9.2) — pinning is set per-handle via `CURLOPT_RESOLVE`.
- TCP checks **MUST** use non-blocking `stream_socket_client()` with `STREAM_CLIENT_ASYNC_CONNECT` plus `stream_select()`, so they are batched in the same wave model rather than serialized.
- Heartbeat evaluation is a pure database comparison and **MUST NOT** consume a concurrency slot.

Resulting capacity target (§14.1): **300 monitors at 60-second intervals**, or roughly 1,500 at 5-minute intervals, on a single shared-hosting account.

### 8.6 Heartbeat of the scheduler itself

`HeartbeatWriter` **MUST** record `checker_last_seen_at` and `rollup_last_seen_at` on every tick. The operator dashboard **MUST** show a prominent warning when a heartbeat is older than a few minutes — a silent monitoring system is worse than none.

**The installation's own status page SHOULD include a built-in "Monitoring system" component fed by this heartbeat**, so that a dead cron is visible on the public page rather than being indistinguishable from "everything is fine".

---

## 9. Outbound security

Port `taskconnect app/Domain/Execution/Outbound/` (`OutboundPolicy`, `UrlValidator`, `IpClassifier`, `DnsResolverInterface`) and `app/Infrastructure/HttpClient/` essentially unchanged, then apply the differences in §9.3.

### 9.1 SSRF requirements

- Only `http` and `https` schemes are permitted; plain HTTP is refused unless `OUTBOUND_ALLOW_HTTP` is explicitly enabled.
- URLs with embedded credentials **MUST** be rejected.
- Localhost hostnames and cloud-metadata hostnames/IPs (`169.254.169.254`, `100.100.100.200`, `fd00:ec2::254`, `metadata.google.internal`, …) **MUST** be blocked.
- Destination ports **MUST** be restricted to `OUTBOUND_ALLOWED_PORTS` for HTTP monitors (default `80,443`).
- Resolved IPs **MUST** be classified and private/loopback/link-local/multicast/reserved ranges refused by default, for both IPv4 and IPv6.
- Policy refusal **MUST** produce check state `blocked` with a machine-readable reason code, never a fake `down`.

### 9.2 DNS pinning

- The hostname **MUST** be resolved once, the resulting IP classified, and the connection made to **that exact IP** via `CURLOPT_RESOLVE`, to defeat DNS rebinding between validation and connection.
- Redirects **MUST** be followed manually with `allow_redirects => false`, re-validating and re-pinning each hop, bounded by `OUTBOUND_REDIRECT_LIMIT`.
- The pinned transport **MUST NOT** be bypassed for any user-supplied URL, including the interactive "test this monitor" action.

### 9.3 Differences from TaskConnect — the private-target problem

TaskConnect delivers to endpoints an operator has deliberately configured; a hard private-IP block is a pure win. StatusConnect has a legitimate conflicting use case: a self-hoster monitoring `192.168.1.10:8080` on their own LAN.

Resolution:

- The default **MUST** remain deny. A fresh installation **MUST NOT** be able to scan its own host's private network.
- Private targets are enabled **per tenant**, opt-in, by a **platform administrator only** — never self-service by a tenant member. Mirror TaskConnect's `tenant_outbound_allow_hosts` migration.
- The allowlist is by **host**, not by CIDR, and each entry is still pinned and re-validated on every check.
- Enabling it **MUST** write an audit log entry.
- Monitors resolving to private addresses **MUST NOT** be publishable to a public status page unless the operator sets an explicit per-monitor `public_safe` flag, and the status page **MUST** render only the component display name, never the target (§11.4).
- A dedicated egress profile `monitor` **SHOULD** exist in `config/outbound.php` alongside TaskConnect's `internal` / `public-crawl` / `api`, carrying monitor-appropriate timeouts and body limits.

### 9.4 Secrets and redaction

- Monitor request headers **MAY** reference encrypted secrets (e.g. a bearer token for an authenticated health endpoint). Storage mirrors TaskConnect's `Application/Secrets/SecretService`, encrypted with `APP_KEY`.
- Stored failure excerpts, stored response headers, and notification payloads **MUST** pass through `SecretRedactor`.
- Hop-by-hop and dangerous headers **MUST** be filtered by a `HeaderPolicy` on the way out.
- The public status page **MUST NEVER** render a failure excerpt, a target URL, a request header, or an IP address. Public failure reasons are limited to a fixed vocabulary (§11.4).
- Losing `APP_KEY` makes stored secrets unrecoverable; `docs/deployment/backup.md` **MUST** say so.

---

## 10. Incidents — the state machine

Pure, deterministic, and unit-tested without I/O, in `Domain/Incidents/`.

### 10.1 Monitor states

```text
pending ──first check──▶ up
   up ──consecutive failures ≥ confirmation_threshold──▶ down
   up ──latency assertion fails──▶ degraded
degraded ──correctness assertion fails (confirmed)──▶ down
degraded ──consecutive successes ≥ recovery_threshold──▶ up
 down ──consecutive successes ≥ recovery_threshold──▶ up
  any ──operator pauses──▶ paused
  any ──inside maintenance window──▶ maintenance
```

### 10.2 Confirmation and recovery

- `confirmation_threshold` (default 3) consecutive non-`up` checks **MUST** be required before a monitor is declared `down` and an incident opened. A single blip **MUST NOT** page anyone.
- `recovery_threshold` (default 2) consecutive `up` checks **MUST** be required before resolving.
- Both **MUST** be configurable per monitor.
- The counters **MUST** be persisted on the monitor row (`consecutive_failures`, `consecutive_successes`), because a tick is a fresh process with no memory.
- A `blocked` check **MUST NOT** increment the failure counter — it is a configuration fault, not target downtime. It **MUST** instead raise a distinct operator-facing alert.

### 10.3 Incident lifecycle

- Opening an incident **MUST** be idempotent: at most one `open` incident per monitor at any time, enforced by a **partial-equivalent unique constraint** (a `unique(monitor_id, resolved_flag)` column pattern, since MySQL 8.0 lacks partial indexes) — not by application-level checking alone.
- `started_at` **MUST** be the timestamp of the **first** failing check in the confirmed streak, not the moment confirmation was reached. Otherwise reported downtime is systematically understated by `confirmation_threshold × interval`.
- `confirmed_at` records when the threshold was met, and is what gates notification.
- Resolving sets `resolved_at`, computes `duration_seconds`, and dispatches a recovery notification.
- Severity is derived: `down` → `major`, `degraded` → `minor`. Operators **MAY** override.
- Operators **MAY** open a manual incident not tied to any monitor (for a dependency outage), and **MAY** post `IncidentUpdate` entries. Manual incidents **MUST NOT** be auto-resolved.

### 10.4 Maintenance windows

- A window has a time range, a set of monitors, and a public title.
- During a window, checks **MUST** still execute and be recorded (observability is retained) but with state `skipped`, alerting suppressed, and rollups excluding the period from uptime denominators.
- The status page **MUST** show an active or upcoming window as a banner.
- Windows **MAY** recur weekly. Cron-style recurrence is deferred beyond v0.

### 10.5 Flap detection

If a monitor opens and resolves more than `FLAP_THRESHOLD` (default 5) incidents within `FLAP_WINDOW_MINUTES` (default 60), it **MUST** be marked `flapping`. While flapping, notifications **MUST** be throttled to at most one per `FLAP_WINDOW_MINUTES`, and the operator UI **MUST** surface the condition. The status page continues to show real state.

---

## 11. The public status page

The only unauthenticated, potentially high-traffic surface. On shared hosting, an uncached status page is a self-inflicted denial of service — the moment an outage brings traffic, the page must not add load to the same database the checker needs.

### 11.1 Requirements

- Rendered **server-side with Blade**. It **MUST NOT** require the Vue SPA bundle, and **MUST** be fully readable with JavaScript disabled.
- Reachable at `/status/{slug}` by default, and **MAY** be mapped to a custom domain or the document root via configuration.
- Visibility per page: `public`, `unlisted` (obscure slug, `noindex`), or `private` (requires authentication).
- It **MUST** render: overall banner, components with current state, uptime percentage over 24h/7d/30d/90d, a 90-day daily history strip, active maintenance banners, and an incident timeline with updates.
- It **MUST** be internationalized (`en`, `pt-BR`) and **MUST** respect a per-page configured display timezone.
- It **MUST** meet WCAG 2.2 AA: state **MUST NOT** be conveyed by colour alone — every state carries a shape/icon and a text label.
- It **MUST** expose `GET /status/{slug}.json` with the same data, for embedding and badges.
- It **SHOULD** expose an RSS/Atom feed of incidents.

### 11.2 Caching (mandatory)

- Page payloads **MUST** be pre-rendered into a `status_page_cache` row (or a file under `storage/`) by `monitor:rollup`, and the public controller **MUST** serve that snapshot.
- The public request path **MUST** execute a bounded, small number of queries — target: **one** primary read. It **MUST NOT** aggregate `check_results` at request time.
- Responses **MUST** send `Cache-Control: public, max-age=60` and a strong `ETag`, and **MUST** honour `If-None-Match` with `304`.
- Cache regeneration **MUST** be incremental — only pages whose monitors changed state or whose partial bucket rolled over.
- When an incident opens or resolves, the affected page's cache **MUST** be regenerated in that same tick, so an outage is visible within one minute rather than after the next scheduled rebuild.

### 11.3 Uptime calculation

- Uptime percentage **MUST** be computed from `check_rollups` plus the current partial bucket, never from raw `check_results`.
- Denominator **MUST** exclude maintenance windows and `no_data` gaps. The page **MUST** disclose when a period contains gaps rather than silently counting them as up.
- `degraded` time **MUST** be counted separately and **MUST NOT** be reported as downtime by default. The formula shown to end users **MUST** be documented on the page or in a tooltip.
- `blocked` checks **MUST** be excluded from both numerator and denominator.

### 11.4 Information disclosure (hard requirement)

The public page **MUST NOT** expose:

- monitor target URLs, hostnames, IP addresses, or ports;
- request or response headers, or any body excerpt;
- internal identifiers (`public_id` of monitors, environments, or tenants);
- tenant names, member names, or email addresses;
- stack traces, SQL errors, or framework debug output.

Public failure reasons **MUST** be drawn from a fixed, translatable vocabulary: `Connection failed`, `Timed out`, `Unexpected response`, `Certificate problem`, `Under maintenance`, `No data`. A test **MUST** assert that a status page response body contains none of the monitor's configured target string, in both HTML and JSON forms.

### 11.5 Abuse resistance

- The public page and the `/ping/{token}` endpoint **MUST** be rate-limited by IP, using the MySQL-backed fixed-window bucket pattern from TaskConnect's `EnforceSubmitRateLimit` — not a Redis limiter.
- An unknown or malformed status page slug **MUST** return a generic `404` in constant time, without disclosing whether the slug exists but is private.
- An unknown heartbeat token **MUST** return `404` and **MUST NOT** be distinguishable in timing from a known one.

---

## 12. Notifications

### 12.1 Requirements

- Notifications **MUST** be dispatched from the same tick that confirms or resolves an incident, within the tick budget.
- Delivery **MUST NOT** block incident recording. A channel failure **MUST** be recorded and retried, never allowed to lose the incident.
- Every notification **MUST** be deduplicated by `(incident_id, channel_id, event)` so overlapping ticks cannot double-send. Enforce with a unique constraint.
- Notification payloads **MUST** pass through `SecretRedactor`.
- Users **MUST** be able to receive a test notification per channel from the UI.

### 12.2 Channels (v0)

| Channel | Transport | Notes |
|---|---|---|
| `email` | Laravel mailer over the host's SMTP | Default. Mailpit in dev |
| `webhook` | Pinned outbound POST | Full SSRF policy applies; HMAC-signed |
| `taskconnect` | TaskConnect submission API | §16.3 — delegates retry/DLQ |

Chat integrations (Slack, Discord, Telegram) are `webhook` with a payload template, and **SHOULD** ship as templates rather than as distinct channel types.

### 12.3 Shared-hosting mail reality

Shared hosts impose strict hourly outbound mail limits (commonly 100–500/hour) and silently drop excess.

- A per-tenant and per-installation mail rate cap **MUST** exist (`NOTIFY_MAIL_MAX_PER_HOUR`, default 100), enforced with the same MySQL bucket pattern.
- On exceeding the cap, notifications **MUST** be coalesced into a digest rather than dropped, and the operator UI **MUST** show that coalescing occurred.
- `docs/deployment/` **MUST** document configuring an external SMTP relay as the recommended production setup, and **MUST NOT** assume the host's `mail()` is reliable.

### 12.4 Webhook signing

Mirror `taskconnect app/Domain/Auth/CallbackHmac.php`: an `X-StatusConnect-Signature` header carrying an HMAC-SHA256 over timestamp and raw body, with a documented verification recipe and a bounded acceptable clock skew (default 300s).

---

## 13. Data model, rollups, and retention

### 13.1 Volume is the constraint

One monitor at 60s produces **1,440 rows/day**, ~43,800/month. Fifty monitors produce ~2.2M rows/month. Shared-hosting MySQL quotas (often 1–3 GB, sometimes with row or inode limits) make unbounded raw retention an immediate outage. Rollups are therefore **not an optimization; they are a functional requirement.**

### 13.2 Core tables

Mirroring TaskConnect's `database/migrations/…phase0_core_tables.php` conventions (`id` + `public_id`, `timestamps`, `archived_at`):

```text
users, tenants, tenant_memberships, environments, user_preferences, audit_logs
api_keys, secrets
monitors, monitor_assertions
check_results, check_rollups
incidents, incident_updates
maintenance_windows, maintenance_window_monitors
notification_channels, notification_deliveries
status_pages, status_page_components, status_page_component_monitors, status_page_cache
rate_limit_buckets, system_heartbeats, idempotency_keys
```

### 13.3 `monitors` — required columns

`id`, `public_id`, `tenant_id`, `environment_id`, `name`, `kind`, `target`, `http_method`, `request_headers_json`, `request_body`, `interval_seconds`, `timeout_ms`, `confirmation_threshold`, `recovery_threshold`, `follow_redirects`, `verify_tls`, `egress_profile`, `public_safe`, `enabled`, `paused_at`, `current_state`, `consecutive_failures`, `consecutive_successes`, `last_checked_at`, `next_check_at`, `last_latency_ms`, `tls_expires_at`, `heartbeat_token`, `heartbeat_grace_seconds`, `last_ping_at`, `flapping_since`, `claim_token`, `claimed_at`, `claim_expires_at`, timestamps.

### 13.4 Required indexes

The claim query runs every 60 seconds forever and is the hottest query in the system.

- `monitors (enabled, next_check_at)` — **MUST** exist; the claim predicate depends on it.
- `monitors (claim_expires_at)` — stale recovery.
- `monitors (environment_id, current_state)` — dashboards.
- `monitors (heartbeat_token)` unique — ping lookup.
- `check_results (monitor_id, checked_at)` — the primary read path; **MUST** be present before any load testing.
- `check_rollups (monitor_id, bucket_start, bucket_kind)` unique — idempotent rollup writes.
- `incidents (monitor_id, resolved_flag)` unique — enforces one open incident (§10.3).
- `incidents (environment_id, started_at)` — timeline.
- `notification_deliveries (incident_id, channel_id, event)` unique — dedup (§12.1).

### 13.5 Rollup strategy

- `monitor:rollup` **MUST** fold closed hourly buckets into `check_rollups` with `bucket_kind = hour`, and closed days into `bucket_kind = day`.
- A rollup row records: counts of `up`/`degraded`/`down`/`blocked`/`skipped`/`no_data`, `downtime_seconds`, `checks_total`, and latency `min`/`avg`/`p50`/`p95`/`max`.
- Rollup writes **MUST** be idempotent (upsert on the unique key), so a re-run or an overlapping tick cannot double-count.
- A bucket **MUST NOT** be rolled up until it is closed, to avoid partially-aggregated data becoming permanent.
- Percentiles from bucketed data are approximations; the UI **MUST NOT** present p95-of-daily-rollup as an exact figure, and the docs **MUST** state the approximation.

### 13.6 Retention defaults

| Data | Env var | Default |
|---|---|---|
| Raw `check_results` | `RETENTION_CHECK_RESULTS_DAYS` | 7 |
| Failing-check excerpts | `RETENTION_CHECK_EXCERPTS_DAYS` | 7 |
| Hourly rollups | `RETENTION_ROLLUP_HOUR_DAYS` | 90 |
| Daily rollups | `RETENTION_ROLLUP_DAY_DAYS` | 730 |
| Resolved incidents | `RETENTION_INCIDENTS_DAYS` | 730 |
| Audit logs | `RETENTION_AUDIT_LOGS_DAYS` | 365 |
| Notification deliveries | `RETENTION_NOTIFICATIONS_DAYS` | 90 |
| Heartbeats | `RETENTION_SYSTEM_HEARTBEAT_DAYS` | 30 |

- Raw results **MUST NOT** be deleted before the covering rollup exists. Deletion is gated on rollup completion, not purely on age.
- Deletion **MUST** be chunked (`DELETE … LIMIT`) with a per-tick cap, so retention never produces a long-running lock on a shared host.

---

## 14. Performance and capacity

### 14.1 Target workload (single shared-hosting account)

| Metric | Target |
|---|---|
| Monitors at 60s interval | 300 |
| Monitors at 300s interval | 1,500 |
| Concurrent checks per wave | 10 (default), 50 (max) |
| Tick wall-clock | ≤ 45s claiming, ≤ 55s total |
| Public status page | 1 primary query, ≤ 50 ms server time |
| Tenants per installation | 50 |

These are the numbers the acceptance checklist verifies. They are targets, not guarantees; the docs **MUST** be explicit that shared hosts vary and that some throttle cron below a true one-per-minute floor.

### 14.2 Backpressure

- When claimable work exceeds what the budget allows, the checker **MUST** prioritize by `next_check_at` ascending (most overdue first), so no monitor is starved.
- Sustained overload **MUST** raise a visible operator warning ("checks are falling behind") rather than silently degrading interval accuracy.
- The dashboard **MUST** show observed-vs-configured interval per monitor, so drift is discoverable.

---

## 15. GrandpaSSOn integration (identity)

StatusConnect **MUST** ship with the GrandpaSSOn seam present and working from v0, defaulted off — mirroring TaskConnect's dual-mode design in `config/grandpasson.php`.

> The contract below was verified against the broker's actual routing table (`grandpasson app/Http/AppRoutes.php`) and controllers, not against prose. Several published assumptions do not survive contact with the code; §15.7 lists the traps.

### 15.1 Dual-mode principle

Local email/password authentication **MUST** continue to work with GrandpaSSOn disabled, and **MUST** remain the default. Both modes are independently gated:

```php
// config/grandpasson.php
'outbound_enabled' => filter_var(env('GRANDPASSON_OUTBOUND_ENABLED', false), FILTER_VALIDATE_BOOL),
'inbound_enabled'  => filter_var(env('GRANDPASSON_INBOUND_ENABLED', false), FILTER_VALIDATE_BOOL),
'base_url'         => env('GRANDPASSON_BASE_URL', ''),

// RP client (browser login) — an `oauth_clients` row, MUST be confidential
'rp_client_id'     => env('GRANDPASSON_RP_CLIENT_ID', ''),
'rp_client_secret' => env('GRANDPASSON_RP_CLIENT_SECRET', ''),
'redirect_uri'     => env('GRANDPASSON_REDIRECT_URI', ''),

// Service client (machine tokens + introspection) — a `service_clients` row
'client_id'        => env('GRANDPASSON_CLIENT_ID', ''),
'client_secret'    => env('GRANDPASSON_CLIENT_SECRET', ''),

'login_url'        => env('GRANDPASSON_LOGIN_URL', '{base_url}/login'),
'exchange_url'     => env('GRANDPASSON_EXCHANGE_URL', '{base_url}/session/exchange'),
'token_url'        => env('GRANDPASSON_TOKEN_URL', '{base_url}/oauth/token'),
'introspect_url'   => env('GRANDPASSON_INTROSPECT_URL', '{base_url}/oauth/introspect'),

'read_scope'       => env('GRANDPASSON_READ_SCOPE', 'status:read'),
'write_scope'      => env('GRANDPASSON_WRITE_SCOPE', 'status:write'),
'callback_scope'   => env('GRANDPASSON_CALLBACK_SCOPE', 'status:callback'),
'token_refresh_skew_seconds'  => (int) env('GRANDPASSON_TOKEN_REFRESH_SKEW_SECONDS', 60),
'introspection_cache_seconds' => (int) env('GRANDPASSON_INTROSPECTION_CACHE_SECONDS', 30),
'callback_hmac_secret'        => env('SC_CALLBACK_HMAC_SECRET', ''),
'callback_max_skew_seconds'   => (int) env('SC_CALLBACK_MAX_SKEW_SECONDS', 300),
```

Scope names **MUST** stay configurable rather than hardcoded (as TaskConnect does), and URL keys **MUST** derive from `base_url` while remaining individually overridable.

### 15.2 Two distinct client registrations

The broker keeps **two separate client tables**, and a client in one cannot act as the other. StatusConnect needs **both**:

| Need | Table | Created with |
|---|---|---|
| Browser login (RP code flow) | `oauth_clients` | `php cron/seed_oauth_client.php --client-id=… --redirect-uri=… --secret=…` |
| Machine tokens + introspection | `service_clients` | `php cron/admin.php client:create-service "StatusConnect" --scopes=… --aud=…` |

An RP client **MUST NOT** be used to call `/oauth/introspect` (it will fail `invalid_client`), and a service client cannot perform browser login. `docs/integrations/grandpasson.md` **MUST** show both commands.

### 15.3 Inbound A — delegated browser login

An `IdentityProvider` seam, structurally copied from `jotter app/Domain/Auth/Contracts/IdentityProvider.php`: a `LocalIdentityProvider` (default) and a `GrandpaSsonIdentityProvider` that **composes** the local one and delegates everything except identity resolution. The interface **SHOULD** cover identity *and* coarse authorization (`accessibleTenantIds()` / `accessibleWorkspaceIds()` returning `null` for "unrestricted"), so no caller ever branches on auth mode.

**StatusConnect MUST implement the real HTTP code flow, not Jotter's shortcut** (§15.7.1):

1. Send the operator to
   `GET {base_url}/login/{provider}` (or `/login/email`) with `client_id`, `redirect_uri`, `state`.
   `{provider}` is restricted to `google` | `microsoft` | `github`. `redirect_uri` **MUST** match the registered value exactly.
2. The broker returns to `redirect_uri` with `?code=…&state=…`.
3. `state` **MUST** be verified against the value stored in the session before use.
4. Redeem **server-side and immediately** at `POST {base_url}/session/exchange`, form-encoded, with `code`, `client_id`, `client_secret`, `redirect_uri`, and optionally `tenant`.
   **Broker auth codes expire in 60 seconds and are single-use** — the redemption **MUST NOT** be deferred to a later request or a queued job.
5. The response carries identity *and* tenancy:
   ```json
   { "subject": {"id":"…","email":"…","name":"…","idp":"google"},
     "tenant":  {"id":"…","slug":"acme","role":"admin"},
     "tenants": [ … ], "groups": ["editors"], "scopes": ["openid","profile","email","tenant:read"] }
   ```
6. Provision or link a local user row keyed on `subject.email`, and map `tenant`/`tenants`/`groups` onto StatusConnect tenancy.

Additional requirements:

- The exchange **MUST** use a confidential RP client. The broker unconditionally requires `client_secret` on this endpoint and rejects public/PKCE clients.
- `tenant.role` is `owner` | `admin` | `member`. `groups` are **opaque tenant-scoped slugs**; the broker owns no per-workspace RBAC (its stated non-goal). Mapping group → StatusConnect role is StatusConnect's job and **MUST** be explicit and configurable.
- Platform-admin status **MUST** be a local decision on the mirrored user row, never taken from a broker claim — mirroring Jotter's escalation test.
- The whole path **MUST** fail closed: any broker error, timeout, or malformed response yields "not authenticated", never a partially-trusted session.

### 15.4 Inbound B — machine tokens

Machine callers present a GrandpaSSOn opaque token (`gpat_live_…`). StatusConnect validates it at `POST {base_url}/oauth/introspect` and builds a `GrandpaSsonActor`, mirroring TaskConnect's two-middleware chain:

1. **`AuthenticateApiKeyOrSanctum`** — order: already-authenticated → native `Bearer sc_*` API key → *(only if `inbound_enabled`)* introspect the bearer → Sanctum/web guards → `401`. The actor **MUST** implement `Authenticatable` with the **SHA-256 fingerprint of the token as its identifier**, never the raw token, so it is safe to log.
2. **`EnforceGrandpaSsonWorkspaceAud`** — no-ops when the flag is off *or* the actor is not a `GrandpaSsonActor`, so API-key and SPA sessions are untouched. Otherwise it **MUST** require `active` **and** the required scope (`status:read` for reads, `status:write` for writes) **and** `aud` covering the current environment's `public_id`.

`audienceIncludes()` **MUST** accept both the raw id (`env_…`) and the prefixed form (`workspace/env_…`) — the broker's documentation uses the prefixed style and operators routinely configure one when the other is expected.

A denial **MUST** write an audit entry with the reason, required scope, presented scopes, presented audiences, and the token fingerprint — never the token.

**Introspection caching is mandatory here, unlike in TaskConnect.** The broker rate-limits `/oauth/token` and `/oauth/introspect` to **60 requests per minute per IP**. TaskConnect does not cache introspection; StatusConnect, whose whole purpose is per-minute activity, would exhaust that budget. Results **MUST** be cached, keyed on the token fingerprint, for `introspection_cache_seconds` (default 30) bounded by the token's own `exp`. Docs **MUST** state the resulting revocation latency, since caching trades away the broker's authoritative-revocation property.

### 15.5 Outbound — machine tokens for calling other services

Mirror `CachedTokenClient` decorating `HttpTokenClient`:

- `POST {base_url}/oauth/token`, form-encoded, `grant_type=client_credentials`, `client_id`, `client_secret`, `scope`.
- Response: `access_token`, `token_type`, `expires_in`, `scope`, `aud`.
- Cache **per scope** (key `sha256(scope)`), storing `access_token` + `expires_at`; refresh when `now >= expires_at - token_refresh_skew_seconds`; write with `ttl = max(30, expires_at - now - skew)`.
- Default TTL is 900s, capped at 3600s by the broker.
- Tokens **MUST NOT** be logged and **MUST** be redacted from any snapshot or notification payload.

When signing outbound callbacks, mirror `taskconnect app/Domain/Auth/CallbackHmac.php` — bearer token *and* HMAC together: the bearer proves the caller is the registered service client, the HMAC binds the exact body inside a bounded skew window. Signature is `hash_hmac('sha256', "{$timestamp}.{$nonce}.{$rawBody}", $secret)`, verified with `hash_equals` and rejected beyond `callback_max_skew_seconds`.

### 15.6 Broker-side provisioning

```bash
# 1. RP client for browser login (confidential; exact redirect URI)
php cron/seed_oauth_client.php \
  --client-id=statusconnect \
  --name="StatusConnect" \
  --redirect-uri=https://status.example.com/auth/grandpasson/callback \
  --secret='<long-random>'

# 2. Service client for machine tokens + introspection, pinned to a workspace
php cron/admin.php client:create-service "StatusConnect" \
  --scopes=status:read,status:write,status:callback \
  --aud=workspace/env_…
```

Then set the `GRANDPASSON_*` values in StatusConnect and flip the enable flags.

`--aud` is a **fixed pin, not a default**: the broker only ever issues tokens whose `aud` equals the client's configured audience, and a client created without `--aud` can never obtain a non-null `aud` — it cannot request one at token time. A workspace-scoped client **MUST** therefore always be created with `--aud`. The service-client secret is printed **once** at creation and stored only as a hash.

**Cross-repo dependency — this is a hard blocker for inbound mode.** GrandpaSSOn's scope vocabulary is a **static allowlist** in `app/Domain/ScopeVocabulary.php`; there is no dynamic scope registration, and `client:create-service` aborts on an unknown scope. The scopes `status:read`, `status:write`, and `status:callback` **do not exist today**. Therefore:

> **Implementation update (2026-08-04):** GrandpaSSOn delivered all three scopes in [#117](https://github.com/suporterfid/grandpasson/pull/117). The remaining live-verification inputs are the documented operator registrations, credentials, and local tenant mapping.
>

- The scope request was delivered by [grandpasson#117](https://github.com/suporterfid/grandpasson/pull/117); live verification now requires operator-created registrations, credentials, and an explicit tenant mapping.
- `docs/architecture/grandpasson-cross-repo.md` **MUST** track the status of each scope, mirroring how TaskConnect tracks `tasks:write`.
- PR13 **MUST** keep flags defaulted off and be tested against fakes. v0 **MUST NOT** be blocked on deployment configuration.

### 15.7 Known traps (verified against broker source — do not repeat these)

1. **Do not copy Jotter's adapter.** `jotter GrandpaSSOnIdentityProvider` calls **no broker HTTP endpoint at all**. It reads the broker's `sessions` and `users` tables directly via raw PDO, relying on both apps sharing one MySQL database (distinguished by table prefix) and one cookie host. That works for Jotter's deployment shape, but it bypasses `/session/exchange` entirely and therefore **never sees `tenant`, `tenants`, or `groups`** — the exact claims StatusConnect needs from day one. Copy Jotter's *interface and decorator structure*; implement the *transport* as the real HTTP flow of §15.3.
2. **Do not copy `taskconnect HttpIntrospectionClient` verbatim — it contains a live defect.** It sends credentials via `->withBasicAuth(...)` and posts only `['token' => …]`. The broker reads `client_id`/`client_secret` from the **request body** and never inspects the `Authorization` header — it implements no HTTP Basic auth anywhere. Against the current broker this returns `401 invalid_client`, which the client maps to `active: false`, so inbound auth silently fails closed and every token appears invalid. StatusConnect **MUST** post `client_id`, `client_secret`, and `token` in the form body, exactly as `HttpTokenClient` does. A test **MUST** assert the request body contains the credentials. This **SHOULD** also be reported upstream as a TaskConnect issue.
3. **Introspection never returns a tenant.** The `tenant` claim exists in the response shape but no broker code path populates it — no token-issuing branch passes a tenant id. Machine tokens carry **no tenancy**. Workspace narrowing is achieved solely through `aud`. Do not design against `introspection.tenant`.
4. **`GET /session` carries no tenancy either** — it returns v0 identity fields only. Tenant claims come from `POST /session/exchange` (or `GET /me/active-tenant` for a cookie session).
5. **`grant_type=authorization_code` returns no `aud` and no tenant/group claims.** A public/PKCE client cannot obtain tenancy from the token endpoint. This is another reason StatusConnect uses a confidential RP client plus `/session/exchange`.
6. **`GET /.well-known/jwks.json` returns `{"keys":[]}` on internal error**, indistinguishable from "JWT disabled". An empty key set **MUST NOT** be treated as a meaningful signal. v0 **SHOULD NOT** depend on JWT verification at all — the opaque token plus introspection is the supported path, and a JWT stays cryptographically valid until `exp` even after revocation.
7. **There is no OIDC discovery document, no `/userinfo`, and no refresh tokens.** Do not reach for a generic OIDC client library and assume discovery.
8. **The broker ships no seeded dev fixtures** — no demo tenant, no demo client. A subject user id only exists after a real login, so `tenant:add-member` cannot run until someone has signed in once. Email OTP (`/login/email`) is the lowest-friction way to create a first subject without configuring an upstream IdP. `docs/integrations/grandpasson.md` **MUST** give a copy-pasteable local bootstrap sequence.

---

## 16. TaskConnect integration

The two products are complementary: TaskConnect runs outbound work on a schedule; StatusConnect observes whether things are alive. Three concrete integrations, each independently optional.

### 16.1 StatusConnect monitors TaskConnect

The simplest direction, requiring no code: TaskConnect exposes `GET /v1/platform/health`. An operator configures an `http` monitor against it with a `json_path` assertion on the health field, using an API key held in a StatusConnect `Secret`.

Docs **MUST** ship this as a worked example in `docs/integrations/taskconnect.md`.

### 16.2 TaskConnect proves its cron is alive (heartbeat)

The most valuable direction, and the reason `heartbeat` monitors are in v0 rather than deferred.

TaskConnect's own failure mode on shared hosting is **silent cron death** — the scheduler stops and nothing announces it. A StatusConnect heartbeat monitor closes that loop:

1. Create a `heartbeat` monitor in StatusConnect with `interval_seconds = 60`, `grace = 180`.
2. Add its ping URL to the TaskConnect host's crontab, chained after the scheduler so it only fires on success:
   ```cron
   * * * * * /opt/alt/php83/usr/bin/php /home/account/app/artisan scheduler:execute-due >/dev/null 2>&1 && curl -fsS -m 10 https://status.example.com/ping/<token> >/dev/null 2>&1
   ```
3. If TaskConnect's cron dies, StatusConnect opens an incident within the grace period.

This is a pure-configuration integration — no coupling, no shared code, works today. It **MUST** be documented prominently, and StatusConnect's own cron **SHOULD** be monitored the same way by a second installation or a free external service, since a monitor cannot reliably report its own death.

### 16.3 StatusConnect delegates notification delivery to TaskConnect

StatusConnect handles email inline (simple, bounded). Reliable **webhook fan-out** is a genuinely hard problem — retries, backoff, idempotency, dead-letter queues, SSRF, per-host rate limiting — and TaskConnect already solves it, tested.

Therefore the `taskconnect` notification channel:

- Submits a task to `POST /v1/tenants/{tenantId}/environments/{environmentId}/tasks` on a configured TaskConnect installation.
- Authenticates either with a TaskConnect API key or, when `GRANDPASSON_OUTBOUND_ENABLED` is on, with a GrandpaSSOn machine token carrying `tasks:write` and an `aud` covering the target workspace (§15.3).
- Sends an idempotency key derived from `(incident_id, channel_id, event)`, so a retried tick cannot produce a duplicate task — TaskConnect enforces this via its `idempotency` middleware.
- Treats a `2xx` as *accepted for delivery*, not as delivered. StatusConnect's UI **MUST** show the TaskConnect run link and **MUST NOT** claim delivery succeeded.

This channel **MUST** be optional and **MUST** degrade gracefully: if TaskConnect is unreachable, StatusConnect falls back to its own webhook channel and records the degradation. StatusConnect **MUST NOT** require TaskConnect to function — that would violate §4.1.

### 16.4 What is explicitly not integrated

- StatusConnect **MUST NOT** delegate its *checks* to TaskConnect. Check execution needs `curl_multi` batching within one tick; routing each check through TaskConnect's sequential per-task delivery would reduce throughput by an order of magnitude and add a network hop to every check.
- The two products **MUST NOT** share a database.
- No shared Composer package is created in v0. Duplicated outbound-policy code is accepted, with source comments (§4.4); extraction into a shared package is a post-v0 candidate (§22).

---

## 17. Development environment (Docker only)

### 17.1 The `stc` wrapper

Twin wrappers with identical verb lists, mirroring TaskConnect's `scripts/tc.sh` / `scripts/tc.ps1`, plus a `Makefile` that proxies to the bash one. (`stc`, not `sc` — `sc.exe` is a built-in Windows command.)

```text
Usage: ./scripts/stc.sh <verb> [args...]

Verbs:
  up           Start core services (app, mysql, mailpit, target)
  down         Stop and remove containers
  bootstrap    Install dependencies, prepare env, migrate database
  composer     Run composer via app container
  artisan      Run artisan via app container
  npm          Run npm via node container (dev profile)
  test         Run PHPUnit test suite
  e2e          Run Playwright end-to-end suite
  release      Build production release zip into dist/
  deploy       Build release and publish over FTP(S)+SSH
  shell        Open shell in app container
  help         Show this help
```

Requirements carried over from TaskConnect's wrapper:

- The script **MUST** resolve the repo root from `BASH_SOURCE` and `cd` there, so it works from any cwd.
- A single `compose()` helper **MUST** wrap `docker compose -f compose.yaml [-f compose.ci.yaml]`, with the CI overlay auto-selected from `STC_CI=1` / `CI=true` / `GITHUB_ACTIONS=true`.
- `composer install` **MUST** retry 3 times with exponential backoff (5s, 10s).
- If `COMPOSER_PACKAGIST_URL` is set, the wrapper **MUST** warn on stderr and forward it to the container. Mirror configuration **MUST NOT** be committed.
- `bootstrap` **MUST** be idempotent and probe for `artisan` / `package.json` / a test runner rather than assuming scaffolding exists.

### 17.2 Compose services

| Service | Image / build | Purpose |
|---|---|---|
| `app` | `docker/php/Dockerfile` (`php:8.2-apache`) | Application, port `${APP_PORT:-8080}:80` |
| `mysql` | `mysql:8.0` | Database, with a `mysqladmin ping` healthcheck |
| `mailpit` | `axllent/mailpit` | SMTP sink + UI on 8025, SMTP on 1025 |
| `target` | `docker/target/Dockerfile` | **Controllable check target** (§17.3) |
| `node` | `docker/node/Dockerfile`, profile `dev` | Vite dev server on 5173 |

`compose.ci.yaml` **MUST** override ports only, using Compose's `!override` tag, to avoid host port collisions.

PHP extensions in the app image **MUST** include `pdo_mysql mbstring bcmath intl zip pcntl opcache gd exif` **plus `curl`** (§8.5 depends on it).

### 17.2.1 Running alongside GrandpaSSOn and TaskConnect

The sibling projects were not designed to share a Docker network, and their default ports collide. StatusConnect **MUST** be configured to coexist:

| Project | Ports already taken |
|---|---|
| GrandpaSSOn | 8080 (broker), 8081 (phpMyAdmin), 3306 (MySQL, published) |
| TaskConnect | 8080 (app), 8025 (Mailpit), 8090 (receiver), 3306 (MySQL) |
| **StatusConnect (proposed)** | **8070** (app), **8035** (Mailpit), **8095** (target), **3308** (MySQL) |

- Every published port **MUST** come from an env var with a StatusConnect-specific default, so an operator running all three can remap without editing `compose.yaml`.
- GrandpaSSOn publishes no service alias and joins no external network. Cross-project HTTP **MUST** therefore go through the host: set `GRANDPASSON_BASE_URL=http://host.docker.internal:8080` and add `extra_hosts: ["host.docker.internal:host-gateway"]` to the `app` service. `http://web:80` **MUST NOT** be assumed to resolve.
- The broker's own `BROKER_BASE_URL` must be the URL the **browser** sees, because it becomes the JWT `iss` and drives the broker's URL prefix derivation. A mismatch produces redirect URIs that fail exact-match validation.
- `docs/integrations/grandpasson.md` **MUST** document this networking shape, since it is the first thing that breaks in local development.

### 17.3 The `target` service — dev fixture

TaskConnect ships a `receiver` that records inbound requests. StatusConnect needs the inverse: **a target whose behaviour can be driven from the test**.

`docker/target/server.js` (dependency-free Node, mirroring the style of TaskConnect's `receiver`) **MUST** support control via path or query:

- `/status/{code}` — respond with an arbitrary status code
- `/delay/{ms}` — respond after a delay, to exercise timeouts
- `/flap?period=N` — alternate up/down deterministically, to exercise confirmation thresholds and flap detection
- `/body?text=…` — arbitrary body, to exercise keyword assertions
- `/redirect/{n}` — chained redirects, to exercise the redirect limit
- `/close` — accept then close the connection, to exercise transport errors

It **MUST** be the default entry in `OUTBOUND_TESTING_ALLOW_HOSTS`, reachable as `http://target:8080` inside the network and `http://localhost:8090` from the host, and **MUST** be allowlisted only when `APP_ENV` is `local` or `testing` (mirroring `config/outbound.php`'s existing guard).

### 17.4 Local verification

There is **no GitHub Actions CI**, mirroring TaskConnect. Before pushing, verify locally:

```bash
./scripts/stc.sh test
./scripts/stc.sh npm --prefix frontend run test
./scripts/stc.sh npm --prefix frontend run build
```

`STC_CI=1` selects the production-like compose overlay.

---

## 18. Production deployment (cPanel / Hostinger)

### 18.1 Requirements

- PHP 8.2+ with `ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, pdo_mysql, session, tokenizer, xml, zip, intl, bcmath`
- MySQL 8.0+ (or a documented MariaDB equivalent)
- Apache or LiteSpeed with `mod_rewrite`; document root at `public/`
- Cron at one-minute cadence
- Writable `storage/` and `bootstrap/cache/`
- Outbound HTTPS access
- **Not required:** Node.js, Docker, Redis, queue workers, or Composer (the release ships `vendor/`)

`docs/deployment/requirements.md` **MUST** call out `ext-curl` explicitly and note that `curl_multi_*` is required for check concurrency.

### 18.2 Release packaging

Mirror `taskconnect docker/release/Dockerfile` — release-as-a-Dockerfile, not a shell script:

1. **vendor** — `composer:2`, `composer install --no-dev --optimize-autoloader --no-scripts`
2. **frontend** — `node:22-bookworm-slim`, `npm ci && npm run build`
3. **release** — `alpine`, assemble `/release/app`, strip `node_modules frontend/node_modules tests .git .github docker scripts compose*.yaml Makefile phpunit.xml`, create `storage/framework/{cache,sessions,views}`, `storage/logs`, `bootstrap/cache`, `chmod 775`, then `zip` and `sha256sum`
4. **export** — `FROM scratch`, emitting `dist/app/`, `dist/statusconnect-release.zip`, and `dist/statusconnect-release.zip.sha256`

### 18.3 Release validation (secret hygiene)

`scripts/validate-release.sh` **MUST** run automatically at the end of `stc release` and **MUST** fail the build on any of:

- a `.env` or `.env.*` file other than `.env.example`
- `*.pem`, `*.key`, `id_rsa`, `id_ed25519`, `*.p12`, `*.pfx`
- a `BEGIN … PRIVATE KEY` block
- credential-like assignments (`AWS_SECRET_ACCESS_KEY`, `GITHUB_TOKEN`, `OPENAI_API_KEY`, `DATABASE_URL`, …) not matching the placeholder allowlist
- token-like literals (`sk_live_…`, `xox[baprs]-…`)

It **MUST** also assert structure: `app/artisan`, `app/vendor/`, `app/public/build/manifest.json` present; `node_modules` and `tests/` absent; and, when given a zip, verify the `.sha256` sidecar.

The validator **MUST** itself be covered by a feature test that plants a `.env` and a `.pem` in a synthetic tree and asserts non-zero exit — mirroring `taskconnect tests/Feature/ReleaseSecretScanTest.php`. **These release secret checks MUST be preserved in every PR.**

### 18.4 Host layout

On Hostinger the application commonly lives *inside* `public_html`. The deploy script **MUST** therefore inject a hardened root `.htaccess`, mirroring TaskConnect's:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    # Hard-deny dotfiles that must never be exposed.
    RewriteRule (^|/)\.(?!well-known)([^/]+) - [F,L]
    # Send everything else through the public/ front controller.
    RewriteCond %{REQUEST_URI} !^/public/
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

### 18.5 Installation flow

1. Upload and extract the release (outside the public web root when the host allows it).
2. Point the document root at `public/`.
3. Copy `.env.example` to `.env`; set `APP_URL`, `APP_KEY`, database credentials, and mail.
4. Make `storage/` and `bootstrap/cache/` writable by the PHP user.
5. `php artisan migrate --force`
6. `php artisan platform:bootstrap-admin you@example.com 'StrongPassword' --name='You'`
7. Configure the three cron lines (§8.1).
8. Log in, create a tenant and environment, add a monitor, and confirm a check result plus a fresh heartbeat within two minutes.
9. Publish a status page and verify §11.4 (no target disclosure).
10. Complete the security checklist.

### 18.6 Deployment docs

`docs/deployment/` **MUST** contain small, single-purpose, cross-linked files, mirroring TaskConnect's split: `requirements.md`, `installation.md`, `cron.md`, `upgrade.md`, `backup.md`, `security.md`, `troubleshooting.md`, `acceptance-checklist.md`, `automated-ftp-deploy.md`.

`backup.md` **MUST** state that `APP_KEY` is required to decrypt stored secrets and that losing it makes them unrecoverable.

---

## 19. REST API

### 19.1 Conventions

- All authenticated endpoints under `/v1`, mirroring TaskConnect's `routes/api.php`.
- Resources are nested `tenants/{tenantId}/environments/{environmentId}/…`.
- Two middleware enforce context: `auth.api_or_sanctum` (Sanctum session **or** API key **or** GrandpaSSOn token) and `tenant.context`.
- Responses use `public_id`; internal `id` **MUST NOT** appear.
- A consistent error envelope with a machine-readable code, mirroring `ApiErrorRenderer`.
- Write endpoints accepting user input **MUST** support an idempotency key.
- All timestamps are ISO-8601 UTC.

### 19.2 Core routes (v0)

```text
POST   /v1/auth/login | logout | forgot-password | reset-password
GET    /v1/me
GET    /v1/platform/health

GET    /v1/tenants/{t}/environments/{e}/monitors
POST   /v1/tenants/{t}/environments/{e}/monitors
GET    /v1/tenants/{t}/environments/{e}/monitors/{m}
PATCH  /v1/tenants/{t}/environments/{e}/monitors/{m}
DELETE /v1/tenants/{t}/environments/{e}/monitors/{m}
POST   /v1/tenants/{t}/environments/{e}/monitors/{m}/pause | resume | check-now
GET    /v1/tenants/{t}/environments/{e}/monitors/{m}/results
GET    /v1/tenants/{t}/environments/{e}/monitors/{m}/uptime?period=24h|7d|30d|90d

GET    /v1/tenants/{t}/environments/{e}/incidents
POST   /v1/tenants/{t}/environments/{e}/incidents            (manual)
GET    /v1/tenants/{t}/environments/{e}/incidents/{i}
POST   /v1/tenants/{t}/environments/{e}/incidents/{i}/updates
POST   /v1/tenants/{t}/environments/{e}/incidents/{i}/resolve

CRUD   …/maintenance-windows, …/notification-channels, …/status-pages, …/secrets
POST   …/notification-channels/{c}/test
GET    /v1/tenants/{t}/audit-logs
```

Public, unauthenticated:

```text
GET        /status/{slug}
GET        /status/{slug}.json
GET        /status/{slug}/feed.xml
GET|POST   /ping/{token}
```

### 19.3 `check-now`

The interactive "check now" action **MUST** run through the identical code path as the scheduler, including the full outbound policy, and **MUST** be rate-limited per monitor. It **MUST NOT** write a `check_result` that would distort uptime statistics — its outcome is returned to the caller and recorded as a diagnostic, flagged `manual`.

---

## 20. Frontend, i18n, and accessibility

### 20.1 Operator UI (Vue 3 SPA)

Required screens: login, dashboard, monitor list, monitor create/edit, monitor detail (with latency chart and result history), incident list, incident detail, maintenance windows, notification channels, status page editor, members, API keys, secrets, audit log, settings.

- The dashboard **MUST** show scheduler heartbeat freshness prominently (§8.6) and a "checks falling behind" warning (§14.2).
- The monitor list **MUST** support filtering by state and searching by name.
- Monitor detail **MUST** show observed-vs-configured interval.
- `blocked` checks **MUST** be surfaced as a configuration error with the policy reason code, visually distinct from `down`.

### 20.2 Internationalization

- Locales `en` and `pt-BR` **MUST** both be complete at v0 — not English-first with Portuguese deferred.
- Namespaces under `frontend/src/i18n/en/` and `frontend/src/i18n/pt-BR/`, mirroring TaskConnect.
- The **public status page** is Blade and **MUST** use Laravel's own translation files under `lang/{en,pt_BR}/`, selected by the status page's configured locale — not by the visitor's `Accept-Language`, since the page represents the operator's chosen presentation.
- Durations, timestamps, and uptime percentages **MUST** be formatted per locale and rendered in the page's configured timezone with the timezone shown.

### 20.3 Accessibility

- WCAG 2.2 AA for both the operator UI and the public status page.
- State **MUST NOT** be conveyed by colour alone; every state carries an icon/shape and a text label (§11.1).
- The 90-day history strip **MUST** be keyboard-navigable and each cell **MUST** have an accessible name including date and state.
- An automated accessibility sweep **SHOULD** run in the Playwright suite, mirroring TaskConnect's `a11y.spec.ts`.

---

## 21. First implementation plan — PR sequence

One PR unit at a time. Each PR **MUST** leave `main` green, with `stc test` passing, and **MUST** update `STATUS.md`.

| PR | Title | Deliverable | Definition of done |
|---|---|---|---|
| **PR0** | Repo scaffold & Docker loop | Laravel 12 skeleton, `compose.yaml`, `compose.ci.yaml`, `docker/{php,node,target}`, `scripts/stc.{sh,ps1}`, `Makefile`, `phpunit.xml` (SQLite `:memory:`), MIT `LICENSE`, `README.md`, `AGENTS.md`, `CLAUDE.md`, `.cursor/rules/`, `STATUS.md`, `BACKLOG.md` | `stc up` serves the app; `stc test` runs; `target` responds on all control routes |
| **PR1** | Tenancy, auth, isolation | users/tenants/memberships/environments/audit_logs migrations, `public_id` (ULID), local auth, policies, `auth.api_or_sanctum` + `tenant.context`, `platform:bootstrap-admin` | Tenant-isolation feature tests pass; cross-tenant access returns 404/403 |
| **PR2** | Outbound policy port | `Domain/Outbound/*` and `Infrastructure/HttpClient/GuzzlePinnedHttpTransport` ported from TaskConnect; `config/outbound.php` with the `monitor` profile; `ArrayDnsResolver` test double | SSRF unit tests pass: metadata IPs, private ranges, rebinding, redirect re-validation, embedded credentials |
| **PR3** | Monitors CRUD + assertions | `monitors`/`monitor_assertions` migrations and indexes (§13.4), API, pure assertion evaluator | Assertion evaluator unit-tested with zero I/O across every operator in §7.3 |
| **PR4** | Scheduler core (sequential) | `monitor:check-due`, `DueMonitorClaimer` with `SKIP LOCKED` leases, `TickBudget`, `HeartbeatWriter`, `StaleClaimRecovery`, `check_results` | Concurrent-claim test proves no double-check; budget test proves clean exit; drift test proves no burst backfill |
| **PR5** | Parallel execution | `CurlMultiPinnedProbe`, wave model, bounded `CHECK_CONCURRENCY`, async TCP probe | A 30-monitor fixture with 5s-delay targets completes within one tick budget; pinning asserted on every multi handle |
| **PR6** | Incident state machine | `Domain/Incidents/*`, confirmation/recovery thresholds, one-open-incident constraint, flap detection, `incidents` + `incident_updates` API | State machine unit-tested exhaustively; `started_at` proven to be the first failing check; `blocked` proven not to open an incident |
| **PR7** | Rollups & retention | `monitor:rollup`, `monitor:maintenance`, `check_rollups`, idempotent upserts, chunked retention gated on rollup completion | Re-running rollup twice produces identical aggregates; retention never deletes un-rolled-up raw rows |
| **PR8** | Public status page | Blade page, `status_pages`/`components`/`cache`, uptime from rollups, JSON + feed, ETag/`304`, IP rate limiting | **Disclosure test**: response contains no target/host/IP/internal id, in HTML and JSON. Query count on the public path asserted |
| **PR9** | Notifications | `notification_channels`, email + signed webhook, dedup constraint, mail-rate coalescing, test-send | Duplicate-tick test proves no double-send; redaction test proves no secret in payload |
| **PR10** | Heartbeat monitors | `/ping/{token}`, grace evaluation, token rotation, rate limiting | Missed-ping test opens an incident after the grace period; unknown token returns 404 indistinguishably |
| **PR11** | Maintenance windows | Windows, suppression, status-page banner, rollup exclusion | Uptime denominator excludes window time; no notification fires inside a window |
| **PR12** | Operator SPA | Vue 3 + Pinia + vue-router + vue-i18n + Tailwind; all screens of §20.1; `en` + `pt-BR` complete | `npm run build` clean; `vue-tsc` clean; both locales complete |
| **PR13** | GrandpaSSOn seam | `config/grandpasson.php`, `IdentityProvider` seam (HTTP code flow, **not** Jotter's shared-DB shortcut), `/session/exchange` redemption, introspection client **with body credentials** + cache, workspace-`aud` middleware (dual-form), `docs/integrations/grandpasson.md`, cross-repo doc + broker scope issue | Dual-mode tests: everything passes with flags off. Inbound tests against fakes. **A test asserts introspection posts `client_id`/`client_secret` in the form body** (§15.7.2). `audienceIncludes` tested for both raw and `workspace/`-prefixed forms. `state` mismatch and expired-code paths fail closed |
| **PR14** | TaskConnect integration | `taskconnect` notification channel, idempotency key, graceful degradation, `docs/integrations/taskconnect.md` with the heartbeat cron recipe | Channel failure falls back and records degradation; StatusConnect works fully with the channel unconfigured |
| **PR15** | Release & deploy | `docker/release/Dockerfile`, `validate-release.sh` + its feature test, `docker/deploy/`, `scripts/deploy.sh`, all `docs/deployment/` | `stc release` produces a validated zip; secret-scan test proves planted `.env`/`.pem` fail the build |
| **PR16** | E2E & accessibility | Playwright specs: operator journey, public status page, a11y sweep; `docs/deployment/acceptance-checklist.md` | Suite green against the compose stack |

### 21.1 Sequencing notes

- **PR2 before PR4.** The scheduler must never exist in a state where it can make unvalidated outbound requests.
- **PR4 before PR5.** Prove correctness sequentially, then make it fast. Debugging a claim-lease race and a `curl_multi` bug simultaneously is not worth the saved PR.
- **PR7 before PR8.** The status page reads only rollups (§11.3); building it against raw results would bake in a query pattern that must then be torn out.
- **PR13 and PR14 are independent** of each other and of PR8–PR12; they may be reordered if the cross-repo scope dependency (§15.6) blocks.

---

## 22. v0 stop line (Definition of Done)

v0 is complete when **all** of the following hold. Request review before starting post-v0 work.

- [ ] A fresh clone reaches a working app with `./scripts/stc.sh up` and no host PHP/Node/Composer.
- [ ] `stc test`, `npm run test`, and `npm run build` are green.
- [ ] 300 monitors at 60-second intervals are checked within budget on the reference profile (§14.1), demonstrated by a documented load fixture.
- [ ] Two concurrent `monitor:check-due` invocations provably never double-check a monitor.
- [ ] The SSRF suite passes, including metadata IPs, private ranges, DNS rebinding, and redirect re-validation.
- [ ] A confirmed incident opens, notifies exactly once, and resolves, with `started_at` at the first failing check.
- [ ] The public status page serves from cache with a bounded query count and passes the §11.4 disclosure test.
- [ ] Uptime percentages are computed from rollups and exclude maintenance and `no_data`.
- [ ] Retention keeps a 50-monitor installation within a documented database size budget over a simulated 90 days.
- [ ] Both `en` and `pt-BR` are complete in the SPA and on the status page.
- [ ] `stc release` produces a zip that passes `validate-release.sh`, including the secret scan.
- [ ] A real deployment to a cPanel/Hostinger account is documented as verified in `docs/deployment/acceptance-checklist.md`.
- [ ] GrandpaSSOn dual-mode works with flags off (default) and with flags on against a live broker.
- [ ] The TaskConnect heartbeat recipe (§16.2) is verified end to end.
- [ ] Every merged PR is linked to a closed GitHub issue (§4.3).

### 22.1 Post-v0 candidates (explicitly out of scope now)

Multi-region checking via a second cooperating installation; status page custom domains with automated TLS; Slack/Discord/Telegram payload templates; per-monitor SLO/error budgets; a public read-only API for badges; extraction of the shared outbound-policy code into a Composer package used by both TaskConnect and StatusConnect; MCP server exposing monitor state to agents (mirroring Jotter); import from Uptime Kuma.

---

## 23. Open questions (resolve before or during implementation)

1. **Status page hosting shape.** Is a status page expected at the installation's document root (`status.example.com`), or always under `/status/{slug}`? Custom domains on shared hosting require a parked/addon domain and manual TLS; v0 assumes path-based and treats domains as post-v0.
2. **Interval floor policy.** Should a tenant be able to configure a 30-second interval knowing cron cannot honour it? Recommendation: reject anything below 60s at validation time rather than silently degrading.
3. **`degraded` in uptime maths.** v0 excludes `degraded` from downtime. Should it be configurable per status page? Recommendation: keep it fixed in v0 and documented; configurability invites uptime figures that cannot be compared.
4. **GrandpaSSOn scope vocabulary.** `status:read` / `status:write` / `status:callback` must be added to `ScopeVocabulary` in the broker repo before inbound mode can be verified live (§15.6). Who owns that issue? Recommendation: ship v0 with the flags off, tested against fakes, and treat live verification as a post-v0 acceptance item so StatusConnect is never blocked on another repo.
4a. **Introspection caching vs. revocation latency.** Caching is forced by the broker's 60 req/min/IP limit (§15.4). Is a 30-second revocation window acceptable, or should high-privilege writes bypass the cache and introspect every time? Recommendation: cache reads, bypass for destructive operations.
5. **Heartbeat-only tenants.** Should a tenant be allowed to create heartbeat monitors without any outbound checks (a pure dead-man's-switch installation)? This has near-zero check cost and may be the cheapest onboarding path.
6. **Self-monitoring honesty.** A monitor cannot reliably report its own death. Should v0 ship a documented recipe for a free external service pinging StatusConnect, and should the status page state plainly when the checker heartbeat is stale?
7. **Retention vs. host quota.** Should `monitor:maintenance` measure actual table sizes and tighten retention automatically when approaching a configured budget, or only warn? Recommendation: warn in v0, automate post-v0.

---

## 24. Repository layout (target)

```text
statusconnect/
  app/
    Application/{Checks,Incidents,Notifications,Rollups,StatusPages,Monitors,
                 Members,ApiKeys,Audit,Tenancy,Secrets,Auth,GrandpaSson}/
    Domain/{Monitoring,Incidents,Outbound,StatusPage,Secrets,Shared}/
    Infrastructure/{Persistence/Eloquent,HttpClient,Tcp,Dns}/
    Http/{Controllers/Api/V1,Controllers/Public,Resources,Middleware,Support}/
    Console/Commands/
    Policies/
    Providers/
  bootstrap/  config/  database/{factories,migrations,seeders}/
  frontend/
    src/{components,pages,router,stores,services,i18n,utils}/
    e2e/
  lang/{en,pt_BR}/
  public/
  resources/views/status/          # Blade public status page
  routes/{api.php,web.php,public.php}
  scripts/{stc.sh,stc.ps1,deploy.sh,validate-release.sh}
  docker/{php,node,target,release,deploy}/
  storage/
  tests/{Unit,Feature,Support}/
  docs/
    statusconnect-initial-spec-and-build-plan.md   # this document
    architecture/
    deployment/
    integrations/{taskconnect.md,grandpasson.md}
  compose.yaml  compose.ci.yaml  Makefile  phpunit.xml
  AGENTS.md  CLAUDE.md  README.md  STATUS.md  BACKLOG.md  CHANGELOG.md  LICENSE
  .cursor/rules/*.mdc
```

`AGENTS.md` **SHOULD** be a ~30-line index deferring to `CLAUDE.md`, and `.cursor/rules/` **SHOULD** contain exactly one `alwaysApply: true` hard-constraints rule plus file-scoped rules — mirroring TaskConnect.

---

## 25. Testing strategy

### 25.1 Unit tests (no I/O)

Assertion evaluation for every operator; incident state machine across every transition; confirmation/recovery counting; flap detection; uptime calculation including gaps and maintenance; rollup aggregation idempotency; `IpClassifier` across IPv4/IPv6 ranges; `UrlValidator` refusals; `SecretRedactor`; `TickBudget`; interval/drift calculation.

### 25.2 Feature tests (SQLite `:memory:`)

Tenant isolation across every resource; claim-lease concurrency; budget-stop behaviour; retention gating; notification dedup; heartbeat grace evaluation; status page disclosure and query count; API authorization per role; GrandpaSSOn inbound with a fake introspection client; release secret scan.

### 25.3 Test doubles (`tests/Support/`)

Mirror TaskConnect's support layer: `FixedClock`, `ArrayDnsResolver`, `MockPinnedHttpTransport`, `FakeGrandpaSsonIntrospectionClient`, `FakeGrandpaSsonTokenClient`, `CreatesTenantFixtures`, `CreatesMonitorFixtures`.

### 25.4 Portability

Tests run on SQLite; production runs on MySQL. Migrations and queries **MUST** be portable across both, including the driver-detected `SKIP LOCKED` branch (§8.2). Any MySQL-only construct **MUST** have an explicit SQLite fallback and a comment saying why.

### 25.5 End-to-end (Playwright, `frontend/e2e/`)

Operator journey (log in → create monitor → observe a result → force a failure via the `target` service → see the incident → resolve); public status page journey; accessibility sweep. Browsers **MUST** be installed inside the node container, never on the host.

---

## 26. Licence

MIT. See `LICENSE`.

Patterns are adapted from [`suporterfid/taskconnect`](https://github.com/suporterfid/taskconnect) and [`suporterfid/grandpasson`](https://github.com/suporterfid/grandpasson), both MIT and under the same ownership.
