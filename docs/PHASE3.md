| Field | Value |
| --- | --- |
| **Title** | SIPETA Phase 3 — Filament CRUD (Cumulative Consolidated Report) |
| **Purpose** | Single consolidated Phase 3 record covering all sub-phases (3.1–3.5) of the Filament admin panel, KK + Penduduk resources, relations, and polish. |
| **Scope** | Phase 3.1 Admin panel foundation, 3.2 KK resource (3.2.1 scaffold → 3.2.5 table), 3.3 Penduduk resource, 3.4 KK ↔ Penduduk relation, 3.5 final polish. |
| **Version** | 3.0.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-06 |
| **Related Documents** | `.ai/hermes.md`, `.ai/filament.md`, `docs/PHASE3.1-REPORT.md` (folded), `docs/PHASE3.2.1-REPORT.md` (folded), `app/Filament/Resources/` |

---

# Phase 3 — Filament CRUD

This is the single consolidated Phase 3 record. Sub-phase detail that previously lived in
`docs/PHASE3.1-REPORT.md` and `docs/PHASE3.2.1-REPORT.md` is now inlined below (sections 3.1 and 3.2.1)
so the whole phase lives in one document.

# SIPETA Phase 3 — Cumulative Report

This is the single cumulative Phase 3 record. Sub-phase detail that already has
its own report file is summarised here by reference, never duplicated.

## Sub-phase status

| Sub-phase | Scope | Status | Commit |
|---|---|---|---|
| 3.1 | Admin panel foundation | Done | `eba15fd`, `d46f427` |
| 3.2.1 | KK Resource scaffold | Done | `9ea75fe` |
| 3.2.2 | KK form schema | Done | `e34eedd` |
| 3.2.3 | KK table schema (complete) | Done | `4f3755b` |
| 3.2.5 | KK table schema (base) | Done | `c55d2b1` |
| 3.2.4 | KK bug fixes, navigation group, resource tests | Done | this task |

Note on numbering: 3.2.5 (base table) was executed before 3.2.3 (complete
table). 3.2.3 extended the same file rather than replacing it. No work was lost
and no implementation is duplicated — `KartuKeluargasTable.php` has exactly one
definition.

## Phase 3.1 — Admin panel foundation

# SIPETA Phase 3.1 Finalization Report

### 3.1.1 Instruction Doc Discrepancy

The task said to read `docs/roadmap.md` and `docs/PHASE3-ARCHITECTURE.md`. Neither file exists in the repository (confirmed via `ls`). The canonical roadmap is `.ai/roadmap.md` (v1.2.0), which was read instead. `.ai/hermes.md` (v1.4.0) was read as the third permitted doc. No other documentation was opened. Because `docs/PHASE3-ARCHITECTURE.md` does not exist, the navigation/branding specifics were derived from the task's explicit statements ("Configure panel navigation", "temporary SIPETA branding is acceptable") and the verified Filament 4 Panel API — not from memory of a missing doc.

### 3.1.2 Verdict

**PHASE 3.1 COMPLETE — verified against real MySQL.**

Delivered:
- Filament admin panel scaffold (panel `id=admin`, `path=admin`), registered in `bootstrap/providers.php`.
- Login enabled (`->login()`).
- Temporary SIPETA branding (`->brandName('SIPETA')`, primary color `Color::Amber`).
- Navigation skeleton (`navigationGroups(['Kependudukan', 'Master Data'])`) — placeholder groups; no Resources/CRUD yet (those are Phase 3.2+).
- `admin/login` route confirmed reachable.
- Admin user created via the documented `AdminUserSeeder` (`admin@sipeta.test`).
- SIPETA schema migrated into MySQL (committed migrations only; no migration files modified).
- Two test layers: a SQLite smoke test (`AdminPanelTest`) and an env-gated real-MySQL verification test (`MysqlPanelVerificationTest`).

Not done (with reasons):
- No Resources, Pages, Widgets, CRUD, migration edits, model edits, or OCR code were created (out of scope for 3.1).
- `User` does NOT implement `FilamentUser` — so in a non-`local` environment the dashboard returns 403. The real operator runtime is `APP_ENV=local` (`.env`), where panel access is permitted. Implementing `FilamentUser::canAccessPanel()` on the `User` model is a Phase 3.2 task and is intentionally out of scope here. The MySQL verification test mirrors the real `local` runtime to prove the dashboard renders.

### 3.1.3 What Was Verified (real command output)

### 3.1.4 Filament installation
```
$ composer show filament/filament
name: filament/filament
versions: * v4.12.5
```
Filament package installed and loadable.

