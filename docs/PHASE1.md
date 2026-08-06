| Field | Value |
| --- | --- |
| **Title** | SIPETA Phase 1 — Foundation + Hardening |
| **Purpose** | Record the completed Phase 1 foundation setup (Laravel 12 + Filament 4 + MySQL) and the Phase 1.5 foundation-hardening deliverables. |
| **Scope** | Phase 1: git init, Laravel scaffold, MySQL config, Filament install, GitHub remote. Phase 1.5: MySQL user fix, `scripts/` suite, Pint, IDE Helper, storage disks. |
| **Version** | 2.0.0 |
| **Status** | Complete |
| **Last Updated** | 2026-08-05 |
| **Related Documents** | `.ai/decisions.md`, `.ai/roadmap.md`, `.ai/filament.md`, `.ai/laravel.md`, `.ai/database.md`, `docs/REQUIREMENTS.md`, `docs/CHANGELOG.md` |

---

# Phase 1 — Foundation

## 1. Objective

Deliver a stable foundation for SIPETA according to Phase 1 of the roadmap:
- Initialize Git repository.
- Create Laravel 12 project.
- Configure MySQL per ADR-004.
- Install Filament 4 per ADR-002.
- Set up project structure and baseline documentation.
- Push to GitHub.

**Out of scope for Phase 1:** Tauri configuration, domain migrations, resources, Phase 2 CRUD work.

---

## 2. Environment Summary

| Item | Value |
| --- | --- |
| PHP | 8.4.24 (cli) |
| Laravel | 12.64.0 |
| Composer | 2.8.8 |
| Filament | v4.12.5 |
| Livewire | v3.8.3 |
| Database | MySQL (MariaDB 11.8.8) |
| App URL | http://localhost |
| Environment | local |

---

## 3. Database Configuration

MySQL was chosen per ADR-004 and project documentation.

| Parameter | Value |
| --- | --- |
| DB_CONNECTION | mysql |
| DB_HOST | 127.0.0.1 |
| DB_PORT | 3306 |
| DB_DATABASE | sipeta |
| DB_USERNAME | sipeta_app |
| DB_PASSWORD | (empty) |

### Verification

- `php -m | grep pdo_mysql` → `pdo_mysql` present.
- `mysqladmin ping -h 127.0.0.1 -P 3306 -u sipeta_app` → alive.
- `new PDO('mysql:host=127.0.0.1;port=3306;dbname=sipeta;charset=utf8mb4', 'sipeta_app', '')` → connection OK.
- `php artisan migrate --force` → base migrations succeeded.

### Base Tables Created

- users
- cache / cache_locks
- failed_jobs
- job_batches / jobs
- migrations
- password_reset_tokens
- sessions

---

## 4. Filament 4 Installation

Filament was pinned to v4 per ADR-002 and `.ai/filament.md`.

| Item | Value |
| --- | --- |
| Package | filament/filament |
| Version | v4.12.5 |
| Install command | `composer require filament/filament:"^4.0"` |
| Setup command | `php artisan filament:install -n` |
| Assets published | public/css, public/fonts, public/js under `public/` |
| Routes registered | filament exports/imports routes present |

### Verification

- `composer show filament/filament` → v4.12.5.
- `php artisan about` → Filament v4.12.5 listed under Packages.
- `php artisan route:list | grep filament` → routes present.

---

## 5. Git and GitHub Setup

| Item | Value |
| --- | --- |
| Branch | main |
| Remote | origin → https://github.com/sawallaz/SIPETA.git |
| Pushed | yes |
| Commit | `8a571b8` — feat: install Laravel 12 + Filament 4 and configure MySQL |

### Notable Files Committed

- `.env.example` — updated to MySQL template.
- `.gitignore` — includes `/vendor`, `/node_modules`, `.env`, `/storage/logs/*`, `/bootstrap/cache/*.php`.
- `composer.json` / `composer.lock` — Filament v4 constraint added.
- `README.md` — project readme.
- `docs/archive/README.laravel-scaffold.md` — archived scaffold docs.
- `public/css`, `public/fonts`, `public/js` — Filament published assets.

### Files Explicitly Not Committed

- `.env` — contains local secrets; listed in `.gitignore`.
- `vendor/` — dependency directory; listed in `.gitignore`.

---

## 6. Test Results

| Command | Result |
| --- | --- |
| `php artisan about` | Laravel 12.64.0, Database: mysql, Filament: v4.12.5 |
| `php artisan test` | 2 passed, 0 failed |
| `composer validate` | `./composer.json is valid` |

---

## 7. Known Issues and Residual Risks

| Issue | Status | Mitigation |
| --- | --- | --- |
| `public/storage` not linked | Open | Run `php artisan storage:link` when file uploads are needed. |
| No admin user yet | Open | Create via Filament user factory/seeder in Phase 2. |
| GitHub HTTPS auth | Unverified | Push succeeded from this environment; confirm CI/CD token if automated deploys are added later. |
| SQLite file removed | Done | `database/database.sqlite` removed to avoid confusion. |

---

## 8. Phase 1 Checklist

