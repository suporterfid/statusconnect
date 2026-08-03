# StatusConnect Implementation Status

Current Phase: PR4 remediation (Scheduler core (sequential))

## PR Sequence Table

| PR | Title | Status | Issue | Verified On / Details |
|---|---|---|---|---|
| **PR0** | Repo scaffold & Docker loop | Completed | [#1](https://github.com/suporterfid/statusconnect/issues/1) | Scaffold, compose stack, stc wrapper, target mock service, PHPUnit test suite passing |
| **PR1** | Tenancy, auth, isolation | Completed | [#2](https://github.com/suporterfid/statusconnect/issues/2) | Core tables & security migrations, Eloquent models, PublicId, ApiKeyService, sc_* bearer auth, workspace scoping middleware (ResolveTenantEnvironment), tenant isolation (404), RBAC, 19 unit & feature tests passing |
| **PR2** | Outbound policy port | Completed | [#3](https://github.com/suporterfid/statusconnect/issues/3) | OutboundPolicy, IpClassifier, UrlValidator, DnsResolver, GuzzlePinnedHttpTransport, SSRF prevention (loopback, RFC1918, metadata, IPv6 link-local blocked), 27 unit & feature tests passing |
| **PR3** | Monitors CRUD + assertions | Completed | [#4](https://github.com/suporterfid/statusconnect/issues/4) | Monitors & assertions database migrations, Eloquent models (Monitor, MonitorAssertion), pure AssertionEvaluator engine for 7 assertion types, MonitorService, API endpoints, 39 unit & feature tests passing |
| **PR4** | Scheduler core (sequential) | In remediation | [#5](https://github.com/suporterfid/statusconnect/issues/5) | Corrective branch implements `monitor:check-due`, portable conditional claim leases, claim-time drift scheduling, TickBudget, checker/maintenance heartbeats, stale-claim recovery, claim-token fencing, and a two-process concurrent claim test. Fresh verification: 60 tests / 129 assertions. PR5 remains blocked until this PR is merged. |
| **PR5** | Parallel execution | Pending | - | - |
| **PR6** | Incident state machine | Pending | - | - |
| **PR7** | Rollups & retention | Pending | - | - |
| **PR8** | Public status page | Pending | - | - |
| **PR9** | Notifications | Pending | - | - |
| **PR10** | Heartbeat monitors | Pending | - | - |
| **PR11** | Maintenance windows | Pending | - | - |
| **PR12** | Operator SPA | Pending | - | - |
| **PR13** | GrandpaSSOn seam | Pending | - | - |
| **PR14** | TaskConnect integration | Pending | - | - |
| **PR15** | Release & deploy | Pending | - | - |
| **PR16** | E2E & accessibility | Pending | - | - |
