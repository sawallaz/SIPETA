| Field | Value |
|---|---|
| **Title** | SIPETA Changelog |
| **Purpose** | Record every meaningful change to the project, following the Keep a Changelog format. |
| **Scope** | All phases of SIPETA development, including documentation, architecture, and code. |
| **Version** | 1.5.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-06 |
| **Related Documents** | `docs/REQUIREMENTS.md`, `docs/FEATURES.md`, `.ai/roadmap.md`, `.ai/decisions.md`, `.ai/hermes.md` |

---

# SIPETA Changelog

All notable changes to SIPETA are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added (Phase 2 — 2026-08-05)
- **Phase 2.3 Seeders** (8 idempotent seeders + `DatabaseSeeder` orchestration):
  - `SettingsSeeder` (singleton `id=1`), `ReligionSeeder` (7 rows), `EducationSeeder` (10 rows), `OccupationSeeder` (12 rows), `RegionSeeder` (3 area_units + 19 rts), `AdminUserSeeder` (single admin per ADR-005), `ResidentStatusSeeder` and `RelationshipStatusSeeder` (obviously-fake demo fixtures covering the enum value sets).
- **Phase 2.4 Eloquent Models** (13 models + `User` extension):
  - `app/Models`: `Setting`, `Religion`, `Education`, `Occupation`, `AreaUnit`, `Rt`, `KartuKeluarga`, `OcrJob`, `Penduduk`, `KkAnggota`, `KkPhoto`, `BackupLog`, `AuditLog`. All set `$table` explicitly; `Penduduk` adds `scopeActive()` and a computed `getAgeAttribute()` (never stored); relations, casts, and `audits()` morph relation added.
  - `app/Enums` (11): `Gender`, `BloodType`, `MaritalStatus`, `FamilyRelation`, `ResidentStatus`, `OcrJobStatus`, `OcrOutcome`, `KkAnggotaStatus`, `BackupType`, `BackupStatus`, `PhotoType`.
  - `database/factories` (13): `PendudukFactory` builds the full FK chain (kk → rts/lookup masters).
- **Phase 2.5 Database Verification** (4 test suites, 28 tests, 181 assertions in `tests/Feature/Phase2/`):
  - `SchemaTest` — 13 tables, unique constraints, approved indexes, the two audit-fix indexes, FK rules (RESTRICT vs SET NULL), and **no soft-delete columns**.
  - `DatabaseBehaviourTest` — FK enforcement, unique rejection, RESTRICT blocks KK delete, SET NULL cascade, KK re-issue membership history preserved, append-only `backup_logs` / `audit_logs`.
  - `RelationAndScopeTest` — relations, `scopeActive`, computed `age`, enum casts round-trip, invalid enum throws.
  - `MigrationLifecycleTest` — `migrate:fresh` produces all tables, `migrate:reset` removes them, re-migrate restores, seeder idempotency.
- **Two additive index migrations** (audit-fix findings recorded in `docs/PHASE2.md` §2.3 Audit):
  - `2026_08_05_101300_add_started_at_index_to_backup_logs_table` — `INDEX (started_at)` on `backup_logs`.
  - `2026_08_05_101400_add_kk_id_index_to_ocr_jobs_table` — `INDEX (kk_id)` on `ocr_jobs` (explicit; FK auto-creates it too).

### Changed
- `.ai/database.md` — rewritten (v1.1.0 → v1.2.0) to the 13-table schema-of-record; `resident_status` = ACTIVE/PINDAH/MENINGGAL; lookup masters as FKs; explicit soft-delete policy (none); Eloquent relationship reference updated with explicit `kk_id` FKs.
- `.ai/architecture.md` — §7 Database Philosophy now lists 13 tables, append-only logs, `kk_anggota` history, no-soft-delete; §21 notes `audit_logs` implemented in Phase 2.2.
- `docs/FEATURES.md` — F-CORE-01 status Implemented; F-CORE-07 status values corrected to ACTIVE/PINDAH/MENINGGAL; F-CORE-16 phase corrected to Phase 6.

