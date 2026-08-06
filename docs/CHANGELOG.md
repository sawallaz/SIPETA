| Field | Value |
|---|---|
| **Title** | SIPETA Changelog |
| **Purpose** | Record every meaningful change to the project, following the Keep a Changelog format. |
| **Scope** | All phases of SIPETA development, including documentation, architecture, and code. |
| **Version** | 1.16.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-07 |
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

### Added (Phase 4.5 — Quick Actions Widget — 2026-08-06)
- **Quick Actions widget** (`app/Filament/Widgets/QuickActionsWidget.php`): eager-rendered dashboard widget (`$isLazy = false`) exposing four static shortcuts to the existing resource routes — `Tambah Kartu Keluarga` → `KartuKeluargaResource::getUrl('create')`, `Tambah Penduduk` → `PendudukResource::getUrl('create')`, `Data Kartu Keluarga` → `KartuKeluargaResource::getUrl('index')`, `Data Penduduk` → `PendudukResource::getUrl('index')`. Each action carries label, Bahasa Indonesia description, heroicon, and URL. No queries, no new resources/pages/migrations/models/controllers/Livewire components.
- **Widget Blade view** (`resources/views/filament/widgets/quick-actions-widget.blade.php`): wraps `x-filament::section` (heading "Aksi Cepat"); four link cards in a responsive CSS grid, styled by a scoped `<style>` block (`fi-wi-quick-actions-*`, light + dark variants) since the panel does not compile arbitrary Tailwind utilities.
- **Dashboard page** (`app/Filament/Pages/Dashboard.php`) — `QuickActionsWidget` mounted AFTER `RecentActivityWidget` in `getWidgets()`; no prior widget reordered.
- **Phase 4.5 tests** (`tests/Feature/Phase4/QuickActionsWidgetTest.php`, 4 tests): widget renders on `/admin` ("Aksi Cepat"); exactly the four actions exposed with the expected labels; all four visible on the dashboard; every action URL matches its registered Filament route (`filament.admin.resources.{kartu-keluargas,penduduks}.{create,index}`, asserted via `Route::has` + `route()` equality).

### Notes
- Static link grid only: no custom buttons, forms, modals, or permission logic; all four shortcuts reuse the existing KK / Penduduk resource routes.
- Verification: `php artisan test` 101 passed / 481 assertions / 3 skipped; `./vendor/bin/pint --test` PASS (126 files). `npm run build` not applicable — no frontend build asset changed.

### Added (Phase 4.6 — Dashboard Polish — 2026-08-06)
- **Operator-first widget ordering** (`app/Filament/Pages/Dashboard.php`): Quick Actions now top (most frequent workflows), then KPI cards, then the three charts, Recent Activity last (reference feed). Previously Quick Actions was mounted last.
- **Full-width responsive layout**: every dashboard widget now sets `columnSpan = 'full'`. Filament's dashboard grid defaults to two columns at `columnSpan = 1`, so all six widgets previously rendered cramped half-width; full width gives the KPI cards, wide 19-RT bar charts, action-card grid, and activity list room to breathe and wrap on narrow screens. Applied to `SipetaStatsOverview`, the three charts, `RecentActivityWidget`, and `QuickActionsWidget`.
- **Chart descriptions (Bahasa Indonesia)**: "Jumlah penduduk aktif di setiap RT", "Jumlah penduduk aktif di setiap RW / lingkungan", and "Sebaran penduduk aktif menurut pekerjaan" — clarifying the active-only scope per `docs/REQUIREMENTS.md` §5.5.
- **Consistent chart colors**: both bar charts (RT, Lingkungan) now render in the brand amber (`#f59e0b`, matching `Color::Amber`) instead of Chart.js's default palette; the Pekerjaan doughnut gets an explicit categorical 12-color palette (Tailwind 500-scale, amber-anchored) covering the 12 seeded occupations with a white slice border.
- **Consistent KPI status colors**: `Penduduk Meninggal` `gray` → `danger`, completing the status family (Aktif `success`, Pindah `warning`, Meninggal `danger`).
- **Layout test** (`tests/Feature/Phase4/DashboardLayoutTest.php`, 3 tests): locks in the operator-first widget order, asserts every widget's `columnSpan` is `'full'`, and confirms the polished dashboard still renders.