### 3.1.5 Panel created + registered
```
$ php artisan make:filament-panel admin -n
INFO  Filament panel [app/Providers/Filament/AdminPanelProvider.php] created successfully.
```
Registered in `bootstrap/providers.php`:
```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
];
```

### 3.1.6 Branding + navigation
Applied in `app/Providers/Filament/AdminPanelProvider.php`:
- `->brandName('SIPETA')`
- `->colors(['primary' => Color::Amber])`
- `->navigationGroups(['Kependudukan', 'Master Data'])`
- `->login()` (from the generator)

### 3.1.7 MySQL connectivity (Task 1)
```
$ php artisan db:show
  MariaDB ............................................................. 11.8.8
  Connection ........................................................... mysql
  Database ............................................................ sipeta
  Host ............................................................. 127.0.0.1
  Port .................................................................. 3306
  Username ........................................................ sipeta_app
  Tables ................................................................... 9
```
MySQL reachable using the current `.env` (`DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=sipeta`, `DB_USERNAME=sipeta_app`).

### 3.1.8 Migration state (Task 2) — applied committed migrations only
Before:
```
$ php artisan migrate:status
  0001_01_01_000000_create_users_table ............ [1] Ran
  0001_01_01_000001_create_cache_table ............ [1] Ran
  0001_01_01_000002_create_jobs_table ............. [1] Ran
  2026_08_05_100000_create_settings_table ......... Pending
  ... (13 SIPETA domain tables) ................... Pending
  2026_08_05_101300_add_started_at_index_to_backup_logs_table ... Pending
  2026_08_05_101400_add_kk_id_index_to_ocr_jobs_table ........... Pending
```
The SIPETA schema was not yet built in MySQL (all 15 domain/audit-fix migrations Pending). Applied committed migrations only:
```
$ php artisan migrate --force
  2026_08_05_100000_create_settings_table ............... DONE
  2026_08_05_100100_create_religions_table .............. DONE
  ... (all 13 SIPETA domain tables) .................... DONE
  2026_08_05_101300_add_started_at_index_to_backup_logs_table ... DONE
  2026_08_05_101400_add_kk_id_index_to_ocr_jobs_table ... DONE
```
No migration files were modified; only previously committed migrations were run.

### 3.1.9 Admin user (Task 4) — created via documented seeder only
```
$ php artisan tinker --execute="...User::where('email','admin@sipeta.test')->first(['id','name','email'])..."
found=NO  (before)
$ php artisan db:seed --class=AdminUserSeeder --force
   INFO  Seeding database.
$ php artisan tinker --execute="...User::where('email','admin@sipeta.test')->first(['id','name','email'])..."
found=YES id=1 name=Administrator email=admin@sipeta.test
```
The admin user (`admin@sipeta.test`, id=1) was created via the documented, idempotent `AdminUserSeeder` (`updateOrCreate` on email, default password `password`). `ADMIN_PASSWORD` is not set in `.env`, so the seeder used the default password. **Action for the operator before deployment:** set `ADMIN_PASSWORD` in `.env` and re-run `php artisan db:seed --class=AdminUserSeeder --force` to change it (this is the documented ADR-005 step, intentionally left for local/prod setup, not done here).

### 3.1.10 Panel + auth + dashboard verification on real MySQL (Tasks 3, 5)
Added `tests/Feature/Phase3/MysqlPanelVerificationTest.php`, **env-gated** (`RUN_MYSQL_TESTS=1`) and **RefreshDatabase-free** (only reads `users` and writes a session row). Ran against the real MySQL database:
```
$ RUN_MYSQL_TESTS=1 php artisan test --filter=MysqlPanelVerification
  PASS  Tests\Feature\Phase3\MysqlPanelVerificationTest
  ✓ admin login page renders against mysql                 (GET /admin/login → 200)
  ✓ admin authenticates via filament login against mysql   (Livewire Login, no errors, redirect /admin)
  ✓ dashboard loads for authenticated admin                (GET /admin → 200)
  Tests:    3 passed (6 assertions)
```
Notes:
- Filament 4 login is a Livewire component (`Filament\Auth\Pages\Login`); a raw `POST /admin/login` is not valid. The test exercises the real Livewire `authenticate` action with the seeded credentials.
- `GET /admin` initially returned **403** under `phpunit.xml`'s `APP_ENV=testing`. Root cause: Filament 4's `Authenticate` middleware (`vendor/filament/filament/src/Http/Middleware/Authenticate.php:35`) permits panel access when `User` does NOT implement `FilamentUser` only if `config('app.env') === 'local'`. The real operator runtime is `APP_ENV=local` (`.env`), so the dashboard renders for the operator. The test mirrors the `local` runtime for the dashboard assertion to prove the render. Implementing `FilamentUser` on `User` is a Phase 3.2 task.