### Notes
- No released migration was edited. The two new index migrations are purely additive.
- `resident_status` values are Indonesian (ACTIVE / PINDAH / MENINGGAL), not the earlier draft's MOVED / DECEASED.
- Verification was performed against a throwaway SQLite database (`php artisan test` uses `sqlite :memory:`); MySQL is the production engine but is not running in this environment.

### Documentation
- `docs/PHASE2.md` — Phase 2 consolidated record (§2.1 Architecture, §2.2 Finalization verdict: COMPLETE, §2.3 Audit verdict: NOT COMPLETE at audit time, gaps later closed).

### Added (Phase 3.1 — Filament Foundation — 2026-08-05)
- **Filament admin panel scaffold** (`app/Providers/Filament/AdminPanelProvider.php`, registered in `bootstrap/providers.php`):
  - `panel id 'admin'`, `path 'admin'`, `->login()` enabled, default panel.
  - Temporary SIPETA branding: `->brandName('SIPETA')`, primary color `Color::Amber`.
  - Navigation skeleton: `navigationGroups(['Kependudukan', 'Master Data'])` (placeholder groups; no Resources/CRUD yet — those are Phase 3.2+).
  - Filament auto-provides the Dashboard page and the `admin/login` route.
- **Phase 3.1 smoke test** (`tests/Feature/Phase3/AdminPanelTest.php`): asserts `/admin/login` returns 200 and the `filament.admin.auth.login` route is registered.

### Notes
- No Resources, Pages, Widgets, CRUD, migrations, models, or OCR code were created (out of scope for 3.1).
- Admin user was NOT created: MySQL is unreachable in this environment (no server running) and the task forbids database writes. The Phase 2 `AdminUserSeeder` remains the idempotent source for the admin user when the DB is available.
- `php artisan test` runs against SQLite `:memory:` (per `phpunit.xml`); full suite is 32 passed / 185 assertions (28 Phase-2 + 2 default + 2 Phase-3.1).
- Not pushed — awaiting approval per task instruction ("Wait for my next instruction").

### Added (Phase 3.2.1 — KK Resource — 2026-08-05)
- **KartuKeluarga Filament Resource** (`app/Filament/Resources/KartuKeluargas/`):
  - `KartuKeluargaResource` references the existing `App\Models\KartuKeluarga` model — no model class, factory, or migration was created or modified.
  - Standard pages auto-generated: `ListKartuKeluargas`, `CreateKartuKeluarga`, `EditKartuKeluarga`.
  - Form schema (`KartuKeluargaForm`) and table schema (`KartuKeluargasTable`) are empty scaffolds — **forms/tables intentionally NOT built** (out of scope for 3.2.1).
  - Registered automatically through Filament's `discoverResources()` in `AdminPanelProvider` (no provider edit required).
  - Note: Filament auto-pluralized the resource *namespace* to `KartuKeluargas`; the class is `KartuKeluargaResource`, the route slug is `kartu-keluarga`, and the nav label resolves to "Kartu Keluarga".

### Notes
- No models, migrations, factories, Penduduk features, or OCR code were created or modified (out of scope for 3.2.1).
- No new tests added (none required by the task). `getRelations()` returns an empty array — no `Penduduk` relation leaked into the resource.
- `php artisan test`: 32 passed / 185 assertions; 3 env-gated MySQL tests skipped (`RUN_MYSQL_TESTS` unset). Routes `admin/kartu-keluarga*` registered and the panel boots.

### Added (Phase 4.3 — Dashboard Charts — 2026-08-06)
- **Three Chart.js chart widgets** (`app/Filament/Widgets/`, each extending `Filament\Widgets\ChartWidget` and eager-rendered like the KPI cards):
  - `PendudukPerRTChart` — bar chart of active residents per RT (every RT shown, zero-padded, natural number order "RT 01" before "RT 10").
  - `PendudukPerLingkunganChart` — bar chart of active residents per Lingkungan / RW, attributed through `penduduk.rt_id → rts.area_unit_id` in one aggregate join query (every area unit shown, zero-padded).
  - `PendudukPerPekerjaanChart` — doughnut chart of active residents per occupation (only occupations with ≥ 1 active resident; count desc, ties broken by name).