### Notes
- Polish only — no new widgets, charts, resources, migrations, models, controllers, or Livewire components; no widget views or Vite assets changed. KPI values, chart data, quick-action routes, and recent-activity queries are unchanged. Loading/empty states already existed (all widgets `$isLazy = false`, charts + recent activity have empty states); Indonesian number formatting already present.
- Verification: `php artisan test` 104 passed / 491 assertions / 3 skipped; `./vendor/bin/pint --test` PASS (127 files). `npm run build` not applicable — no frontend build asset changed.

### Added (Phase 5.1 — OCR Upload Foundation — 2026-08-06)
- **Upload service** (`app/Services/KkDocumentUploadService.php`, new — first `App\Services\*` business-logic class per ADR-016):
  - `upload(UploadedFile $file, ?User $operator = null): OcrJob` — validates, stores, and registers the upload.
  - `validate(UploadedFile $file): void` — throws `ValidationException` on rejection; nothing is persisted on failure (no file, no job row).
  - `rules(): array` — static reusable rules for the future upload UI.
- **Upload validation** (NFR-SEC-05; `.ai/ocr.md` §4.1): accepted types JPG/JPEG/PNG enforced by extension (`mimes`) AND content MIME sniff (`mimetypes`, rejects disguised files); maximum size 5 MB (`max:5120`).
- **Secure storage**: files stored on the private local `kk_uploads` disk (`storage/app/kk_uploads`, `visibility = private`) under a UUID filename; the client's original filename is never used for storage.
- **Upload status handling**: accepted uploads create an `ocr_jobs` row with `status = PENDING`, `kk_id = null` (KK record does not exist at upload time), `operator_id`, `started_at`, and `source_image_hash` (SHA-256 of the stored file — the seed for FR-OCR-05 duplicate detection in a later sub-phase). No schema changes: the existing `ocr_jobs` table covers upload recording.
- **Phase 5.1 tests** (`tests/Feature/Phase5/KkDocumentUploadServiceTest.php`, 6 tests): valid JPEG accepted (PENDING job, operator, hash, no kk), valid PNG accepted, upload stored correctly on the private disk (content hash round-trip, `visibility = private`, root under `storage/app/kk_uploads`), invalid extension rejected, oversized file rejected, and wrong content with an allowed extension rejected (all rejections leave zero job rows and an empty disk).

### Notes
- OCR extraction, parsing, resolution validation, duplicate warning, upload UI, queue workers, and temp-file GC are explicitly NOT part of 5.1 — they land in later 5.x sub-phases (see `docs/PHASE5.md` §5.1.3).
- Verification: `php artisan test` 110 passed / 514 assertions / 3 skipped; `./vendor/bin/pint --test` PASS (129 files). `npm run build` not applicable — no frontend build asset changed.

### Added (Phase 5.2 — OCR Processing Pipeline — 2026-08-06)
- **Pipeline service** (`app/Services/OcrProcessingService.php`, new):
  - `start(OcrJob $job): OcrJob` — rejects non-PENDING jobs (`InvalidArgumentException`, nothing persisted); transitions the job to the `PROCESSING` runtime state; loads the source image from the private `kk_uploads` disk; validates processing prerequisites (file exists, non-empty/readable, JPEG/PNG signature).
  - On any prerequisite failure the job is persisted as `FAILED` with `error_message` and `finished_at`, then `OcrProcessingException` (new, `app/Exceptions/`) re-surfaces to the caller.
- **Status transitions**: PENDING → PROCESSING → FAILED (when processing cannot continue). `PROCESSING` is added to the `OcrJobStatus` PHP enum as a **runtime state only** — the Phase 2 column constraint (SQLite CHECK / MySQL ENUM) predates the value, so it cannot be persisted yet (verified: `CHECK constraint failed`). `OcrJobStatus::persistable()` lists the five persistable statuses and the factory now samples from it so fixtures never write the illegal value. Widening the column is a deliberate future schema change, out of scope here.
- **Phase 5.2 tests** (`tests/Feature/Phase5/OcrProcessingServiceTest.php`, 7 tests): pending → processing, DB row stays PENDING while processing, missing image fails the job, unreadable image fails, non-image content fails, non-PENDING job rejected without state change, and FAILED persisted with failure details.
- No OCR recognition, Tesseract, AI vision, parsing, queue workers, new migrations, schema changes, resources, or dashboard changes — Phase 4 and 5.1 remain frozen.

### Notes
- Verification: `php artisan test` 117 passed / 535 assertions / 3 skipped; `./vendor/bin/pint --test` PASS (132 files). `npm run build` not applicable — no frontend build asset changed.

