# PR13 GrandpaSSOn Seam Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the opt-in GrandpaSSOn identity seam while preserving default local, Sanctum, and `sc_*` API-key behavior.

**Architecture:** Define framework-light value objects and interfaces in `Application/GrandpaSson`; bind HTTP and cache implementations in the provider. Extend the existing authentication and tenant pipeline with an opaque-token actor plus post-resolution scope/audience middleware. Browser exchange logic is isolated in an identity provider/controller pair and uses the broker’s documented HTTP routes. A platform-admin-managed mapping persists each broker tenant id, linked local tenant, and role/group mappings; exchange never creates an unapproved tenant.

**Tech Stack:** Laravel 12 HTTP client/cache/session, PHPUnit 11, SQLite in-memory tests, Docker `stc` wrapper.

## Global Constraints

- PHP 8.2+, MySQL 8.0+, minute cron, and document root `public/` are the deployment target.
- Do not require Redis, workers, brokers, or runtime shell functions.
- Run PHP, Composer, and tests only through `scripts/stc.ps1`/`scripts/stc.sh` Docker wrappers.
- Keep Eloquent under `app/Infrastructure/Persistence/Eloquent/`.
- Mirror TaskConnect only after reading its source and attach provenance comments to adapted code.
- GrandpaSSOn scopes remain configurable and both feature flags default to disabled.
- Do not log bearer tokens; use only their SHA-256 fingerprints.

---

### Task 1: Configuration and machine-token contract

**Files:**
- Create: `config/grandpasson.php`
- Create: `app/Application/GrandpaSson/IntrospectionClientInterface.php`
- Create: `app/Application/GrandpaSson/IntrospectionResult.php`
- Create: `app/Auth/GrandpaSsonActor.php`
- Test: `tests/Unit/GrandpaSson/IntrospectionResultTest.php`

**Interfaces:**
- Produces `IntrospectionClientInterface::introspect(string): IntrospectionResult` and `IntrospectionResult::hasScope(string): bool`, `audienceIncludes(string): bool`.

- [ ] **Step 1: Write failing tests** for raw and `workspace/`-prefixed audience acceptance and a nonmatching audience rejection.
- [ ] **Step 2: Run** `./scripts/stc.ps1 test --filter=IntrospectionResultTest` and confirm the missing class failure.
- [ ] **Step 3: Implement** immutable result/actor contracts and disabled-by-default configurable settings.
- [ ] **Step 4: Run** the same filter and confirm it passes.

### Task 2: Correct, cached introspection transport

**Files:**
- Create: `app/Application/GrandpaSson/HttpIntrospectionClient.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Feature/GrandpaSson/HttpIntrospectionClientTest.php`

**Interfaces:**
- Consumes `IntrospectionClientInterface` and `config('grandpasson.*')`.
- Produces active/scopes/audiences from a broker response, cached by SHA-256 fingerprint no longer than `exp` and the configured cache window.

- [ ] **Step 1: Write failing HTTP-fake tests** asserting the broker receives form fields `client_id`, `client_secret`, and `token`, invalid responses fail closed, and cache expiry is bounded by `exp`.
- [ ] **Step 2: Run** `./scripts/stc.ps1 test --filter=HttpIntrospectionClientTest` and confirm failure because the transport is absent.
- [ ] **Step 3: Implement** form-post transport and cache behavior without logging tokens.
- [ ] **Step 4: Run** the same filter and confirm it passes.

### Task 3: Inbound authentication and authorization

**Files:**
- Modify: `app/Http/Middleware/AuthenticateApiKeyOrSanctum.php`
- Create: `app/Http/Middleware/EnforceGrandpaSsonWorkspaceAud.php`
- Modify: `app/Http/Middleware/ResolveTenantEnvironment.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/GrandpaSson/InboundAuthTest.php`

**Interfaces:**
- Consumes an active `GrandpaSsonActor`, resolved tenant/environment attributes, and HTTP method.
- Produces unchanged native auth while requiring read scope for safe methods, write scope for mutation methods, and a matching raw/prefixed audience for broker actors.

- [ ] **Step 1: Write failing feature tests** for disabled-mode 401, native API-key compatibility, good raw audience, good prefixed audience, missing write scope, and an audited redacted denial.
- [ ] **Step 2: Run** `./scripts/stc.ps1 test --filter=InboundAuthTest` and confirm failure before adding middleware.
- [ ] **Step 3: Implement** actor creation, safe audit summary, tenant access bypass limited to broker actors pending audience enforcement, and route middleware ordering.
- [ ] **Step 4: Run** the filter and confirm all authorization outcomes pass.

### Task 4: Browser delegated identity

**Files:**
- Create: `app/Application/GrandpaSson/GrandpaSsonIdentityProvider.php`
- Create: `app/Application/GrandpaSson/GrandpaSsonTenantMappingService.php`
- Create: `app/Infrastructure/Persistence/Eloquent/GrandpaSsonTenantMapping.php`
- Create: `database/migrations/*_create_grandpasson_tenant_mappings_table.php`
- Create: `app/Http/Controllers/Auth/GrandpaSsonLoginController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/GrandpaSson/BrowserLoginTest.php`

**Interfaces:**
- Produces `redirectToLogin(Request): RedirectResponse` and `handleCallback(Request): RedirectResponse`; `GrandpaSsonTenantMappingService` resolves a broker tenant id plus broker role/groups into the explicitly linked local tenant and `owner`/`admin`/`viewer` membership.
- Uses the configured confidential RP client, session `state`, and immediate `/session/exchange` form post.

- [ ] **Step 1: Write failing HTTP-fake tests** for redirect state, state mismatch, expired/broker-error exchange, successful email-linked local identity, and membership granted only through an explicit local tenant mapping.
- [ ] **Step 2: Run** `./scripts/stc.ps1 test --filter=BrowserLoginTest` and confirm failure because the controller is absent.
- [ ] **Step 3: Implement** state generation/verification and fail-closed exchange; never read shared broker tables.
- [ ] **Step 4: Run** the filter and confirm it passes.

### Task 5: Documentation, tracking, and full verification

**Files:**
- Create: `docs/integrations/grandpasson.md`
- Create: `docs/architecture/grandpasson-cross-repo.md`
- Modify: `STATUS.md`
- Modify: `BACKLOG.md`

- [ ] **Step 1: Document** both client registrations, local Docker host networking, flags, 30-second default cache/revocation window, and issue links for all three scopes.
- [ ] **Step 2: Update** PR13 and the open-question state without claiming live verification before #116 lands.
- [ ] **Step 3: Run** `./scripts/stc.ps1 test` and inspect the clean worktree.
- [ ] **Step 4: Commit** implementation and docs on `codex/pr13-grandpasson-seam`, open the linked PR, request review, and merge only when its Definition of Done is met.

## Self-review

- §15.1 configuration, §15.3 browser code flow, §15.4 cached inbound introspection/audience enforcement, §15.6 cross-repo tracking, and §15.7 fail-closed traps map to Tasks 1–5.
- The plan contains no placeholders, no runtime-process dependency, and no live-broker dependency.
- `IntrospectionResult`, `GrandpaSsonActor`, and middleware names are consistent across tasks.