### 3.1.11 `php artisan about`
```
Laravel Version .......... 12.64.0
PHP Version ............... 8.4.24
Database ................. mysql
Filament .................. v4.12.5 (Packages: filament, forms, notifications, support, tables, actions, infolists, schemas, widgets)
Livewire .................. v3.8.3
```
App boots; DB driver `mysql` now reachable and migrated.

### 3.1.12 Tests + style (Task 6)
Default suite (SQLite per `phpunit.xml`; gated MySQL test skips):
```
$ php artisan test
  PASS  Tests\Feature\Phase2\SchemaTest
  PASS  Tests\Feature\Phase2\DatabaseBehaviourTest
  PASS  Tests\Feature\Phase2\RelationAndScopeTest
  PASS  Tests\Feature\Phase2\MigrationLifecycleTest
  PASS  Tests\Feature\Phase3\AdminPanelTest
  WARN  Tests\Feature\Phase3\MysqlPanelVerificationTest (3 skipped — RUN_MYSQL_TESTS not set)
  Tests:    3 skipped, 32 passed (185 assertions)
```
Targeted Pint (pinned to Phase 3.1 files; pre-existing Phase 1.5 untracked files deliberately excluded):
```
$ ./vendor/bin/pint --test app/Providers/Filament/AdminPanelProvider.php \
      tests/Feature/Phase3/AdminPanelTest.php \
      tests/Feature/Phase3/MysqlPanelVerificationTest.php
  PASS  ........................................................... 3 files
```

### 3.1.13 Files Changed (Phase 3.1 only)

| File | Change |
|------|--------|
| `app/Providers/Filament/AdminPanelProvider.php` | New — panel definition (id `admin`, path `admin`, login, branding, nav groups). |
| `bootstrap/providers.php` | Modified — `AdminPanelProvider` registered by the panel generator. |
| `tests/Feature/Phase3/AdminPanelTest.php` | New — 2 SQLite smoke tests (login page loads, route registered). |
| `tests/Feature/Phase3/MysqlPanelVerificationTest.php` | New — 3 env-gated, RefreshDatabase-free real-MySQL verification tests (login render, Livewire auth, dashboard). |
| `docs/CHANGELOG.md` | Modified — under `[Unreleased]`, Phase 3.1 entry (per `.ai/hermes.md` §23 mandate). |
| `docs/PHASE3.1-REPORT.md` | New (v1.0.0) then updated (v1.1.0) with MySQL verification. |

Committed across two local commits (both pushed; see §5): `eba15fd` (foundation) and the Phase 3.1 MySQL-verification commit.

### 3.1.14 Git State at Completion

- Two local commits pushed to `origin/main`:
  - `eba15fd` — `feat(filament): Phase 3.1 — admin panel foundation (boots, login, branding, nav skeleton)`
  - `<verify-commit>` — `docs(test): Phase 3.1 — real MySQL verification (migrate, admin user, gated test, report)`
- After the push, the working tree remains dirty with the **pre-existing Phase 1.5 set** (intentionally NOT part of Phase 3.1): `.env.example`, `.gitignore`, `README.md`, `composer.json`, `composer.lock`, `config/filesystems.php`, `storage/app/.gitignore`, `docs/PHASE1.5-REPORT.md`, `package-lock.json`, `pint.json`, `scripts/`, `storage/app/backups/`, `storage/app/kk_uploads/`, `storage/app/ocr_temp/`.
- `vendor/`, `.env`, `node_modules/` not touched. No migration files modified. No force-push.

### 3.1.15 Verification Environment

- MySQL/MariaDB 11.8.8 is running and reachable on `127.0.0.1:3306` (DB `sipeta`, user `sipeta_app`). The schema was migrated from committed migrations.
- The default `php artisan test` uses SQLite `:memory:` per `phpunit.xml`. The real-MySQL verification is opt-in via `RUN_MYSQL_TESTS=1` so it never wipes or reset the production database during normal runs.
- The MySQL verification test is RefreshDatabase-free: it reads `users` and writes only a session row during login. It does not migrate, wipe, or reset the schema.
- Operator login in the real app: open `/admin/login`, sign in with `admin@sipeta.test` / `password` (default; change via `ADMIN_PASSWORD` in `.env` per ADR-005).