### Added (Phase 5.3 — Image Preprocessing — 2026-08-06)
- **Preprocessing stage** (`app/Services/ImagePreprocessor.php`, new — GD-based; GD + exif are the only image-processing libraries in the repo, so no new dependency was introduced):
  - `preprocess(string $bytes, string $sourcePath): PreprocessResult` — decodes and validates the image, corrects EXIF orientation (tags 2–8 via `imageflip` / `imagerotate`), converts to grayscale (`IMG_FILTER_GRAYSCALE`, `.ai/ocr.md` §4.2 step 1), downscales past the 4000×4000 cap (`.ai/ocr.md` §4.1), measures sampled mean brightness, stores a lossless PNG on the private `ocr_temp` disk, and logs a `pipeline_stage=preprocess` line (`.ai/ocr.md` §9).
  - **Resolution gate** (deferred from 5.1 per `docs/PHASE5.md` §5.1.3): minimum 800×600 enforced — below it the job is persisted `FAILED`; maximum 4000×4000 downscaled proportionally. Undecodable content (valid signature, corrupt body) is rejected at the same gate.
  - **Result tracking** (`app/Services/PreprocessResult.php`, new readonly DTO, in-memory only): processed path, width/height, sampled mean brightness, skew angle (`null` — auto-deskew not implemented), ordered applied transforms (`exif_orientation`, `grayscale`, `resize`), non-blocking quality warnings, and duration.
  - **Quality warnings** (`.ai/ocr.md` §4.10): brightness outside the acceptable 100–200 band is recorded as a warning; processing still continues.
- **Pipeline integration** (`app/Services/OcrProcessingService.php`): `start()` now runs the preprocessing stage after loading the source image; `preprocessResult()` exposes the last result. Preprocessing failures share the same `FAILED` persistence path as load failures.
- **Phase 5.3 tests** (`tests/Feature/Phase5/ImagePreprocessorTest.php`, 7 tests): valid flow (PROCESSING + full result metadata), output generated on `ocr_temp` (PNG signature + round-trip dimensions), corrupt image rejected → FAILED, below-minimum resolution → FAILED with no output written, oversized image downscaled with aspect preserved, EXIF orientation 6 applied (800×600 → 600×800), dark image records a brightness warning but still processes.
- `tests/Feature/Phase5/OcrProcessingServiceTest.php` fixtures raised to 800×600 and `ocr_temp` faked: the new resolution gate would otherwise reject the old 10×10 fakes before the 5.2 transitions under test could run.

### Notes
- No OCR recognition, Tesseract, AI vision, parsing, migrations, schema changes, dashboard changes, or frontend assets. Denoise, adaptive binarization, border removal, and automatic deskew (`.ai/ocr.md` §4.2 steps 2–5) need an image-processing library absent from the repo; the `appliedTransforms` pipeline structure is ready for them in the OCR engine phase (`docs/PHASE5.md` §5.3.3).
- Verification: `php artisan test` 124 passed / 581 assertions / 3 skipped; `./vendor/bin/pint --test` PASS (135 files). `npm run build` not applicable — no frontend build asset changed.

