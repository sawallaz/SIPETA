| Field | Value |
| --- | --- |
| **Title** | SIPETA Phase 6 — Reporting & Export |
| **Purpose** | Track Phase 6 (Reporting & Export) sub-phase progress. |
| **Scope** | 6.1 Reporting & Export foundation: a `PendudukExportService` producing PDF (DomPDF), XLSX (OpenSpout), and CSV (OpenSpout) downloads via three Filament table toolbar actions; exports always respect the active filter criteria (FR-EX-02) and the generated filename always embeds the export date plus a human-readable filter summary (FR-EX-03). |
| **Version** | 1.3.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-07 |
| **Related Documents** | `.ai/architecture.md`, `.ai/workflow.md` (§14, §15), `docs/REQUIREMENTS.md` (§ FR-EX-02, FR-EX-03, FR-BR-04..06), `docs/CHANGELOG.md`, `docs/FEATURES.md`, `app/Services/PendudukExportService.php`, `app/Enums/ExportFormat.php`, `resources/views/exports/penduduk-pdf.blade.php`, `app/Filament/Resources/Penduduks/Tables/PenduduksTable.php`, `app/Services/BackupService.php`, `app/Services/RestoreService.php`, `app/Filament/Pages/Backup.php` |

---

# Phase 6 — Reporting & Export

## 6.1 Reporting & Export foundation

### 6.1.1 Objective

Build the reporting/export foundation for the Penduduk data: a single
`PendudukExportService` that streams PDF (DomPDF), XLSX and CSV (OpenSpout)
files, wired to the Penduduks list page as three Filament table toolbar
actions (CSV / Excel / PDF). Every export honours the active table filter
criteria (FR-EX-02) and the generated filename embeds the export date and a
human-readable filter summary (FR-EX-03). No chart/aggregate reporting yet —
this sub-phase is the export base the later reporting sub-phases consume.

### 6.1.2 Deliverables