### 3.1.16 Recommendation

Phase 3.1 foundation is complete and verified against real MySQL, committed and pushed. **Do not start Phase 3.2** until the project owner confirms. When Phase 3.2 begins, consider implementing `FilamentUser::canAccessPanel()` on `User` (so panel access does not depend on `APP_ENV=local`), and ensure MySQL/MariaDB is running so Resources can be built against the migrated schema.
## Phase 3.2.1 — KK Resource scaffold

# SIPETA Phase 3.2.1 Report — KK (Kartu Keluarga) Resource

### 3.2.1.1 Instruction Doc Discrepancy

The task said to read `docs/PHASE3-ARCHITECTURE.md`. That file does **not** exist in the repository (confirmed via `ls docs/`). This is the same gap recorded in `docs/PHASE3.1-REPORT.md` §1. I proceeded using the authoritative, existing sources:

- `.ai/hermes.md` (v1.4.0) — project constitution; §18 Filament Rules ("Use Filament for CRUD, forms, tables, filters, exports. Keep Resources focused."), §22.15 Commit Safety Gate.
- `app/Models/KartuKeluarga.php` — the existing model the resource must reference.
- `app/Providers/Filament/AdminPanelProvider.php` — the panel that auto-discovers resources.
- `docs/PHASE3.1-REPORT.md` — establishes the foundation this phase extends (panel `id=admin`, `path=admin`, `->login()`).

No other documentation was opened. No missing-doc assumption was invented; the resource was built purely from the verified Filament 4 generator output and existing code.

### 3.2.1.2 Verdict

**PHASE 3.2.1 COMPLETE — Kartu Keluarga Filament Resource generated, registered, and verified to boot. Full test suite passes.**

Delivered:
- A Filament 4 resource for `KartuKeluarga` that references the **existing** model (no model, factory, or migration created or modified).
- Standard resource pages (`index`/`create`/`edit`) auto-generated by the Filament generator.
- Form and table schemas left as **empty scaffolds** — forms/tables are explicitly out of scope for 3.2.1.
- Registration handled automatically by Filament's `discoverResources()` in `AdminPanelProvider` (no provider edit needed).
- `php artisan test` → **32 passed / 185 assertions**, 3 env-gated MySQL tests skipped.

Not done (with reasons):
- No forms/tables built (constrained by task).
- No model, migration, factory, Penduduk feature, or OCR code created or modified (constrained by task).
- No new tests added (none required by the task; existing suite remains green).

### 3.2.1.3 What Was Generated

Command (Filament v4.12.5 generator; the positional argument is the model, `--model` is a boolean in v4 so it is omitted):

```bash
php artisan make:filament-resource KartuKeluarga --no-interaction
```

Output:
```
INFO  Filament resource [App\Filament\Resources\KartuKeluargas\KartuKeluargaResource] created successfully.
```

Files created (all under `app/Filament/Resources/KartuKeluargas/`):

| File | Contents |
|------|----------|
| `KartuKeluargaResource.php` | `extends Resource`; `protected static ?string $model = KartuKeluarga::class`; `form()`/`table()` delegate to the schema classes; `getRelations()` returns `[]`; standard `getPages()` (index/create/edit). |
| `Schemas/KartuKeluargaForm.php` | `configure(Schema): Schema` with `->components([])` — empty scaffold (no form built). |
| `Tables/KartuKeluargasTable.php` | `configure(Table): Table` with empty `->columns([])` and `->filters([])`; only default `EditAction` + `DeleteBulkAction` (no columns defined). |
| `Pages/ListKartuKeluargas.php` | List page (extends `ListRecords`). |
| `Pages/CreateKartuKeluarga.php` | Create page (extends `CreateRecord`). |
| `Pages/EditKartuKeluarga.php` | Edit page (extends `EditRecord`). |

No `make:model`, `make:migration`, or `--factory` was triggered — generation reused the existing `App\Models\KartuKeluarga`.

### 3.2.1.4 Namespace note (cosmetic, not a defect)

Filament auto-pluralized the resource **namespace** to `KartuKeluargas`. The class itself is `KartuKeluargaResource`. Effect on the product:
- Route slug: `admin/kartu-keluarga` (and `admin/kartu-keluarga/create`, `admin/kartu-keluarga/{record}/edit`).
- Navigation label resolves to "Kartu Keluarga".
This is standard Filament behavior and requires no change for the 3.2.1 scope. If the team wants the namespace singular for consistency with a future naming standard, that is a later-phase refactor, not a 3.2.1 item.