### Added (Phase 5.4 — OCR Engine Integration — 2026-08-06)
- **Engine contract** (`app/Services/OcrEngine.php`, new — `.ai/ocr.md` §12): `run(string $imagePath): OcrResult`; the pipeline's abstraction over the OCR binary.
- **Tesseract engine** (`app/Services/TesseractOcrEngine.php`, new): invokes `tesseract <image> stdout -l ind --psm 6 tsv` via Laravel's Process facade (`.ai/ocr.md` §4.3), parses word-level TSV into raw text (words grouped into lines in reading order) plus a mean word confidence (0–100, 2 decimals). Non-zero exit → `OcrEngineException` (new, `app/Exceptions/`) with stderr; timeout (`config/ocr.php` `timeout_seconds`, 10 s per `.ai/ocr.md` §4.9) → `OcrEngineException`; empty/no-word output → empty `OcrResult` (`''`, 0.0, 0 words).
- **OCR result DTO** (`app/Services/OcrResult.php`, new readonly): `rawText`, `confidence`, `wordCount`, `durationMs` — in-memory only, never persisted.
- **Configuration** (`config/ocr.php`, new — `.ai/ocr.md` §6): `tesseract_path` (env `TESSERACT_PATH`, default `tesseract` on PATH), `language` `ind`, `psm` `6`, `confidence_threshold` 70, `timeout_seconds` 10, `temp_retention_hours` 24. Resolution/size bounds stay owned by `ImagePreprocessor` / `KkDocumentUploadService`.
- **Pipeline stage** (`app/Services/OcrProcessingService.php`): new `extract(OcrJob)` stage after `start()` — resolves the preprocessed image on the `ocr_temp` disk, runs the engine, persists the outcome on existing columns (no migration): mean confidence ≥ 70 → `SUCCESS`, below (incl. empty results) → `LOW_CONFIDENCE`, both persisting `raw_text` + `confidence` + `finished_at`; engine failure/timeout → `FAILED` with `error_message` + `finished_at`. `ocrResult()` accessor added. `start()` untouched — the Phase 5.1–5.3 tests were not rewritten.
- **DI binding** (`app/Providers/AppServiceProvider.php`): `OcrEngine` → `TesseractOcrEngine`; tests override with a fake.
- **Phase 5.4 tests**:
  - `tests/Feature/Phase5/TesseractOcrEngineTest.php` (6 tests, Process-faked): successful TSV parse, invocation shape + timeout wiring via `Process::assertRan`, empty output, non-zero exit with/without stderr, plus an env-gated real-binary test (`RUN_TESSERACT_TESTS=1`, same gating as the Phase 3 real-MySQL test) rendering a NIK with GD + DejaVu and asserting real tesseract 5.5 + `ind` extracts it.
  - `tests/Feature/Phase5/OcrEnginePipelineTest.php` (9 tests, `tests/Support/FakeOcrEngine.php` bound in the container): SUCCESS/LOW_CONFIDENCE persisted with raw text + confidence, threshold boundary 70.0, empty result → LOW_CONFIDENCE, engine failure → FAILED, timeout → FAILED ("timed out"), DB status sequence PENDING → SUCCESS, extract without `start()` rejected, non-PROCESSING job rejected.
- `SUCCESS` / `LOW_CONFIDENCE` are now persisted outcomes (previously reserved for the extraction sub-phase per `docs/PHASE5.md` §5.2.3); `PROCESSING` remains runtime-only.

### Notes
- No parsing, no KartuKeluarga / Penduduk creation, no review UI, no confidence highlighting, no dashboard changes, no migrations. The `.ai/ocr.md` §4.3 character whitelist is deliberately deferred — a digits/uppercase whitelist would mangle lowercase name/address text before the parsing stage exists (`docs/PHASE5.md` §5.4.3).
- Verification: `php artisan test` 138 passed / 628 assertions / 4 skipped (3 MySQL + 1 Tesseract, env-gated); `./vendor/bin/pint --test` PASS (143 files). `npm run build` not applicable — no frontend build asset changed. Real-binary smoke (`RUN_TESSERACT_TESTS=1`) passes on this host.

### Added (Phase 5.5 — OCR Parsing and Mapping — 2026-08-06)
- **Parsing service** (`app/Services/OcrParsingService.php`, new — rule-based per ADR-017): `parse(string $rawText, float $confidence): ParsedOcrResult`, a pure function of the raw text with no database access.
  - Header scan recognizes `NOMOR KARTU KELUARGA` / `NOMOR KK` / `NO KK`, `ALAMAT`, `RT/RW`, `RT`, `RW`, `LINGKUNGAN` labels (`:` or space separator, longest label first); wrapped addresses and KK numbers on their own line are recovered.
  - Member-table scan finds the `NIK`/`NAMA` column header and reads rows with a valid 16-digit NIK (spaced NIK runs merged); remaining tokens are attributed in column order by longest-match against curated vocabularies (religions, educations, occupations, marital statuses, family relations).
  - Confidence handling: aggregate engine confidence carried onto every member; `lowConfidence` below `ocr.confidence_threshold`; `< 30` adds a `Gambar tidak terbaca` warning.
  - Required-field validation (`.ai/ocr.md` §4.7): nomor KK present + 16 digits, at least one member NIK, sane birth dates (1900..today) — problems land in `validationErrors`, never thrown.
  - Graceful degradation: missing values stay null; duplicate labels keep the first occurrence (conflicting duplicates warn); duplicate NIKs keep the first row; malformed rows skipped with a warning; empty input yields an empty result. Stage log line `pipeline_stage=parse` matches the preprocess convention (`.ai/ocr.md` §9).
