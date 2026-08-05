| Field | Value |
|---|---|
| **Title** | SIPETA Phase 3 — Cumulative Report |
| **Purpose** | Track Phase 3 (Filament CRUD) sub-phase progress across the KartuKeluarga and Penduduk resources. |
| **Scope** | Phase 3.1–3.5: Filament admin panel resources, forms, tables, relations and polish. |
| **Version** | 2.0.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-06 |
| **Related Documents** | `.ai/hermes.md`, `.ai/filament.md`, `docs/PHASE3.1-REPORT.md`, `docs/PHASE3.2.1-REPORT.md`, `app/Filament/Resources/` |

---

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

*Full report: `docs/PHASE3.1-REPORT.md`.* Panel boots at `/admin`, login route
registered, branding and navigation-group skeleton in place, verified against
real MySQL via an env-gated test (`RUN_MYSQL_TESTS=1`).

## Phase 3.2.1 — KK Resource scaffold

*Full report: `docs/PHASE3.2.1-REPORT.md`.* Generated the `KartuKeluarga`
Filament Resource against the existing `App\Models\KartuKeluarga`. No models,
migrations, factories, Penduduk or OCR code created.

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

`docs/PHASE3-REPORT.md` previously carried the 3.2.3 and 3.2.5 sections as two
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
