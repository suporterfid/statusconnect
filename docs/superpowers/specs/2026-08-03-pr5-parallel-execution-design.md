# PR5 Parallel Execution Design

**Issue:** [#7](https://github.com/suporterfid/statusconnect/issues/7)

**Goal:** Replace PR4's one-monitor-at-a-time check loop with bounded concurrent HTTP and TCP waves, without weakening DNS-pinned outbound protection or the established claim-token fence.

## Scope

PR5 introduces `CurlMultiPinnedProbe`, a wave scheduler, and a non-blocking TCP probe. It keeps lease claiming, tick budgeting, heartbeat persistence, result persistence, assertion evaluation, and incident handling at their existing boundaries. Incident state transitions remain PR6 work.

## Chosen design

`ParallelMonitorCheckRunner` replaces `SequentialMonitorCheckRunner` as the implementation called by `monitor:check-due`. Before each wave, it asks `TickBudget` whether the tick can still safely claim work, then calls `DueMonitorClaimer` with a limit of at most `CHECK_WAVE_SIZE`. The configuration defaults `CHECK_CONCURRENCY` and `CHECK_WAVE_SIZE` to 10; both values are normalized to a minimum of 1, and concurrency is capped at 50. A wave never claims a monitor twice in one tick.

The runner partitions a claimed wave by monitor kind. Heartbeat monitors are evaluated through their database-only path and never enter the HTTP/TCP concurrency budget. HTTP and TCP monitors run concurrently. Once the whole wave settles, the runner hands each completed outcome to the existing persistence/fencing path, then checks `TickBudget` again before claiming another wave. If remaining budget cannot safely accommodate another wave, it releases only unstarted claims, records `budget_stopped` in the checker heartbeat, and exits successfully.

`CurlMultiPinnedProbe` accepts already validated `PinnedHttpRequest` values. It creates one cURL easy handle per request, applies the profile timeouts, sanitized headers, TLS setting, request body, body-size guard, and `CURLOPT_RESOLVE` built from that request's validated endpoint. Redirect following remains manual: a redirect completion is revalidated by `OutboundPolicy`, receives a newly pinned endpoint, and is queued as a subsequent handle up to the configured redirect limit. No request URL reaches a multi handle without validation and an explicit per-handle resolve entry.

The probe returns the existing `PinnedHttpResponse` shape, including a transport error instead of throwing for ordinary connection failures. The execution layer continues to map policy refusal to `blocked`, perform pure assertion evaluation, redact stored failure excerpts, and fence persistence with the original claim token. Latency is measured from wave handle start/completion metadata, rather than from a sequential blocking call.

`PinnedTcpProbe` uses `stream_socket_client()` with `STREAM_CLIENT_ASYNC_CONNECT` and `stream_select()` to make TCP connection attempts concurrently. It has the same timeout bound and validated/pinned endpoint requirement as HTTP. It reports connection success or a transport error in a small value object that the execution layer converts to `CheckOutcome`; it does not use an HTTP concurrency slot beyond its membership in the wave.

## Alternatives considered

1. Keep Guzzle and use its promise pool. This adds an abstraction over cURL that obscures the required per-handle `CURLOPT_RESOLVE` proof and makes the shared-host constraint harder to audit.
2. Use one multi handle for every due monitor. This can strand large numbers of claim leases if the tick budget expires.
3. Run HTTP multi waves but retain sequential TCP. This violates §8.5 and makes TCP monitors disproportionately consume the cron budget.

The selected direct `curl_multi` plus `stream_select` design is the smallest approach that meets the spec and keeps SSRF pinning inspectable.

## Error handling and safety

- Configuration rejects or clamps invalid concurrency values before they reach `curl_multi`.
- A setup failure for one handle produces that monitor's transport-error outcome and does not abort unrelated handles in the wave.
- Every redirect is bounded and revalidated before a new handle is created.
- Only the worker holding a monitor's original claim token may persist the outcome or clear the lease.
- The runner checks the tick budget between waves, never relies on PHP's hard time limit, and writes the heartbeat in all clean-stop paths.
- No worker, queue, broker, runtime shell execution, or new third-party dependency is introduced.

## Required proof

- A 30-monitor fixture against 5-second delayed targets completes in one tick budget under the configured concurrency.
- Tests inspect every created HTTP handle's `CURLOPT_RESOLVE` entry and prove no unpinned user URL is issued.
- Tests prove waves do not claim ahead of the budget, persist completed results before a subsequent claim, and retain the claim-token fence.
- TCP tests prove multiple connection attempts are multiplexed through non-blocking streams instead of serialized.
- The full Docker test suite remains green and `STATUS.md` / `BACKLOG.md` stay synchronized with issue #7.

## Constraints

- PHP 8.2+, MySQL 8.0+ in production, SQLite-compatible tests, Docker-only development.
- Time in Application/Domain code continues to come from `Clock`; the low-level transport may use cURL/stream timestamps only for measured I/O duration.
- All Eloquent models remain under `app/Infrastructure/Persistence/Eloquent/`.
- Any TaskConnect pattern copied while implementing must name its source file in a short attribution comment.