- **Structured DTOs** (new, `final readonly`, in-memory only): `ParsedOcrResult` (confidence, lowConfidence, kkNumber, address, rt, rw, lingkungan, `ParsedResident[]` members, warnings, validationErrors, durationMs, `isEmpty()`/`memberCount()`) and `ParsedResident` (nama, nik, gender, birthPlace, birthDate `Y-m-d`, religion, education, occupation, maritalStatus, familyRelation, confidence, lowConfidence).
- **Pipeline stage** (`app/Services/OcrProcessingService.php`): new `parse(OcrJob)` stage after `extract()` — parses the in-memory `OcrResult`, publishes `parsedResult()`, persists **nothing** (the `ocr_jobs` row stays untouched; no `KartuKeluarga`/`Penduduk`/`KkAnggota` writes). `start()`/`extract()` untouched — the Phase 5.1–5.4 tests were not rewritten.
- **Phase 5.5 tests**:
  - `tests/Feature/Phase5/OcrParsingServiceTest.php` (11 tests): valid full parse (all defined fields, case preserved); missing optional fields stay null; missing required fields reported; malformed OCR (15-digit NIK skipped with warning, impossible date flagged, junk lines ignored); duplicate labels + duplicate NIK keep first occurrence with warnings; low confidence flags result and members; threshold boundary 70.0; very-low confidence warning; empty / whitespace-only input; RT/RW/lingkungan variants; wrapped KK number and spaced NIK recovery.
  - `tests/Feature/Phase5/OcrParsingPipelineTest.php` (6 tests, `FakeOcrEngine`): parse after extract returns the structured result; parse persists nothing (row unmutated, `extracted_data` null); parse without extract rejected; non-terminal (PENDING/FAILED) rejected; SUCCESS job without extraction on the instance rejected; low-confidence extraction parses into a low-confidence result.

### Notes (Phase 5.5)
- No persistence, no KK/Penduduk creation, no review UI, no confidence highlighting, no dashboard changes, no migrations — parsing is a pure in-memory mapping layer (ADR-009: OCR is an assistant).
- Field-level confidence (`.ai/ocr.md` §4.4) is approximated by carrying the engine's aggregate onto each member; per-field word confidence needs a per-token stream from the engine and is deferred to a later sub-phase.
- Verification: `php artisan test` 155 passed / 753 assertions / 4 skipped (3 MySQL + 1 Tesseract, env-gated); `./vendor/bin/pint --test` PASS (148 files). `npm run build` not applicable — no frontend build asset changed.

### Added (Phase 5.6 — OCR Review and Validation — 2026-08-07)
- **Review service** (`app/Services/OcrReviewService.php`, new): the operator validation layer over the Phase 5.5 `ParsedOcrResult`.
  - `validate(ParsedOcrResult, array $corrections = [])` merges the parsed baseline with operator corrections into one effective dataset, validates it against a schema-grounded rule set (kk_number 16 digits, address, the NOT NULL penduduk columns), and returns an in-memory `OcrReviewResult` (isValid, field-keyed errors, correctedData, duration). No database writes, no KK/Penduduk creation, no job mutation (ADR-009 — OCR is an assistant, never auto-saves).
  - `missingRequiredFields(array $data)` — labels of required fields still empty, for the page's "Wajib diisi" highlight.
  - `isReviewable(OcrJob $job)` — gates review to terminal OCR states (`SUCCESS`/`LOW_CONFIDENCE`) that carry raw text.
  - `confidenceBand(float $confidence)` — `.ai/ocr.md` §5: ≥ 90 normal, 70–90 yellow (`warning`), < 70 red (`danger`, "Harap periksa").