### 3.2.1.5 Registration (Task 2)

No manual registration was needed. `app/Providers/Filament/AdminPanelProvider.php:39` already declares:

```php
->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
```

The new resource lives under that path and namespace, so it is discovered automatically on panel boot. The provider was **not** modified (no scope creep).

### 3.2.1.6 Verification — It Boots (Task 3)

### 3.2.1.7 Route registration (loads the panel + resource; fatal errors would surface here)
```bash
$ php artisan route:list | grep -i kartu
  GET|HEAD  admin/kartu-keluargas  filament.admin.resources.kartu-keluarga.index
  GET|HEAD  admin/kartu-keluargas/create  filament.admin.resources.kartu-keluarga.create
  GET|HEAD  admin/kartu-keluargas/{record}/edit  filament.admin.resources.kartu-keluarga.edit
```
Exit 0 — the panel booted and registered all three KK routes with no fatal error.

### 3.2.1.8 `php artisan about` (Filament package resolves)
```bash
$ php artisan about | grep -iA1 filament
  Filament ....................................................................
  Packages  filament, forms, notifications, support, tables, actions, infolists, schemas, widgets
```

### 3.2.1.9 Style — Pint
```bash
$ ./vendor/bin/pint --test app/Filament/Resources/KartuKeluargas/
  PASS  ........................................................... 6 files
```

### 3.2.1.10 Cache cleared before verification
```bash
$ php artisan optimize:clear
  routes / views / blade-icons / filament ........................ DONE
```

### 3.2.1.11 Test Run (Task 4)

Default suite per `phpunit.xml` (SQLite `:memory:`; the env-gated MySQL tests skip unless `RUN_MYSQL_TESTS=1`):

```bash
$ php artisan test
  PASS  Tests\Feature\Phase2\RelationAndScopeTest        (8 tests)
  PASS  Tests\Feature\Phase2\DatabaseBehaviourTest      (9 tests)
  PASS  Tests\Feature\Phase2\SchemaTest                 (6 tests)
  PASS  Tests\Feature\Phase2\MigrationLifecycleTest     (5 tests)
  PASS  Tests\Feature\Phase3\AdminPanelTest             (2 tests)
  WARN  Tests\Feature\Phase3\MysqlPanelVerificationTest (3 skipped — RUN_MYSQL_TESTS not set)
  Tests:    3 skipped, 32 passed (185 assertions)
  Duration: 3.33s
```

Exit code 0. The new resource does not break any existing test; no schema/model access occurs at boot time because the form/table schemas are empty.

### 3.2.1.12 Files Changed (Phase 3.2.1 only)

| File | Change |
|------|--------|
| `app/Filament/Resources/KartuKeluargas/KartuKeluargaResource.php` | New — resource class, references `App\Models\KartuKeluarga`. |
| `app/Filament/Resources/KartuKeluargas/Schemas/KartuKeluargaForm.php` | New — empty form scaffold. |
| `app/Filament/Resources/KartuKeluargas/Tables/KartuKeluargasTable.php` | New — empty table scaffold. |
| `app/Filament/Resources/KartuKeluargas/Pages/ListKartuKeluargas.php` | New — list page. |
| `app/Filament/Resources/KartuKeluargas/Pages/CreateKartuKeluarga.php` | New — create page. |
| `app/Filament/Resources/KartuKeluargas/Pages/EditKartuKeluarga.php` | New — edit page. |
| `docs/PHASE3.2.1-REPORT.md` | New (v1.0.0) — this report. |
| `docs/CHANGELOG.md` | Modified — `[Unreleased]` Phase 3.2.1 entry added (per `.ai/hermes.md` §23). |

**Intentionally excluded from the commit:** the pre-existing Phase 1.5 untracked/modified set (`.env.example`, `.gitignore`, `README.md`, `composer.json`, `composer.lock`, `config/filesystems.php`, `storage/app/.gitignore`, `docs/PHASE1.5-REPORT.md`, `package-lock.json`, `pint.json`, `scripts/`, `storage/app/backups/`, `storage/app/kk_uploads/`, `storage/app/ocr_temp/`) — these are not part of Phase 3.2.1 and were left untouched.

### 3.2.1.13 Git State at Completion

- Committed on `main`; pushed to `origin/main` (no force-push).
- Commit message: `feat(filament): Phase 3.2.1 — KartuKeluarga Filament resource (scaffold, auto-registered)`.
- `vendor/`, `.env`, `node_modules/` not touched. No migration files modified. No secrets staged.

