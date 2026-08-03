# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) and Cursor agents when working with code in this repository.

## What this is

StatusConnect is an open-source, multi-tenant uptime monitor and public status page designed to run on commodity PHP + MySQL shared hosting. It features a Laravel 12 backend (modular/DDD-ish layering), Blade-rendered public status page, and Vue 3 SPA for the operator interface. Cron fires artisan commands every minute to claim due monitors from MySQL, execute parallel checks via `curl_multi`, evaluate incidents, and generate status rollups.

Authority spec: `docs/specs/statusconnectinitialspecandbuildplan.md`.

## Hard constraints (non-negotiable)

1. **Must stay deployable on commodity shared hosting**: PHP 8.2+, MySQL 8.0+, minute cron, docroot `public/`.
   - NO dependency on an always-on process, daemon, or external broker (no Redis, Memcached, RabbitMQ, Horizon, Octane, Reverb, supervisor workers).
   - MySQL-backed claim leases + minute cron (`monitor:check-due`, `monitor:rollup`, `monitor:maintenance`).
   - `QUEUE_CONNECTION=sync`, `CACHE_STORE=file` or `database`, `SESSION_DRIVER=database` or `file`.
   - `exec()`, `shell_exec()`, `proc_open()`, `popen()` MUST NOT be used at runtime.
2. **Development is Docker-only**: PHP, Composer, Node, npm MUST NOT be run on the host. Everything runs through containers via `scripts/stc.sh` / `scripts/stc.ps1`.
3. **Track all work on GitHub issues**: Every PR unit MUST be linked to an issue in `suporterfid/statusconnect` and closed upon completion with verification evidence.
4. **Licence & provenance**: MIT licensed. Code adapted from `TaskConnect` carries source attribution comments.

## Dev environment

```powershell
# Windows
.\scripts\stc.ps1 up
.\scripts\stc.ps1 bootstrap
```

```bash
# Linux / macOS
./scripts/stc.sh up
./scripts/stc.sh bootstrap
```

Services & Ports:
- App: http://localhost:8070
- Mailpit UI: http://localhost:8035
- Target service (controllable check target): http://localhost:8095
- MySQL: 3308

### Common commands

| Command | Purpose |
|---|---|
| `stc test` | Run PHPUnit test suite inside app container |
| `stc artisan <cmd>` | Run artisan command |
| `stc composer <cmd>` | Run composer in app container |
| `stc npm <cmd>` | Run npm in node container |
| `stc shell` | Shell in app container |
| `stc release` | Build production release zip into dist/ |

## Layering

- `app/Domain/` — Framework-free business logic (Monitoring, Incidents, Outbound, StatusPage, Secrets, Shared).
- `app/Application/` — Orchestration, transaction boundaries (Checks, Incidents, Notifications, Rollups, StatusPages, etc.).
- `app/Infrastructure/` — Concrete persistence (`Persistence/Eloquent/`), HTTP clients (`HttpClient/`), TCP, DNS.
- `app/Http/` — Thin controllers (`Controllers/Api/V1/`, `Controllers/Public/`), Resources, Middleware.