- **Review resource** (`app/Filament/Resources/OcrJobs/`, new) — the operator entry point: `OcrJobResource` (index table with ID / status badge / confidence / timestamps, a `review` action, routes `index` and `/{record}/review`; nav group `Kependudukan`, label "Review OCR"), `ListOcrJobs` index page, and `ReviewOcrJob` page.
- **Review page** (`ReviewOcrJob`): loads the job via `InteractsWithRecord`, re-parses raw text in-memory (`OcrParsingService`), and renders the review form. Uses the canonical Filament v4 form-state pattern (`public ?array $data = []` bound via `statePath('data')` in `defaultForm()`), mapping parsed fields into `kk_number`, `address`, `rt`, `rw`, `lingkungan` and a `members` Repeater (nama, nik, gender, place/date of birth, religion, education, occupation, marital status, family relation). A `validateReview()` action runs the pre-approval gate and notifies ("Validasi berhasil" / "Validasi gagal" / "Belum dapat divalidasi") — it never imports.
- **Detail status sections** (`statusComponents()`): conditional Filament Sections for parse problems, missing required fields, and low-confidence members — the `.ai/ocr.md` §5 highlighting requirement, driven by `currentData()` (falls back to the parsed baseline while the schema builds; normalizes Repeater UUID keys back to a numeric list for the service).
- **Blade view** (`resources/views/filament/resources/ocr-jobs/review-ocr-job.blade.php`): renders the form, the "Validasi Data" button, and the "belum dapat direview" rejection panel.
- **Phase 5.6 tests**:
  - `tests/Feature/Phase5/OcrReviewServiceTest.php` (11 tests): complete parse validates; missing kk_number rejected; more than one member required; malformed NIK correction breaks validation; invalid gender/marital-status corrections rejected; corrections fix a parse problem; corrections override parsed values; missing-required returned as labels; complete result has none; confidence band matches §5 boundaries (90/70); `isReviewable` gates terminal states with raw text.
  - `tests/Feature/Phase5/OcrReviewPageTest.php` (9 tests): page loads; parsed fields displayed (asserted against Livewire component state, since Filament renders deferred form values in partials rather than the initial HTTP shell); missing-required highlighted; low-confidence highlighted; high confidence not flagged; validation succeeds + reports ready-to-import; validation fails on malformed operator correction (field error surfaced); non-reviewable job rejected without the form; review never writes (KK and Penduduk tables unchanged).

### Notes (Phase 5.6)
- No persistence / import — no KartuKeluarga, Penduduk, or KkAnggota rows created or updated, and `ocr_jobs` is never mutated. Accepting and importing the validated data is the Save / import sub-phase (ADR-009).
- Duplicate-upload detection (FR-OCR-05, image hash + KK number) and per-field word confidence remain deferred.
- No migrations, no schema changes, no dashboard changes, no frontend build asset. `php artisan test` 175 passed / 818 assertions / 4 skipped (3 MySQL + 1 Tesseract, env-gated); `./vendor/bin/pint --test` PASS (155 files).

### Added (Phase 5.8 — Import Penduduk — 2026-08-07)
- **Penduduk import service** (`app/Services/PendudukImportService.php`, new — `App\Services\*` per ADR-016):
  - `import(OcrJob $job, ?User $operator = null): PendudukImportResult` — the second half of the operator-triggered "SIMPAN" write (ADR-009). Consumes the Phase 5.7 SAVED state (`kk_id`, `outcome` SAVED, `raw_text`, `extracted_data` snapshot) and persists every approved member as a `Penduduk` row linked to the KK, plus one ACTIVE `KkAnggota` membership row per member (ADR-008 membership baseline).
  - Re-runs the approved snapshot through `OcrReviewService::validate()` (re-parsing `raw_text`, re-applying the snapshot as corrections) before any write — a tampered/incomplete dataset returns `invalid` with zero writes.
  - **Domain mapping**: gender / marital_status / family_relation from their enums; `blood_type` defaults to `TIDAK_DIKETAHUI`, `resident_status` to `ACTIVE`; religion / education / occupation resolve case-insensitively to the lookup masters (created title-cased when absent); `birth_date` normalized to `Y-m-d`; reviewed `rt` resolved to an existing `Rt` by number (`"001"` → `"01"`), else `invalid` with a clear message.
  - **Duplicate NIK detection** (FR-OCR-05 / `penduduk.nik` unique): intra-list repeats and existing `penduduk` rows are pre-checked, and the insert is wrapped in `DB::transaction` so a concurrent NIK race also resolves to `duplicate` (never a partial family).
  - **Transactional write**: every Penduduk + KkAnggota insert and the job update in one transaction; a failed job update rolls the whole family back (no orphan residents).
  - **OCR job updated on success**: `extracted_data` augmented with `penduduk_imported_at`, `penduduk_ids`, `penduduk_operator_id` (audit); `status` / `outcome` / `kk_id` untouched.
  - **Guards**: jobs without a Phase 5.7-imported KK throw `InvalidArgumentException`; a job already carrying the `penduduk_imported_at` marker returns `already_imported` and writes nothing.
