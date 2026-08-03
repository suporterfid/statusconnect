# StatusConnect

> Uptime monitoring and public status pages for the cPanel your grandpa never gave up.

StatusConnect is an open-source, multi-tenant uptime monitor and public status page that runs on commodity PHP + MySQL shared hosting.

## Development

Development is strictly Docker-only. Do not run PHP, Composer, Node, or npm on your host machine.

### Quick Start

On Linux / macOS:
```bash
./scripts/stc.sh up
./scripts/stc.sh bootstrap
./scripts/stc.sh test
```

On Windows (PowerShell):
```powershell
.\scripts\stc.ps1 up
.\scripts\stc.ps1 bootstrap
.\scripts\stc.ps1 test
```

Services exposed:
- **App**: http://localhost:8070
- **Mailpit UI**: http://localhost:8035
- **Target Service**: http://localhost:8095
- **MySQL**: localhost:3308

## Specification & Build Plan

Refer to `docs/specs/statusconnectinitialspecandbuildplan.md` as the sole authority for project requirements and architecture.

## License

MIT. See [LICENSE](LICENSE).