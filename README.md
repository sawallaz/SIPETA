# SIPETA

SIPETA (Sistem Informasi Pendataan Penduduk Kelurahan Tanete) adalah aplikasi pendataan penduduk berbasis Kartu Keluarga untuk membantu arsip digital, pencarian data, pelaporan, backup, dan proses input berbantuan OCR.

Status saat ini: Phase 1 — fondasi aplikasi. Fitur desktop, OCR produksi, installer Windows, dan skema database domain akan dikerjakan pada fase berikutnya sesuai roadmap proyek.

## Technology Stack

- Laravel 12
- Filament 4
- PHP 8.3+
- MySQL 8
- Vite / Tailwind CSS
- Tauri 2 desktop wrapper (planned, Phase 7)
- Tesseract OCR 5 integration (planned)
- Inno Setup installer (planned, Phase 7)

## Requirements

Development machine requirements:

- PHP 8.3 or newer with extensions required by Laravel
- Composer 2
- Node.js and the JavaScript package manager selected by this repository
- MySQL 8 for SIPETA development database
- Git
- Tesseract OCR 5 for later OCR phases

The application targets a single local administrator/operator and Indonesian-language UI workflows.

## Installation

Clone the repository, configure local environment values, then run the developer setup script:

```bash
cp .env.example .env
# Edit .env and configure DB_CONNECTION=mysql plus valid MySQL credentials.
bash scripts/setup.sh
```

The setup script intentionally does not overwrite an existing `.env`. Database migration requires a valid local MySQL database configuration.

## Developer Utilities

| Script | Purpose |
|---|---|
| `bash scripts/setup.sh` | Install PHP/JS dependencies, run `composer run setup`, and link the public storage directory. |
| `bash scripts/verify.sh` | Validate Composer metadata, clear framework caches (`optimize:clear`), and run `php -l` syntax checks across the codebase. Prints PASS/FAIL. |
| `bash scripts/backup.sh` | Dump the `sipeta` database to `storage/app/backups/sipeta_<timestamp>.sql.gz` (credentials read from `.env`). Includes a commented cron example. |
| `bash scripts/clean.sh` | Clear Laravel caches and regenerate the Composer autoloader — deliberately NON-destructive (no `git clean`). |
| `cat scripts/db-user.sql` | Idempotent MySQL `CREATE USER IF NOT EXISTS 'sipeta_app'@'localhost'` + `GRANT` script (run once as root). |

These scripts are for developers, not for the final village operator workflow.

## Storage Layout

SIPETA uses dedicated private local disks (configured in `config/filesystems.php`):

| Disk | Path | Purpose |
|---|---|---|
| `kk_uploads` | `storage/app/kk_uploads` | Uploaded Kartu Keluarga scans (Phase 3+). |
| `ocr_temp` | `storage/app/ocr_temp` | Temporary OCR working files (Phase 5+). |
| `db_backups` | `storage/app/backups` | Database dumps produced by `scripts/backup.sh`. |

Their contents are gitignored; the directories themselves are tracked so deployments start with the right structure.

## Deferred Tooling (deliberate, for KKN speed)

The following were intentionally **not** added in Phase 1.5:

- **PHPStan** — postponed until the app is nearly complete to avoid churn on static-analysis warnings during feature work.
- **GitHub Actions CI** — not needed for a single-developer KKN project; focus stays on features.
- **`scripts/release.sh`** — premature (no release, desktop app, or installer yet).
- **Full `/tmp` clone-from-scratch verification** — deferred to pre-deployment.

## Architecture Overview

SIPETA follows the approved architecture documents in `.ai/` and product documents in `docs/`:

- Laravel handles the application backend and domain logic.
- Filament provides the admin interface.
- MySQL stores production data with integrity constraints.
- OCR assists data entry but never saves directly without operator review.
- Desktop packaging is deferred until the web application foundation is stable.

Authoritative project rules:

1. `.ai/decisions.md`
2. `.ai/hermes.md`
3. `docs/REQUIREMENTS.md`

## Roadmap

High-level milestones:

1. Phase 1 — Git, Laravel 12, Filament 4, developer utilities, and project foundation.
2. Phase 2 — Database foundation for KK and Penduduk.
3. Phase 3 — Core data entry and validation.
4. Phase 4 — Search, filters, dashboard, and exports.
5. Phase 5 — Backup and restore.
6. Phase 6 — OCR-assisted input.
7. Phase 7 — Desktop packaging and installer.

See `.ai/roadmap.md` for the detailed phased plan.

## Testing

Run the current verification suite:

```bash
bash scripts/verify.sh
```

At Phase 1 this includes Composer validation, Laravel status checks, Filament status checks when installed, PHPUnit tests, and frontend build verification when JavaScript dependencies are installed.

## License

SIPETA project code is prepared for the Kelurahan Tanete KKN deliverable. The underlying Laravel framework and third-party dependencies retain their respective open-source licenses.