- **Import result DTO** (`app/Services/PendudukImportResult.php`, new — `final readonly`, in-memory only): status `saved` / `duplicate` / `invalid` / `already_imported` with `kartuKeluargaId`, `kkNumber`, `importedCount`, `duplicateNik`, validation `errors`; `isSaved()` / `isDuplicate()` / `isInvalid()` / `isAlreadyImported()`.
- **Phase 5.8 tests** (`tests/Feature/Phase5/PendudukImportServiceTest.php`, 12 tests, fixtures built by running the real Phase 5.7 import): successful import creates all family members (3 penduduk + 3 kk_anggota); every member linked to the imported KK (kk_id, rt); parsed family relation preserved on penduduk and kk_anggota (KEPALA_KELUARGA / ISTRI / ANAK); member fields map onto the existing domain (enums, defaults, lookups created, birth date); duplicate NIK against an existing penduduk rejected with zero writes; duplicate NIK inside the approved list rejected with zero writes; transaction rolls back when the job-update step fails (family rolled back, job untouched); OCR job updated after success (marker + ids + operator); already-imported job refused; invalid snapshot fails with zero writes; missing RT fails `invalid`; not-yet-imported job rejected by the guard.

### Notes (Phase 5.8)
- No review-page UI wiring (service-layer contract only, same as 5.7). No migrations / schema changes — the reviewed `rt` / `rw` / `lingkungan` fields still have no persistable columns; RT resolves to an existing `rts` row by number (area-unit disambiguation out of scope).
- No changes to OCR parsing, the OCR engine, preprocessing, the review page, the dashboard, or Phase 5.1–5.7 code.
- No migrations, no frontend build asset. `php artisan test` 195 passed / 924 assertions / 4 skipped (3 MySQL + 1 Tesseract, env-gated); `./vendor/bin/pint --test` PASS (161 files).

### Added (Phase 5.9 — OCR Finalization — 2026-08-07)
- **Completion service** (`app/Services/OcrCompletionService.php`, new — the centralized success/failure completion handler closing the OCR lifecycle after the Phase 5.7 KK + Phase 5.8 Penduduk imports, ADR-009):
  - `finalize(OcrJob $job, ?User $operator = null): OcrCompletionResult` — transitions the fully imported job (outcome SAVED + `kk_id` + the `penduduk_imported_at` snapshot marker) to the terminal **COMPLETED** state.
  - **Completion timestamp** recorded on the audit snapshot (`extracted_data.ocr_completed_at`); the extraction `finished_at` is never overwritten.
  - **Import summary generation** persisted on the snapshot as `completion_summary` (imported, kk_number, kartu_keluarga_id, member_count, penduduk_count, completed_at).
  - **Final processing metrics** persisted as `processing_metrics` (ocr_status, confidence, duration_ms, word_count, member_count, imported_penduduk_count).
  - **Cleanup of temporary processing artifacts**: best-effort removal of the pipeline's transient files on the private `ocr_temp` disk (`ImagePreprocessor::DISK`) after the completion is persisted — the uploaded source document on `kk_uploads` (the persistent archive) is never touched; a cleanup failure logs a warning and never rolls back (or breaks) the completion.
  - **Audit/event logging** using the project's existing approach: an `AuditLog` row (`event` `ocr.completed`, actor + summary values) in the same DB transaction as the job update, plus `Log::info('OCR finalize …', pipeline_stage=finalize)` per `.ai/ocr.md` §9.
  - **Idempotence**: an already-COMPLETED job returns `already_completed` and writes nothing (no duplicate completion). **Fault handling**: a failing job-save step rolls the whole finalization back (no COMPLETED state, no summary, no audit entry); not-fully-imported / FAILED jobs are rejected by the guard with `InvalidArgumentException` (`markJobCompleted()` kept `protected` so the rollback is verifiable).
- **Completion result DTO** (`app/Services/OcrCompletionResult.php`, new — `final readonly`, in-memory only): status `completed` / `already_completed` with `jobId`, `kartuKeluargaId`, `kkNumber`, `importedPendudukCount`, `summary`, `metrics`; `isCompleted()` / `isAlreadyCompleted()`.
- **COMPLETED status** (`app/Enums/OcrJobStatus.php` + `database/migrations/2026_08_07_101500_add_completed_status_to_ocr_jobs_table.php`): the enum gains the terminal `COMPLETED` case (included in `persistable()`), and the `ocr_jobs.status` column constraint (SQLite CHECK / MySQL ENUM) is widened to accept it — the exact "deliberate future schema change" documented in Phase 5.2; purely additive (existing values/rows/NOT-NULL untouched).
- **Phase 5.9 tests** (`tests/Feature/Phase5/OcrCompletionServiceTest.php`, 11 tests, fixtures built by running the real Phase 5.7 + 5.8 imports): successful finalize marks the job COMPLETED (persisted); completion summary + processing metrics generated; completion timestamp recorded without overwriting `finished_at`; audit entry appended with the operator; result DTO reports completed details; duplicate completion refused with exactly one audit entry and no duplicate writes; transaction rolls back when the job-update step fails (no COMPLETED state, no audit entry, temp files survive); guards reject a KK-only (no Penduduk import), a not-yet-imported, and a FAILED job; transient `ocr_temp` artifacts cleaned up while the uploaded source archive survives.