- **All charts count active residents only** (`resident_status = ACTIVE`), per `docs/REQUIREMENTS.md` §5.5 "Charts reflect active residents only".
- **Dashboard page** (`app/Filament/Pages/Dashboard.php`) — `getWidgets()` now mounts the three charts after `SipetaStatsOverview`.
- **Phase 4.3 tests** (`tests/Feature/Phase4/DashboardChartTest.php`, 2 tests): chart headings render on `/admin`; chart labels/values match a controlled database including zero-padded RTs and the active-only filter (PINDAH / MENINGGAL residents excluded).

### Notes
- No migrations, models, resources, or prior-phase code changed. Chart type choice (bar for RT/Lingkungan, doughnut for Pekerjaan) is a presentation decision documented in `docs/PHASE4.md` §4.3.
- Verification: `php artisan test` 93 passed / 437 assertions / 3 skipped; `./vendor/bin/pint --test` PASS (122 files). `npm run build` not applicable — no frontend asset, Tailwind class, or Blade view added (Chart.js ships with Filament).
- Known doc debt (not part of this phase): Phase 4.1 / 4.2 changelog entries and the `F-CORE-02` status flip were not recorded at the time.

### Added (Phase 4.4 — Recent Activity Widget — 2026-08-06)
- **Recent Activity widget** (`app/Filament/Widgets/RecentActivityWidget.php`): eager-rendered dashboard widget listing the 5 newest Kartu Keluarga and the 5 newest Penduduk from existing `created_at` data only, merged into one newest-first list. Each row shows an icon (`heroicon-o-home-modern` / `heroicon-o-user`), a title ("KK {kk_number}" / full name), a subtitle (address / "NIK {nik}"), a human-readable Bahasa Indonesia timestamp (`->locale('id')->diffForHumans()`), and a link to the record's edit page via the existing resource routes (`KartuKeluargaResource::getUrl('edit')` / `PendudukResource::getUrl('edit')`).
- **Widget Blade view** (`resources/views/filament/widgets/recent-activity-widget.blade.php`): wraps `x-filament::section`; renders Filament's `x-filament::empty-state` ("Belum ada aktivitas") when there is no data. The panel does not compile arbitrary Tailwind utilities (no custom Vite theme), so the list is styled by a small scoped `<style>` block (`fi-wi-recent-activity-*`, light + dark variants).
- **Dashboard page** (`app/Filament/Pages/Dashboard.php`) — `RecentActivityWidget` appended last in `getWidgets()`; no prior widget reordered.
- **Phase 4.4 tests** (`tests/Feature/Phase4/RecentActivityWidgetTest.php`, 4 tests): widget renders on `/admin`; empty state when no data; exactly the 5 newest KK listed (newest first, `/edit` URLs); exactly the 5 newest Penduduk listed (newest first, `/edit` URLs).

### Notes
- Read-only: no observers, no audit log, no activity table, no migrations, no seeders. Everything reads `kartu_keluarga.created_at` / `penduduk.created_at`.
- Verification: `php artisan test` 97 passed / 458 assertions / 3 skipped; `./vendor/bin/pint --test` PASS (124 files). `npm run build` not applicable — no frontend build asset changed (no Tailwind classes, no `resources/css` / `resources/js` / `vite.config` edits).

## [1.3.0] - 2026-08-03

### Added (Phase 1.5 — 2026-08-05)
- `scripts/` helper suite: `setup.sh`, `verify.sh`, `backup.sh`, `clean.sh`, and `db-user.sql`.
  - `setup.sh` runs `composer run setup` + `storage:link`.
  - `verify.sh` runs `composer validate`, `optimize:clear`, and `php -l` over the codebase (prints PASS/FAIL).
  - `backup.sh` dumps the `sipeta` DB to `storage/app/backups/sipeta_<ts>.sql.gz`; includes a commented cron example.
  - `clean.sh` clears caches + regenerates autoloader + `npm cache verify` — deliberately NON-destructive (no `git clean -fdx`).
