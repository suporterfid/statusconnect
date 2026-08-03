# AGENTS.md

Guidance for AI agents working in this repository.

## Read first

1. **Hard constraints** — `.cursor/rules/always-apply-hard-constraints.mdc` (always on) and `CLAUDE.md`
2. **Product spec** — `docs/specs/statusconnectinitialspecandbuildplan.md`
3. **Deployment / cron** — `docs/deployment/`

File-scoped Cursor rules under `.cursor/rules/` cover backend layering, scheduler/SSRF, status page, and tests.

## Quick facts

| Item | Value |
|------|--------|
| Stack | Laravel 12 + Vue 3 SPA (`frontend/`) + Blade public status page |
| Runtime target | Shared hosting: PHP 8.2+, MySQL 8.0+, minute cron, docroot `public/` |
| Async model | MySQL claim leases + `monitor:*` artisan — no queue workers |
| Dev | Docker only via `scripts/stc.ps1` (Windows) or `scripts/stc.sh` (Unix) |
| Issues | Track work in GitHub `suporterfid/statusconnect` |

## Do not

- Require Redis, brokers, Horizon, Octane, or long-running workers
- Install PHP/Composer/Node on the host
- Put Eloquent models in `app/Models/` (use `app/Infrastructure/Persistence/Eloquent/`)
- Bypass outbound SSRF / DNS-pinned HTTP for target URLs
- Commit Packagist mirror configuration