| Task | Status |
| --- | --- |
| Git init + commit docs | ✅ |
| Laravel 12 scaffold | ✅ |
| MySQL configured | ✅ |
| `.env` / `.env.example` updated | ✅ |
| Base migrations run | ✅ |
| Filament 4 installed | ✅ |
| `.gitignore` corrected | ✅ |
| GitHub remote configured | ✅ |
| Push to GitHub | ✅ |
| Verification commands pass | ✅ |
| Phase 1 Report authored | ✅ |

---

## 9. Next Steps

**Do not start Phase 2 until explicitly approved.**

Planned Phase 2 work:
- Domain migrations (`kartu_keluarga`, `penduduk`, `settings`, `backup_logs`).
- Models and relationships.
- Filament Resources per `.ai/filament.md`.
- Search, filters, export, dashboard widgets.

---

## 1.5 Foundation Hardening

### 1.5.1 Objective

Harden the Phase 1 foundation with lightweight, feature-supporting tooling — fast to finish for KKN, not enterprise scaffolding. Per the project review, the following were intentionally **deferred**: PHPStan, GitHub Actions CI, `scripts/release.sh`, and full `/tmp` clone-from-scratch verification.

**Out of scope for Phase 1.5:** Tauri configuration, domain migrations, CRUD, OCR application code, reports, release/installer pipelines.

### 1.5.2 Deliverables

#### 1.5.2.1 MySQL application user (`scripts/db-user.sql`)
- Idempotent `CREATE USER IF NOT EXISTS 'sipeta_app'@'localhost'` + `GRANT ALL PRIVILEGES ON sipeta.*` + `FLUSH PRIVILEGES`.
- Uses `IF NOT EXISTS` and **no `DROP USER`**, so it never breaks an already-running application.
- Placeholder password `CHANGE_ME` (never a real credential in version control).
- `.env.example` `DB_HOST` changed `127.0.0.1` → `localhost`. `.env` left untouched (it works as-is).

#### 1.5.2.2 `scripts/` helper suite

| Script | What it does |
| --- | --- |
| `setup.sh` | `composer run setup` + `php artisan storage:link` (idempotent). |
| `verify.sh` | `composer validate --no-check-publish`, `php artisan optimize:clear`, and `php -l` over the codebase; prints PASS/FAIL. |
| `backup.sh` | `mysqldump` of `sipeta` → `storage/app/backups/sipeta_<timestamp>.sql.gz`; credentials read from `.env` via `artisan tinker`. Includes a commented cron example. |
| `clean.sh` | `optimize:clear` + `composer dump-autoload` + `npm cache verify` — deliberate NON-destructive (no `git clean -fdx`). |
| `db-user.sql` | See 1.5.2.1. |

All bash scripts are executable (`chmod +x`) with `#!/usr/bin/env bash` and `set -euo pipefail`.

#### 1.5.2.3 Laravel Pint
- `pint.json` added with `{ "preset": "laravel" }`. Pint was already present in `require-dev`.

#### 1.5.2.4 IDE Helper
- `composer require --dev barryvdh/laravel-ide-helper` (^3.7).
- Generated `_ide_helper.php` and `.phpstorm.meta.php`; both added to `.gitignore` (not committed).

#### 1.5.2.5 Storage configuration
- Three private local disks added to `config/filesystems.php`: `kk_uploads`, `ocr_temp`, `db_backups`.
- Directories `storage/app/kk_uploads`, `storage/app/ocr_temp`, `storage/app/backups` created.
- `storage/app/.gitignore` extended to track the directories while ignoring their contents.

#### 1.5.2.6 Documentation
- `docs/CHANGELOG.md` — Phase 1.5 "Added" + "Deferred" entries.
- `README.md` — Developer Utilities table corrected, Storage Layout section, Deferred Tooling section.
- This report.

### 1.5.3 Deferred (deliberate, for KKN speed)

| Item | Reason |
| --- | --- |
| PHPStan | Postponed until the app is nearly complete; avoids churn on static-analysis warnings during feature work. |
| GitHub Actions CI | Not needed for a single-developer KKN project; focus stays on features. |
| `scripts/release.sh` | Premature — no release, desktop app, or installer yet. |
| `/tmp` clone verification | Deferred to pre-deployment; current setup already validated via install/migrate/test/validate/push. |

### 1.5.4 Verification

Run before considering the phase complete:

```bash
bash scripts/verify.sh      # composer validate + optimize:clear + php -l → PASS/FAIL
composer validate           # must pass
php artisan optimize:clear  # must succeed
php artisan storage:link    # idempotent, must not error
```

(Results recorded at review time — see commit message / PR notes.)

### 1.5.5 Notes / Residual Risks

- `backup.sh` requires the `mysqldump` client to be installed on the operator PC; the script reports a clear error if credentials cannot be read from `.env`.
- `db-user.sql` must be run manually as MySQL root before the app is pointed at `'sipeta_app'@'localhost'`; it does not alter the currently working `127.0.0.1` setup.
- IDE Helper files are generated artifacts and are gitignored; they regenerate via `php artisan ide-helper:generate` after `composer install`.
- Automatic DB backup is documented (cron example) but **not** installed — it belongs on the specific operator PC at deployment time.