### Notes (Phase 5.9)
- No changes to the OCR engine, parsing, review workflow, KK import, Penduduk import, dashboard, or Filament resources. No new columns/tables — the completion timestamp, summary and metrics live in the existing `extracted_data` JSON, the audit entry on `audit_logs`, cleanup touches only the `ocr_temp` disk. Finalization is a service-layer contract with no UI wiring (same as 5.7 / 5.8).
- `php artisan test` 206 passed / 983 assertions / 4 skipped (3 MySQL + 1 Tesseract, env-gated); `./vendor/bin/pint --test` PASS (165 files).

### Added (Phase 5.7 — Import Kartu Keluarga — 2026-08-07)
- **Import service** (`app/Services/OcrImportService.php`, new — `App\Services\*` per ADR-016):
  - `import(OcrJob $job, array $correctedData, ?User $operator = null): OcrImportResult` — the operator-triggered "SIMPAN" write (ADR-009). Consumes the Phase 5.6 approved review data and persists **only** a `KartuKeluarga` record (`kk_number` + `address`); **no Penduduk / KkAnggota creation**.
  - Re-runs the supplied corrections through `OcrReviewService::validate()` (the existing schema-grounded gate) before writing — an un-validated/tampered payload returns an `invalid` result with zero writes.
  - **Duplicate KK detection** (FR-OCR-05, KK-number rule): `kk_number` is unique; existence is pre-checked and the insert is wrapped in `DB::transaction`, so a concurrent insert that wins the race also resolves to `duplicate` (never a partial write).
  - **Transactional write**: KK insert + job update in one transaction; a failed job update rolls the KK creation back (no orphan row).
  - **OCR job updated on success**: `outcome` = SAVED, `kk_id` linked, `reviewed_at`, `operator_id`, and the approved data snapshot persisted to `extracted_data` (audit). `status` is left untouched (it records the extraction outcome, not the save).
  - **Guards**: non-reviewable jobs (not SUCCESS/LOW_CONFIDENCE with raw text) throw `InvalidArgumentException` (pipeline convention); an already-saved job (`kk_id` set / `outcome` SAVED) returns `already_saved` and writes nothing.
- **Import result DTO** (`app/Services/OcrImportResult.php`, new — `final readonly`, in-memory only): status `saved` / `duplicate` / `invalid` / `already_saved` with `kartuKeluargaId`, `kkNumber`, validation `errors`; `isSaved()` / `isDuplicate()` / `isInvalid()` / `isAlreadySaved()`.
- **Phase 5.7 tests** (`tests/Feature/Phase5/OcrImportServiceTest.php`, 8 tests): successful import creates exactly one KK (no penduduk/kk_anggota rows); duplicate KK number rejected with zero writes; transaction rolls back when the job-update step fails (KK insert rolled back, job untouched); OCR job updated after success (outcome SAVED, kk_id, reviewed_at, data snapshot); operator recorded when provided; invalid data fails import with zero writes; already-saved job refused; non-reviewable job rejected by the guard.

### Notes (Phase 5.7)
- No Penduduk / KkAnggota creation — importing the approved `members` rows is a later sub-phase. No review-page UI wiring (service-layer contract only). No migrations / schema changes (the reviewed `rt` / `rw` / `lingkungan` fields still have no persistable columns).
- No changes to OCR parsing, the OCR engine, preprocessing, the dashboard, or Phase 5.1–5.6 code. Two pre-existing quirks were noted but deliberately left untouched: the `OcrJob` model has no `outcome` cast, and the `OcrJob` factory definition eagerly creates a backing `KartuKeluarga` when the table is empty.
- No migrations, no frontend build asset. `php artisan test` 183 passed / 853 assertions / 4 skipped (3 MySQL + 1 Tesseract, env-gated); `./vendor/bin/pint --test` PASS (158 files).

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