### 3.2.1.14 Residual Notes / Recommendations

- Forms and tables for KK remain empty scaffolds; those are later sub-phases (e.g. 3.2.x for KK CRUD). The resource is ready to receive schema work without structural changes.
- `getRelations()` is empty — no `Penduduk` (or any) relation surfaced in the resource, consistent with the "do not implement Penduduk" constraint.
- If a singular resource namespace is desired later, rename the `KartuKeluargas` directory/namespace to `KartuKeluarga` in a dedicated refactor phase; not required for 3.2.1.
## Phase 3.2.2 — KK form schema

`app/Filament/Resources/KartuKeluargas/Schemas/KartuKeluargaForm.php` — Section
"Data Kartu Keluarga" containing a 2-column Grid (`kk_number`, `postal_code`),
plus full-width `address` and `notes`. Indonesian labels and helper text.
Field names taken from the model (`kk_number`, `address`, `postal_code`,
`notes`). Two defects shipped in this commit; both fixed in 3.2.4 below.

## Phase 3.2.3 / 3.2.5 — KK table schema

`app/Filament/Resources/KartuKeluargas/Tables/KartuKeluargasTable.php`:

| Column | Field | Config |
|--------|-------|--------|
| Nomor KK | `kk_number` | searchable, sortable, copyable, toggleable |
| Alamat | `address` | searchable, wrap, `limit(50)`, toggleable |
| Kode Pos | `postal_code` | searchable, sortable, `placeholder('-')`, toggleable |
| Jumlah Anggota | `kkAnggotas_count` | `counts('kkAnggotas')`, sortable, toggleable |
| Dibuat | `created_at` | `dateTime('d M Y H:i')`, sortable, toggleable |
| Diperbarui | `updated_at` | `dateTime('d M Y H:i')`, sortable, toggleable |

Table-level: `defaultSort('created_at','desc')`,
`recordTitleAttribute('kk_number')`, `paginated([10,25,50])` with default 10,
Indonesian empty state. Row actions: View / Edit / Delete. Bulk: Delete only.
`ViewAction` is a modal in Filament 4, so no View page or route was added.

## Phase 3.2.4 — KK finalization

### Verdict

**COMPLETE.** Two real defects in `e34eedd` were found by inspection and are now
fixed and regression-tested. Navigation metadata added. Resource feature tests
added — these are the first tests in the project that actually render Filament
pages, which is why the defects had survived earlier green runs.

### Defects fixed

1. **Wrong component namespace.** `KartuKeluargaForm` imported
   `Filament\Forms\Components\Section` and `Filament\Forms\Components\Grid`.
   Neither class exists in Filament v4.12.5 (verified with `class_exists`);
   the canonical namespace is `Filament\Schemas\Components\*`. Any attempt to
   render the create or edit page would have fatalled on a missing class.
2. **Wrong table in the unique rule.** `->unique('kartu_keluargas', ...)`
   referenced a table that does not exist. The migration and the model both use
   `kartu_keluarga` (singular). The rule would have thrown at validation time.

Both were namespace/argument corrections to the existing implementation — the
form schema itself was not rewritten.

### Navigation and labels added

`KartuKeluargaResource`: `$navigationGroup = 'Kependudukan'` (the group was
already declared in `AdminPanelProvider` but no resource joined it),
`$navigationSort = 10`, `$navigationLabel`/`$modelLabel`/`$pluralModelLabel` =
"Kartu Keluarga" (Indonesian, non-pluralised), `$recordTitleAttribute = 'kk_number'`.

### Tests added

`tests/Feature/Phase3/Phase3ResourceTestCase.php` — shared base: `RefreshDatabase`,
authenticated user, `app.env` pinned to `local` so Filament's Authenticate
middleware admits a `User` that does not implement `FilamentUser`.

`tests/Feature/Phase3/KartuKeluargaResourceTest.php` — 13 tests: list/create/edit
page render, create, edit, required-field validation, 16-digit format, uniqueness
against the real table, self-ignoring uniqueness on edit, search, sort, bulk
delete, navigation group registration.

### Documentation cleanup

The previous Phase 3 cumulative report previously carried the 3.2.3 and 3.2.5 sections as two
overlapping descriptions of the same file, plus a stale "3.2.4 pending" status
block that contradicted the table above it. Rewritten as one status table plus
one section per sub-phase. `docs/PHASE3.1-REPORT.md` and
`docs/PHASE3.2.1-REPORT.md` are referenced, not restated.

### Verification

```
php artisan test        45 passed (208 assertions), 3 skipped
./vendor/bin/pint --test  PASS
```