- `pint.json` (Laravel preset) for `laravel/pint` (already in require-dev).
- `barryvdh/laravel-ide-helper` (dev) + generated `_ide_helper.php` / `.phpstorm.meta.php` (gitignored).
- Three storage disks in `config/filesystems.php`: `kk_uploads`, `ocr_temp`, `db_backups` (private local disks), with matching directories and `.gitignore` rules.
- `.env.example` `DB_HOST` changed `127.0.0.1` → `localhost`; `.env` left untouched (it works as-is).

### Deferred (deliberately, for KKN speed)
- PHPStan — postponed until the app is nearly complete (avoids churn on style/static warnings during feature work).
- GitHub Actions CI — not needed for a single-developer KKN project; focus stays on features.
- `release.sh` — premature (no release, desktop app, or installer yet).
- Full `/tmp` clone-from-scratch verification — deferred to pre-deployment.

### Architecture
- Tauri + PHP embedded runtime strategy decided.
- MySQL bundled installer (silent mode) strategy decided.
- Application and data folders separated to support upgrade safety.

## [1.3.0] - 2026-08-03

### Policy
- **Identity clarified.** `.ai/hermes.md` states that "Hermes" is the project AI constitution/policy name, while the active execution model is the **SIPETA development agent operating through the default Mixture of Agents (MoA)**.
- **Database Configuration Policy (ADR-028).** Do not assume database names, usernames, or passwords. Use `DB_*` environment variables only. Never hardcode credentials. `.env` is never committed. If the database or application user does not exist, create them during Phase 1 only after the configuration values are known.
- **Commit Safety Gate (ADR-029).** Before every commit: run tests when available, verify Laravel boots, verify no secrets are staged, review `git diff --check`, review the staged file list. Never commit `.env`, `vendor/`, `node_modules/`, `storage/logs/*`, `bootstrap/cache/*`, credentials, private keys, tokens, dumps, or local database files. Retain Laravel-required `.gitignore` placeholder files.
- **Tesseract Phase 1 exception (ADR-027).** `tesseract-ocr` and `tesseract-ocr-ind` may be installed as system-level prerequisites during Phase 1, but no OCR application code, configuration, workflow, storage, tests, or behavior documentation may be written until the OCR phase.

### Architecture
- ADR-025 — Tauri integration deferred until Phase 7 (Desktop Packaging). Phase 1 does not include Tauri configuration. Tauri CLI binary may already be installed on the developer machine, but `cargo tauri init`, `src-tauri/`, `tauri.conf.json`, and Inno Setup scripts are forbidden until Phase 7 is explicitly started.
- ADR-026 — Phase-Scoped Installation Policy. Only install software required for the current phase. Future-phase dependencies require confirmation before installation.

### Changed
- `.ai/roadmap.md` — versioned to 1.2.0. Phase 1 trimmed (no Tauri config). Phase 7 (Desktop Packaging) explicitly marked as deferred; Tauri is configured only after the web application is stable.
- `.ai/decisions.md` — versioned to 1.3.0; appended ADRs 025–029. ADR-003 and ADR-018 augmented with "integration deferred" notes.
- `.ai/hermes.md` — versioned to 1.4.0. Identity note added. §21 renamed to §22 and extended with §21 Database Configuration Policy and §22.15 Commit Safety Gate. Tauri references in §4 and §16 annotated with deferral note. Golden Rules expanded.
- `.ai/installation.md` — versioned to 1.1.0. Added §0 Status — DEFERRED banner and §15 Pre-Phase-7 Checklist.
- `.ai/architecture.md` — versioned to 1.2.0. Added §0 Two-Layer Architecture and Phase 7 notes.

