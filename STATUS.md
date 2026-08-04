# StatusConnect Implementation Status

Current Phase: PR6 complete (Incident state machine); PR1 authentication remediation [#14](https://github.com/suporterfid/statusconnect/issues/14) merged via [PR #15](https://github.com/suporterfid/statusconnect/pull/15). PR13 remains blocked only on the broker scope-vocabulary proposal.

## PR Sequence Table

| PR | Title | Status | Issue | Verified On / Details |
|---|---|---|---|---|
| **PR0** | Repo scaffold & Docker loop | Completed | [#1](https://github.com/suporterfid/statusconnect/issues/1) | Scaffold, compose stack, stc wrapper, target mock service, PHPUnit test suite passing |
| **PR1** | Tenancy, auth, isolation | Completed | [#2](https://github.com/suporterfid/statusconnect/issues/2), [#14](https://github.com/suporterfid/statusconnect/issues/14) | Core tables & security migrations, Eloquent models, PublicId, ApiKeyService, sc_* bearer auth, workspace scoping middleware (ResolveTenantEnvironment), tenant isolation (404), RBAC. Remediation [PR #15](https://github.com/suporterfid/statusconnect/pull/15) restored the required local email/password session flow; `stc test` passed with 101 tests / 284 assertions. |
| **PR2** | Outbound policy port | Completed | [#3](https://github.com/suporterfid/statusconnect/issues/3) | OutboundPolicy, IpClassifier, UrlValidator, DnsResolver, GuzzlePinnedHttpTransport, SSRF prevention (loopback, RFC1918, metadata, IPv6 link-local blocked), 27 unit & feature tests passing |
| **PR3** | Monitors CRUD + assertions | Completed | [#4](https://github.com/suporterfid/statusconnect/issues/4) | Monitors & assertions database migrations, Eloquent models (Monitor, MonitorAssertion), pure AssertionEvaluator engine for 7 assertion types, MonitorService, API endpoints, 39 unit & feature tests passing |
| **PR4** | Scheduler core (sequential) | Completed | [#5](https://github.com/suporterfid/statusconnect/issues/5) | `monitor:check-due`, portable conditional claim leases, claim-time drift scheduling, TickBudget, checker/maintenance heartbeats, stale-claim recovery, claim-token fencing, and a two-process concurrent claim test. Verified with 61 tests / 131 assertions. PR5 is next. |
| **PR5** | Parallel execution | Completed | [#7](https://github.com/suporterfid/statusconnect/issues/7) | Bounded 10-wide waves, DNS-pinned `curl_multi` HTTP, async pinned TCP, shared wave deadlines, claim-token fenced persistence, and the parallel `monitor:check-due` path. The real 30-monitor / 5-second fixture completed in 15.22s within the 45-second budget; 77 tests / 190 assertions verified. |
| **PR6** | Incident state machine | Completed | [#9](https://github.com/suporterfid/statusconnect/issues/9) | Merged via [PR #10](https://github.com/suporterfid/statusconnect/pull/10): pure confirmation/recovery transitions, transactional lifecycle, one-open-incident constraint, persisted flap suppression, and environment-scoped/idempotent incident API. Main verified with 98 tests / 272 assertions. PR7 is next. |
| **PR7** | Rollups & retention | Pending | - | - |
| **PR8** | Public status page | Pending | - | - |
| **PR9** | Notifications | Pending | - | - |
| **PR10** | Heartbeat monitors | Pending | - | - |
| **PR11** | Maintenance windows | Pending | - | - |
| **PR12** | Operator SPA | Pending | - | - |
| **PR13** | GrandpaSSOn seam | Blocked | [#13](https://github.com/suporterfid/statusconnect/issues/13) | Implementation is ready on its feature branch. Its local-auth prerequisite was restored by [PR #15](https://github.com/suporterfid/statusconnect/pull/15); the remaining blocker is the broker scope-vocabulary proposal [grandpasson#116](https://github.com/suporterfid/grandpasson/issues/116). |
| **PR14** | TaskConnect integration | Pending | - | - |
| **PR15** | Release & deploy | Pending | - | - |
| **PR16** | E2E & accessibility | Pending | - | - |