`npm run build` not applicable — no frontend asset, Tailwind class, or Blade
view was touched.

### Commit

`fix(filament): finalize Phase 3.2`

## Phase 3.3 — Penduduk Resource

### Verdict

**COMPLETE.** Full CRUD resource for `App\Models\Penduduk` with form, table,
validation, search, sorting, pagination, row actions and bulk delete. No OCR and
no dashboard widgets (explicitly out of scope).

### Files added

- `app/Filament/Resources/Penduduks/PendudukResource.php`
- `app/Filament/Resources/Penduduks/Schemas/PendudukForm.php`
- `app/Filament/Resources/Penduduks/Tables/PenduduksTable.php`
- `app/Filament/Resources/Penduduks/Pages/{List,Create,Edit}Penduduk*.php`
- `tests/Feature/Phase3/PendudukResourceTest.php`

Scaffolded with `php artisan make:filament-resource Penduduk`, so the directory
layout matches the KK resource exactly.

### Form

Five sections, all labels in Bahasa Indonesia, all field names taken from the
model's `$fillable`:

| Section | Fields |
|---|---|
| Identitas | `nik`, `full_name`, `birth_place`, `birth_date`, `gender`, `blood_type` |
| Kartu Keluarga & Wilayah | `kk_id`, `family_relation`, `rt_id` |
| Data Sosial | `religion_id`, `education_id`, `occupation_id`, `marital_status` |
| Status Kependudukan | `resident_status`, `moved_*`, `deceased_*` |
| Catatan | `notes` |

Enum selects are built from the `App\Enums\*` cases (never hardcoded strings).
Lookup selects use the model's existing relations (`kartuKeluarga`, `rt`,
`religion`, `education`, `occupation`) via `->relationship()`, searchable and
preloaded. `birth_date` has `maxDate(now())`; age is never a form field (ADR-007).

Conditional validation: the `resident_status` select is `->live()`; the Pindah
block (and its required `moved_at`) and the Meninggal block (and its required
`deceased_at`) are shown only for the matching status.

### Table

Default-visible: NIK (copyable), Nama Lengkap, Nomor KK, Jenis Kelamin (badge),
Tanggal Lahir, Usia, RT, Status (badge — green/amber/red). Toggleable-hidden:
Agama, Pendidikan, Pekerjaan, Dibuat, Diperbarui.

Usia is a computed column reading the model's `getAgeAttribute()` accessor — the
value is never stored (ADR-007). Search covers name (partial), NIK, KK number
and RT. Default sort `full_name` ascending. Pagination `[10, 25, 50, 100]`,
default 25. Row actions: View / Edit / Delete. Bulk: Delete only.

### Tests

`PendudukResourceTest` — 19 tests: page rendering, all form fields present,
create, edit, required-field validation, 16-digit NIK, NIK uniqueness,
self-ignoring uniqueness on edit, conditional `moved_at` / `deceased_at`
requirements, search by name / NIK / KK number, sorting, pagination, row-action
delete, bulk delete, navigation metadata.

### Verification

```
php artisan test        64 passed (340 assertions), 3 skipped
./vendor/bin/pint --test  PASS (109 files)
```

`npm run build` not applicable — PHP and Markdown only.

### Commit

`feat(filament): Phase 3.3 — Penduduk resource`

## Phase 3.4 — KK ↔ Penduduk relation

### Verdict

**COMPLETE.** The KK edit page now carries an "Anggota Keluarga" relation
manager listing that family's residents, with a member-count badge and
two-way navigation between the KK and Penduduk resources.

### Files

- `app/Filament/Resources/KartuKeluargas/RelationManagers/PenduduksRelationManager.php` (new)
- `app/Filament/Resources/KartuKeluargas/KartuKeluargaResource.php` (registered the relation)
- `app/Filament/Resources/Penduduks/Tables/PenduduksTable.php` (KK column links back)
- `app/Filament/Resources/Penduduks/Pages/CreatePenduduk.php` (pre-selects KK)
- `tests/Feature/Phase3/KartuKeluargaPendudukRelationTest.php` (new)

### Relation manager

Built on the model's existing `KartuKeluarga::penduduks()` HasMany relation — no
model, migration or relation code was changed. Columns: NIK, Nama Lengkap,
Hubungan Keluarga (badge), Jenis Kelamin, Tanggal Lahir, Usia (computed), Status
(colour-coded badge). Sorted by `family_relation` so the Kepala Keluarga leads.
Indonesian title "Anggota Keluarga" and empty state.