### Notes
Phase 1 work in progress. Tauri CLI binary is installed on the developer machine at `~/.cargo/bin/cargo-tauri` (acceptable per ADR-025 — it is a developer tool, not a project file). No Tauri project files exist in the repository.

## [1.2.0] - 2026-08-03

### Documentation
- **AI Execution Environment** added to `.ai/hermes.md` §21 as the single source of truth for all available Skills, Tools, and MCP servers.
- Listed 20 Hermes Skills across 8 categories (Autonomous AI, Planning, Debugging, Documentation, OCR, Architecture, GitHub, Research).
- Listed 16 built-in tools (terminal, file, browser, web, code_execution, vision, image_gen, computer_use, memory, todo, context_engine, session_search, clarify, delegation, cronjob, skills).
- Listed 6 MCP servers (github, filesystem, context7, playwright, sequential-thinking, agentrouter).
- Defined AI Capability Priority: project docs → Context7 → Skills → MCP → built-in tools → manual implementation.
- Defined Skill Selection Policy: analyze → check existing Skills → check MCP → use highest-level → manual only if no fit.
- Defined per-MCP usage rules (Context7, Filesystem, GitHub, Playwright, Sequential Thinking, AgentRouter).
- Documentation rule: new Skills/Tools/MCPs must be appended to `.ai/hermes.md` §21 before use.

### Architecture
- ADR-021 — `.ai/hermes.md` §21 is the authoritative AI execution environment reference.
- ADR-022 — All MCP calls route through `mcporter`; no direct MCP access.
- ADR-023 — AI Capability Priority formalised.
- ADR-024 — Context7 consulted before using any external library (Laravel, Filament, Tauri, PHP packages, Rust crates).

### Changed
- `.ai/hermes.md` — replaced legacy AI Workflow / Context7 / Playwright Policy sections with consolidated §21 AI Execution Environment. Versioned to 1.2.0.
- `.ai/decisions.md` — versioned to 1.2.0; appended ADRs 021–024.

## [1.1.0] - 2026-08-03

### Documentation
- `docs/REQUIREMENTS.md` defined.
- `docs/FEATURES.md` defined.
- `docs/USER_GUIDE.md` defined.
- `docs/BACKLOG.md` defined.
- `.ai/ocr.md` defined.
- Metadata block standardized across all `.ai/` documents.

### Architecture
- Tauri + PHP embedded runtime strategy decided.
- MySQL bundled installer (silent mode) strategy decided.
- Application and data folders separated to support upgrade safety.

## [1.0.0] - 2026-08-03

### Added
- Project bootstrapped.
- Documentation baseline created under `.ai/` and `docs/`.
- Hermes constitution (`hermes.md`) established.
- Architecture baseline (`architecture.md`) established.
- Database baseline (`database.md`) established.
- Workflow baseline (`workflow.md`) established.
- UI/UX baseline (`ui-ux.md`) established.
- Coding standards (`coding.md`) established.
- Testing standards (`testing.md`) established.
- Deployment guide (`deployment.md`) established.
- Roadmap (`roadmap.md`) established.
- Architectural Decisions (`decisions.md`) — 20 ADRs recorded.
- Business rules (`project-rules.md`) established.

### Notes
This is a documentation-only release. The first code release will be tagged `1.4.0` once Phase 1 (Foundation) is complete.

---

## Types of Changes

This changelog uses the following categories:

- **Added** — new features.
- **Changed** — changes in existing functionality.
- **Deprecated** — soon-to-be removed features.
- **Removed** — now-removed features.
- **Fixed** — bug fixes.
- **Security** — vulnerability fixes.
- **Documentation** — documentation-only changes.
- **Architecture** — non-code architectural changes.
- **Policy** — binding process or governance changes.

## Versioning Policy

- **MAJOR** version — incompatible schema changes, breaking data migrations.
- **MINOR** version — new features, schema additions that are backward-compatible.
- **PATCH** version — bug fixes, non-functional changes.

## Operational Notes

- Every phase completion adds an entry.
- Every release is tagged in Git.
- The current version is reflected in the application's `Settings` page.