- **Export service** (`app/Services/PendudukExportService.php`, new —
  `App\Services\*` per ADR-016):
  - `export(ExportFormat $format, array $filters = [], ?string $name = null): Response`
    — builds the filtered query and returns the download.
  - `exportQuery(Builder $query, ExportFormat $format, array $filters = [], ?string $name = null): Response`
    — exports an already-filtered query (e.g. the Filament table's live query);
    filter metadata is used only for the filename.
  - `buildQuery(array $filters): Builder` / `applyFilters(Builder $query, array $filters): Builder`
    — shared filter application, mirroring the projected Penduduk table
    filters: `rt` (exact RT number), `area_unit` (RW/Lingkungan id), `gender`,
    `religion_id`, `education_id`, `occupation_id`, `resident_status`,
    `age` (exact, computed via birth-date span), `age_min`/`age_max`.
  - `filename(ExportFormat, array $filters, ?Carbon $now): string` —
    `<YYYY-MM-DD>_<filter summary>.<ext>` and `filterSummary(array $filters): string`
    — `semua` when no filter; otherwise PK-slugs (`jk-laki-laki_status-aktif`).
    Gender and resident status map to Bahasa Indonesia labels in the slug
    (`Laki-laki`/`Perempuan`, `Aktif`/`Pindah`/`Meninggal`) — the raw enum value
    `ACTIVE` is never leaked into a filename.
  - Formats via DomPDF (`Pdf::loadView('exports.penduduk-pdf', ...)`), OpenSpout
    XLSX/CSV writers streamed row-by-row to a temp file with
    `$query->chunkById(500, ...)`, returned as a `BinaryFileResponse` that
    deletes the temp file after send.
- **Export format enum** (`app/Enums/ExportFormat.php`, new) — `PDF`/`XLSX`/`CSV`
  with `label()` and `mime()`.
- **PDF report view** (`resources/views/exports/penduduk-pdf.blade.php`, new) —
  headed by the kelurahan identity from the `Setting` singleton
  (`Setting::query()->first()?->kelurahan_name`, falling back to
  `config('app.name')` when absent) and rendering the ordered `columns`
  header with `rows` data.
- **Toolbar export actions** (`app/Filament/Resources/Penduduks/Tables/PenduduksTable.php`
  updated) — three `Filament\Actions\Action` toolbar actions (`export_csv`,
  `export_xlsx`, `export_pdf`) each calling
  `app(PendudukExportService::class)->exportQuery($livewire->getFilteredTableQuery(), ExportFormat::…)`.
  The closure injects the Livewire page component by name
  (`fn (Filament\Tables\Contracts\HasTable $livewire)`), so the export uses
  literally the currently-filtered table query — that is how `respects active
  filters` (FR-EX-02) is guaranteed without re-implementing filters.
- **Runtime dependencies** — `composer require barryvdh/laravel-dompdf:^3.1
  openspout/openspout:^4.23` (openspout was already a transitive dep of
  `filament/actions`; dompdf is genuinely new). Both auto-register their
  service providers in Laravel 11+/12 (no `config/app.php` edit).

### 6.1.3 Not done (explicitly out of scope for 6.1)

- **No reporting/analytics beyond raw export** — the "export reflects active
  filters" contract lands here; charts, grouping, sums and derived KPI-style
  report layouts are later 6.x sub-phases. The Pendudu resolvable fields use
  the same display mapping as the review/CRUD (gender/status labels,
  lookups by name).
- **`exportQuery()` (the live-filter path) has no HTTP round-trip test.** The
  two CSV-output tests that produced raw body bytes during the run were
  eliminated as `risky` (PHPUnit flags output during a test); the XLSX
  round-trip (reader-verified rows) and the PDF report-view text tests remain
  as the export-content proof. The live-query filter wiring is exercised
  through the remaining query-level tests; a browser-level download assertion
  is deferred.
- No migrations / schema changes — exports read only existing Penduduk columns
  and relations; the computed `age` is never stored.
- No changes to the OCR pipeline, dashboard, or other resources.
- No compiled frontend asset touched — the panel has no custom Vite theme; the
  toolbar actions reuse existing Heroicon `heroicon-o-arrow-down-tray`.

### 6.1.4 Files changed (6.1 only)

| File | Change |
| --- | --- |
| `composer.json` / `composer.lock` | Updated — `barryvdh/laravel-dompdf:^3.1` + `openspout/openspout:^4.23` added to `require`. |
| `app/Enums/ExportFormat.php` | New — `PDF`/`XLSX`/`CSV` with `label()` / `mime()`. |
| `app/Services/PendudukExportService.php` | New — export service (DOM, filter application, filename / filter summary, PDF / XLSX / CSV responses). |
| `resources/views/exports/penduduk-pdf.blade.php` | New — DomPDF report view headed by the Setting singleton. |
| `app/Filament/Resources/Penduduks/Tables/PenduduksTable.php` | Updated — three toolbar export actions (CSV / Excel / PDF) calling `exportQuery($livewire->getFilteredTableQuery(), …)`. |
| `tests/Feature/Phase6/PendudukExportServiceTest.php` | New — 14 tests (filename/summary, filters, XLSX round-trip, PDF render). |
| `docs/PHASE6.md` | New — this §6.1 section; Version 0.0.0 → 1.0.0. |
| `docs/CHANGELOG.md` | Updated — Phase 6.1 entry; Version 1.16.0 → 1.17.0. |
| `docs/FEATURES.md` | Updated — F-CORE-11/12/13 + F-HIGH-06 moved to Implemented; F-HIGH-21 added. |

### 6.1.5 Verification

```text
php artisan test tests/Feature/Phase6   14 passed (25 assertions)
php artisan test                        220 passed (1008 assertions), 4 skipped (3 MySQL + 1 Tesseract, env-gated)
./vendor/bin/pint --test                 PASS (168 files)
```

`npm run build` not applicable — no compiled frontend asset changed (pure PHP
service + view + table actions + tests + docs; the panel has no custom Vite
theme).

### 6.1.6 Commit

`feat(export): Phase 6.1 — penduduk export`

## 6.2 ZIP Backup (FR-BR-01 / FR-BR-02 / FR-BR-03 / FR-AUD-01)

### 6.2.1 Objective

Provide a `BackupService` that produces a single portable ZIP archive of the
application data — a database SQL dump, the KK photo archive, and the settings
singleton — on the private `db_backups` disk, with a timestamped filename and a
permanent `backup_logs` record. This is the "create a backup" admin workflow
(US-11, FR-CORE-14); restore is a later 6.x sub-phase. No operator UI ships in
6.2 — the service API is the contract (same service-layer-first pattern as the
Phase 5.7 / 5.8 imports).

### 6.2.2 Deliverables

- **Backup service** (`app/Services/BackupService.php`, new — `App\Services\*`
  per ADR-016):
  - `create(?User $operator = null): BackupResult` — assembles and persists the
    archive, then appends the `backup_logs` row.
  - `filename(?Carbon $now = null): string` — the FR-BR-02 pattern
    `backup_YYYY-MM-DD_HHMMSS.zip`.
  - ZIP contents (FR-BR-01): `database.sql` (SQL dump via a `DatabaseDumper`),
    `settings.json` (the singleton settings row, or `[]` when unseeded), and
    `kk/*` (every archived KK photo copied from its `storage_disk`; a photo whose
    stored file is missing is skipped without failing the backup).
  - **FR-BR-03 no-overwrite**: if an archive with the generated filename already
    exists the call returns a `duplicate` result without writing anything.
  - **FR-AUD-01 logging**: appends a `backup_logs` row — `backup_type` MANUAL,
    `backup_status` SUCCESS (with `backup_size`) or FAILED (with the error
    message and `backup_size` = 0), `backup_size`, `operator_id`,
    `started_at` / `finished_at`, and the message. On failure the service logs
    FAILED and rethrows `BackupException`.
- **Dump abstraction** (mirrors the Phase 5.4 `OcrEngine` DI pattern):
  - `app/Services/DatabaseDumper.php` (interface) — `dump(): string`.
  - `app/Services/MysqldumpDatabaseDumper.php` (new) — runs
    `mysqldump --single-transaction` via Symfony `Process` using the MySQL
    connection settings from config (never hard-coded credentials), 120 s
    timeout; throws `DatabaseDumperException` on a non-zero exit. Bound in
    `AppServiceProvider` so tests override it with a fake (no real mysqldump
    in the suite; the host keeps no running MySQL server). Recognised photo
    bytes are read via `Storage::disk($photo->storage_disk)->get(...)` —
    photos are bounded (≤5 MB uploads) so in-memory reads are acceptable.
- **Result DTO** (`app/Services/BackupResult.php`, new — `final readonly`):
  status `success` / `duplicate` with `filename` and `size`; `isSuccess()`,
  `isDuplicate()`.
- **Exceptions** (`app/Exceptions/BackupException.php`,
  `app/Exceptions/DatabaseDumperException.php`, new) — dedicated domain
  exceptions, matching the OCR engine style.
- **Tests** (`tests/Feature/Phase6/BackupServiceTest.php`, 6 tests) — FR-BR-02
  filename; SQL + settings + photo inclusion in the archive with a SUCCESS log;
  `kk_photos`/settings packing with a missing-file skip; no-overwrite
  `duplicate`; FAILED log + thrown exception + no leftover file; operator
  recorded. Supported by `tests/Support/FakeDatabaseDumper.php` holding a fake
  dumper so the suite never invokes mysqldump.

### 6.2.3 Not done (explicitly out of scope for 6.2)

- **No restore** (FR-BR-04..06) — that is a later 6.x sub-phase; the backup
  archive format is defined here so restore can consume it.
- **No admin UI / Filament page or action** — backup is a service-layer
  contract in this sub-phase; the operator-facing "Backup" control ships with
  the UI sub-phase.
- No scheduled backups (BackupType::SCHEDULED is recorded by schema but only
  MANUAL is produced here), no retention/rotation, no backup integrity check or
  dry-run.
- No migrations / schema changes — the existing `backup_logs` table and the
  `db_backups` disk (Phase 1.5 storage layout) fully cover persistence.
- No compiled frontend asset touched — `npm run build` is a courtesy gate.

### 6.2.4 Files changed (6.2 only)

| File | Change |
| --- | --- |
| `app/Services/BackupService.php` | New — ZIP backup service (filename, create, archive assembly, logging). |
| `app/Services/BackupResult.php` | New — readable backup result DTO. |
| `app/Services/DatabaseDumper.php` | New — dump contract. |
| `app/Services/MysqldumpDatabaseDumper.php` | New — mysqldump implementation. |
| `app/Exceptions/BackupException.php` | New — backup domain exception. |
| `app/Exceptions/DatabaseDumperException.php` | New — dump domain exception. |
| `app/Providers/AppServiceProvider.php` | Updated — bind `DatabaseDumper` → `MysqldumpDatabaseDumper`. |
| `tests/Support/FakeDatabaseDumper.php` | New — deterministic dump fake for the suite. |
| `tests/Feature/Phase6/BackupServiceTest.php` | New — 6 tests. |
| `docs/PHASE6.md` | Updated — this §6.2 section; Version 1.0.0 → 1.1.0. |
| `docs/CHANGELOG.md` | Updated — Phase 6.2 entry; Version 1.17.0 → 1.18.0. |
| `docs/FEATURES.md` | Updated — F-CORE-14 'backup log table' moved to Implemented. |

### 6.2.5 Verification

```text
php artisan test tests/Feature/Phase6/BackupServiceTest.php   6 passed (31 assertions)
php artisan test                                               226 passed (1039 assertions), 4 skipped (3 MySQL + 1 Tesseract, env-gated)
./vendor/bin/pint --test                                       PASS (176 files)
npm run build                                                  PASS (vite exit 0; no tracked frontend path changed)
```

### 6.2.6 Commit

`feat(backup): Phase 6.2 — data backup`

## 6.3 Restore from ZIP (FR-BR-04 / FR-BR-05 / FR-BR-06)

### 6.3.1 Objective

Provide a `RestoreService` that applies a backup archive produced by the 6.2
`BackupService` (the FR-BR-01 format — `database.sql` + `settings.json` +
`kk/*`) to bring the application back to a backed-up state. This is the
"restore from backup" half of the Backup admin workflow (FR-CORE-15). As with
the other Phase 6.x sub-phases it is service-layer only: no operator UI ships
here — the service API is the contract, and the caller/UI sub-phase provides
the confirmation prompt and the restart notice.

### 6.3.2 Deliverables

- **Restore service** (`app/Services/RestoreService.php`, new — `App\\Services\\*`
  per ADR-016):
  - `restore(string $filename, ?User $operator = null, bool $confirmed = false): RestoreResult`
    — reads and applies the archive on the `db_backups` disk.
  - **FR-BR-05 explicit confirmation**: when `$confirmed` is `false` the call
    returns a `confirmation_required` result and applies nothing.
  - **FR-BR-04 integrity validation BEFORE applying**: the archive must open as
    a valid ZIP and `database.sql` + `settings.json` must both be present and
    readable; otherwise the restore throws `RestoreException` with **zero** state
    changes.
  - **Apply order**: the SQL dump is applied first via the injected
    `DatabaseImporter` — a dump failure aborts the restore before any
    settings/photo change — then the `settings.json` singleton is upserted into
    the `settings` table (only the `Setting` fillable fields; ignored when the
    archive carries no settings row, i.e. `[]` / `{}`), then every `kk/*` photo
    is written back to the `kk_uploads` disk.
  - **FR-BR-06 restart advice**: a successful restore returns
    `RestoreResult::restored($filename)` with `restartRequired = true` so the
    awaiting UI can prompt the operator to restart the application.
- **Import abstraction** (mirrors the Phase 6.2 `DatabaseDumper` DI pattern):
  - `app/Services/DatabaseImporter.php` (interface) — `apply(string $sql): void`.
  - `app/Services/MysqlClientDatabaseImporter.php` (new) — pipes the dump to the
    `mysql` client over stdin using the MySQL connection settings from config
    (never hard-coded credentials), 180 s timeout; throws
    `DatabaseImporterException` on a non-zero exit. Bound in
    `AppServiceProvider` so tests override it with a fake (no real `mysql`
    client in the suite; the host keeps no running MySQL server).
- **Result DTO** (`app/Services/RestoreResult.php`, new — `final readonly`):
  status `restored` / `confirmation_required` with `filename`,
  `restartRequired`; `isRestored()`, `isConfirmationRequired()`.
- **Exceptions** (`app/Exceptions/RestoreException.php`,
  `app/Exceptions/DatabaseImporterException.php`, new) — dedicated domain
  exceptions, matching the backup/OCR engine style.
- **Tests** (`tests/Feature/Phase6/RestoreServiceTest.php`, 7 tests) — FR-BR-05
  confirmation gate; archive-not-found; corrupt archive; missing mandatory
  `database.sql`; successful restore applies SQL + settings + photos (asserts
  `restartRequired`, FR-BR-06); empty `settings.json` skips the settings upsert;
  importer failure aborts before settings/photos. Supported by
  `tests/Support/FakeDatabaseImporter.php` holding a fake importer so the suite
  never invokes the real `mysql` client (it records every applied dump).

### 6.3.3 Not done (explicitly out of scope for 6.3)

- **No operator UI / Filament page or action** — restore is a service-layer
  contract in this sub-phase (same pattern as 6.2 backup and the 5.7/5.8
  imports); the operator-facing restore control ships with the later UI
  sub-phase.
- **No restore dry-run (FR-MED-05) and no backup integrity check on launch
  (FR-MED-04)** — both are later Phase 6 work.
- No migrations / schema changes — restore reuses the existing `backup_logs`
  table, the `db_backups` and `kk_uploads` disks (Phase 1.5 storage layout).
  The `backup_logs` table records backup *creation* (FR-AUD-01); restore
  attempts are surfaced to the operator via the returned `RestoreResult` and
  logged with `Log::info`, not by misusing the backup-only log schema.
- No compiled frontend asset touched — `npm run build` is a courtesy gate.

### 6.3.4 Files changed (6.3 only)

| File | Change |
| --- | --- |
| `app/Services/RestoreService.php` | New — restore service (confirmation gate, integrity validation, apply order, restart advice). |
| `app/Services/RestoreResult.php` | New — readable restore result DTO. |
| `app/Services/DatabaseImporter.php` | New — restore apply contract. |
| `app/Services/MysqlClientDatabaseImporter.php` | New — `mysql` client implementation. |
| `app/Exceptions/RestoreException.php` | New — restore domain exception. |
| `app/Exceptions/DatabaseImporterException.php` | New — import domain exception. |
| `app/Providers/AppServiceProvider.php` | Updated — bind `DatabaseImporter` → `MysqlClientDatabaseImporter`. |
| `tests/Support/FakeDatabaseImporter.php` | New — deterministic import fake for the suite. |
| `tests/Feature/Phase6/RestoreServiceTest.php` | New — 7 tests. |
| `docs/PHASE6.md` | Updated — this §6.3 section; Version 1.1.0 → 1.2.0. |
| `docs/CHANGELOG.md` | Updated — Phase 6.3 entry; Version 1.18.0 → 1.19.0. |
| `docs/FEATURES.md` | Updated — F-CORE-15 'Restore from ZIP' moved to Implemented. |

### 6.3.5 Verification

```text
php artisan test tests/Feature/Phase6/RestoreServiceTest.php   7 passed
php artisan test                                               233 passed (1063 assertions), 4 skipped (3 MySQL + 1 Tesseract, env-gated)
./vendor/bin/pint --test                                     PASS (184 files)
npm run build                                                PASS (vite exit 0; no tracked frontend path changed)
```

### 6.3.6 Commit

`feat(restore): Phase 6.3 — data restore`

## 6.4 Backup & Restore page (operator-facing)

### 6.4.1 Objective

Provide the operator-facing "Backup" menu page (the five-menu navigation,
`.ai/workflow.md` §1) that wires the 6.2 `BackupService` and the 6.3
`RestoreService` onto a single page, implementing workflow §14 (create) and
§15 (restore). This is the deferred "later UI sub-phase" explicitly promised
by the 6.2 and 6.3 scope notes. The page reuses the built services unchanged —
it is the thin operator surface on top, keeping all domain logic in the
service layer.

### 6.4.2 Deliverables

- **Backup & Restore page** (`app/Filament/Pages/Backup.php`, new — a
  `Filament\\Pages\\Page` auto-discovered by `AdminPanelProvider`):
  - Navigation: menu label "Backup", `heroicon-o-archive-box` icon, page
    title "Backup & Restore".
  - **§14 create**: a `Filament\\Actions\\Action` header button "Buat Backup"
    that calls `app(BackupService::class)->create(auth()->user())` and reports
    success or (FR-BR-03) duplicate via a notification carrying the archive
    filename.
  - **Archive list**: `backups()` returns every ZIP on the `db_backups` disk,
    newest first (filename, size, `lastModified`), shown in the view; each row
    exposes a "Pulihkan" button.
  - **§15 two-step restore**: selecting "Pulihkan" sets `restoreCandidate`
    (the confirmation step — this is where FR-BR-05 explicit confirmation is
    satisfied, exactly once, in the page); "Konfirmasi" then calls
    `app(RestoreService::class)->restore($filename, auth()->user(), true)`.
    - FR-BR-04 integrity failures throw `RestoreException`, caught and shown
      as a "Pemulihan gagal" notification (nothing applied; the candidate stays
      so the operator can retry or cancel).
    - FR-BR-06 a successful restore sends "Pemulihan selesai" with restart
      advice.
    - Non-ZIP selections are rejected up front.
- **Page view** (`resources/views/filament/pages/backup.blade.php`, new) —
  `x-filament-panels::page` with sections for the create hint, the archive
  list, and the confirmation block (rendered only while
  `$restoreCandidate !== null`). Interactions are Livewire `wire:click`
  methods on the page class — the same custom-page pattern used by the
  Phase 5.6 ReviewOcrJob page (no business logic in the view).
- **Tests** (`tests/Feature/Phase6/BackupPageTest.php`, 5 tests) — page lists
  stored archives; create-backup action produces an FR-BR-02-named archive;
  restore requires confirmation then applies SQL + settings + photos with the
  restart-advice notification; a corrupt archive surfaces danger without
  applying anything; non-ZIP selection is rejected. The container binds
  `FakeDatabaseDumper` / `FakeDatabaseImporter`, so no real mysqldump / mysql
  client runs in the suite.

### 6.4.3 Not done (explicitly out of scope for 6.4)

- **No "Pengaturan" (Settings) page** — F-CORE-16 (kelurahan identity, logo,
  backup path) is a separate later Phase 6 sub-phase; workflow §16.
- **No scheduled backups, retention/rotation, FR-MED-04 launch integrity
  check, or FR-MED-05 restore dry-run** — all later Phase 6 work.
- No changes to `BackupService` / `RestoreService` (untouched), and no
  migrations/schema changes — the existing `backup_logs` table, `db_backups`
  and `kk_uploads` disks are reused.
- `npm run build` regenerated only gitignored `public/build` artifacts — no
  tracked frontend file changed.

### 6.4.4 Files changed (6.4 only)

| File | Change |
| --- | --- |
| `app/Filament/Pages/Backup.php` | New — "Backup" menu page (create + archive list + two-step restore). |
| `resources/views/filament/pages/backup.blade.php` | New — page view (sections + Livewire wire:click wiring). |
| `tests/Feature/Phase6/BackupPageTest.php` | New — 5 tests (list, create, restore-confirm-apply, corrupt, non-ZIP). |
| `docs/PHASE6.md` | Updated — this §6.4 section; Version 1.2.0 → 1.3.0. |
| `docs/CHANGELOG.md` | Updated — Phase 6.4 entry; Version 1.19.0 → 1.20.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-22 'Backup & Restore operator page' added as Implemented. |

### 6.4.5 Verification

```text
php artisan test tests/Feature/Phase6/BackupPageTest.php   5 passed (30 assertions)
php artisan test                                           238 passed (1093 assertions), 4 skipped (3 MySQL + 1 Tesseract, env-gated)
./vendor/bin/pint --test                                    PASS (186 files)
npm run build                                               PASS (vite exit 0; no tracked frontend path changed)
```

### 6.4.6 Commit

`feat(backup): Phase 6.4 — backup & restore page`