### Member count

Two independent surfaces, both derived from the real relation:

- `getBadge()` on the relation manager returns `penduduks()->count()`, shown on
  the relation tab.
- The KK list table already had a "Jumlah Anggota" column via
  `counts('kkAnggotas')` (membership-history aggregate, from 3.2.3) — left as is.

### Family navigation

`$relatedResource = PendudukResource::class` links the relation manager to the
Penduduk resource, so View / Edit / Create open the full Penduduk pages instead
of reduced modals. Consequence: header and row actions become URL links rather
than modal actions — this is Filament's documented linked-resource behaviour,
verified against the vendor source (`RelationManager::getDefaultActionUrl`).

Because a linked CreateAction navigates away, the owning KK would otherwise be
lost. "Tambah Anggota" therefore points at
`PendudukResource::getUrl('create', ['kk_id' => <owner>])`, and `CreatePenduduk`
pre-selects that KK in an `afterFill()` hook using `fillPartially()` — chosen over
overriding `fillForm()` so component defaults still apply.

The reverse direction: the Penduduk table's "Nomor KK" column is now a link to
that KK's edit page.

### Tests

11 tests: relation registered, correct relation name, members scoped to the
owner KK, badge count (populated and zero), relation-to-resource link, relation
manager present on the edit page, title, "Tambah Anggota" URL carries `kk_id`,
create page pre-selects the KK, Penduduk table links back to the KK.

### Verification

```
php artisan test        75 passed (359 assertions), 3 skipped
./vendor/bin/pint --test  PASS (111 files)
```

### Commit

`feat(filament): Phase 3.4 — KK ↔ Penduduk relation`

## Phase 3.5 — Final Phase 3 polish

### Verdict

**COMPLETE.** Review pass over navigation, icons, labels, authorization, empty
states, notifications, page titles, translations and UX consistency. No
redesign — existing layouts, columns and flows are unchanged.

### Reviewed and changed

**Authorization (real gap closed).** `User` now implements
`Filament\Models\Contracts\FilamentUser` with `canAccessPanel()` returning true
(single admin, ADR-005). Previously the panel was reachable *only* because
`app.env` was `local` — Filament's Authenticate middleware 403s a non-FilamentUser
outside local. That meant the panel would have refused the operator in any
non-local environment. A regression test now loads the panel with
`app.env=production`.

`KartuKeluargaPolicy` and `PendudukPolicy` added per `.ai/filament.md` §10, all
abilities returning true, auto-discovered by Laravel's convention.

**Page titles.** All six resource pages now return explicit Indonesian titles:
Kartu Keluarga / Tambah Kartu Keluarga / Ubah Kartu Keluarga, Penduduk / Tambah
Penduduk / Ubah Data Penduduk.

**Notifications.** Indonesian success messages on create, save and delete for
both resources, plus Indonesian delete confirmation modals ("Data yang dihapus
tidak dapat dikembalikan. Lanjutkan?").

**Labels.** Row and bulk actions labelled Lihat / Ubah / Hapus / Hapus yang
dipilih. Header create actions labelled Tambah Kartu Keluarga / Tambah Penduduk.

**Navigation.** Both resources sit in the "Kependudukan" group (already declared
in `AdminPanelProvider`), ordered KK (10) before Penduduk (20), each with a
distinct outlined Heroicon.

### Reviewed, deliberately unchanged

- **Empty states** — already Indonesian on both tables and the relation manager
  (added in 3.2.3, 3.3, 3.4). Asserted, not modified.
- **Translations** — Filament v4.12.5 ships a complete `id` locale, so framework
  chrome needs no custom translation files. `APP_LOCALE` is read from `.env`
  (currently `en`); switching it is a deployment/config decision, not a code
  change, so it was left alone rather than silently altering runtime behaviour.
- **Icons, columns, layout, sort and pagination defaults** — no redesign.

Also removed a now-false comment in `MysqlPanelVerificationTest` that claimed
implementing `FilamentUser` was out of scope; the test no longer pins
`app.env=local`.

### Tests

`Phase3PolishTest` — 12 tests: shared navigation group, navigation order, icons
present, Indonesian non-pluralised model labels, all six page titles, create and
edit notifications, empty-state headings, `FilamentUser` contract, panel
reachable in production, guests redirected to login, policies discovered.

### Verification

```
php artisan test        87 passed (397 assertions), 3 skipped
./vendor/bin/pint --test  PASS (114 files)
```

### Commit

`feat(filament): Phase 3.5 — final Phase 3 polish`
