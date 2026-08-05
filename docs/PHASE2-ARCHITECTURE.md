| Field | Value |
| --- | --- |
| **Title** | SIPETA Phase 2 — Database Architecture (Final, decisions locked) |
| **Purpose** | Final database architecture for Phase 2: entity list, ERD, relationships, normalization, index/FK strategy, migration order, risk analysis. NO models/seeders/Filament yet. |
| **Scope** | Database foundation only. No CRUD, Resources, pages, OCR code, Tauri, auth changes. |
| **Version** | 0.3.0-final |
| **Status** | Approved — final architectural decisions locked; Phase 2.2 (migrations) in progress per user execution plan |
| **Last Updated** | 2026-08-05 |
| **Related Documents** | `.ai/database.md`, `.ai/decisions.md` (ADR-004/006/007/008/010/020), `.ai/architecture.md`, `.ai/ocr.md`, `.ai/project-rules.md`, `docs/REQUIREMENTS.md`, `docs/PHASE1-REPORT.md` |

---

# SIPETA — Phase 2 Database Architecture

## 0. Status & Stop Condition

This document is the **Phase 2.1 (Architecture)** deliverable, now at **Final** after the user's APPROVED WITH FINAL ARCHITECTURAL DECISIONS. Per the approved workflow:

```
Architecture (2.1)  →  Approval  →  Migration (2.2)  →  Seeder (2.3)
                   →  Model Relations (2.4)  →  Database Testing (2.5)
```

Phase 2.2 execution rule (from the user): **one migration at a time**; after each — verify, review, explain, commit. Do NOT generate models, seeders, Filament Resources, or CRUD. Stop after all migrations are complete. Push only after all migrations verified. Wait for user approval before Phase 3.

---

## 1. Reconciliation With the Already-Approved Schema

`.ai/database.md` (approved) defines 4 production tables: `kartu_keluarga`, `penduduk`, `settings`, `backup_logs`. Your Phase 2 prompt adds requirements that schema does **not** fully satisfy. Resolution:

| New requirement | Gap in approved schema | Resolution in this design | Flag |
| --- | --- | --- | --- |
| Old KK photo archived, only newest active | `kk_photo_path` single column | `kk_photos` table (1 KK → many, `is_active`) | In scope |
| OCR metadata (status, confidence, raw, corrected, operator, source image) | `ocr.md §8` stateless | `ocr_jobs` = **log/audit only**; pipeline stays stateless | Approved |
| Photo storage full metadata set | Only `kk_photo_path` | columns on `kk_photos` (decision: file storage) | In scope |
| Audit who/when/what/old/new | `audit_logs` backlog | `audit_logs` table (morphic) | Approved |
| Blood type | none | `blood_type` ENUM on `penduduk` | Gap fix |
| Move destination | only `moved_note` | `moved_destination` varchar | Gap fix |
| **Religion/Education/Occupation** | ENUM / free VARCHAR | **Master tables** `religions`, `educations`, `occupations` | Refinement (Q4) |
| **RT/RW/Lingkungan** | raw `rt`/`rw`/`lingkungan` strings | **Flexible `area_units` + `rts`** (not hardcoded Lingkungan→RW→RT) | Refinement |
| **KK re-issue history** | `penduduk.kk_id` single FK | `kk_anggota` membership-history table + new KK row per new number | Q1 |

---

## 2. Final Entity List (13 domain tables + base)

Base tables already created in Phase 1 (Laravel/Filament): `users`, `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `password_reset_tokens`, `sessions`.

Domain tables for Phase 2 (13):

1. **`settings`** — singleton kelurahan identity + backup path.
2. **`kartu_keluarga`** — household unit (KK). One row per KK number.
3. **`penduduk`** — individual resident. Belongs to exactly one *current* KK; lives in one RT.
4. **`kk_anggota`** — KK membership history (resident ↔ KK over time, with effective date).
5. **`kk_photos`** — KK photo archive (versioned, `is_active`).
6. **`ocr_jobs`** — OCR attempt log + extracted-data snapshot (audit only).
7. **`backup_logs`** — append-only backup history.
8. **`audit_logs`** — morphic who/when/what/old/new trail.
9. **`religions`** — lookup master (agama).
10. **`educations`** — lookup master (pendidikan).
11. **`occupations`** — lookup master (pekerjaan).
12. **`area_units`** — flexible area Level 1 (Lingkungan *or* RW, per local admin).
13. **`rts`** — RT; always belongs to exactly one `area_units`.

`users` holds the single admin; `audit_logs.actor_*`, `ocr_jobs.operator_id`, `kk_photos.uploaded_by`, `backup_logs.operator_id` reference `users.id`.

---

## 3. ERD

```mermaid
erDiagram
    users ||--o{ audit_logs : "actor"
    users ||--o{ ocr_jobs : "operator"
    users ||--o{ kk_photos : "uploaded_by"
    users ||--o{ backup_logs : "operator"

    settings ||--|| settings : "singleton (app-enforced)"

    kartu_keluarga ||--o{ penduduk : "current members"
    kartu_keluarga ||--o{ kk_anggota : "membership history"
    kartu_keluarga ||--o{ kk_photos : "versioned photos"
    kartu_keluarga ||--o{ ocr_jobs : "ocr attempts"
    kartu_keluarga ||--o{ audit_logs : "logged"

    penduduk ||--o{ kk_anggota : "membership history"
    penduduk ||--o{ audit_logs : "logged"

    religions ||--o{ penduduk : "religion"
    educations ||--o{ penduduk : "education"
    occupations ||--o{ penduduk : "occupation"

    area_units ||--o{ rts : "contains"
    rts ||--o{ penduduk : "rt"

    kartu_keluarga {
        bigint id PK
        varchar kk_number UK "16 digits"
        varchar address
        varchar postal_code "nullable"
        text notes "nullable"
        timestamp created_at
        timestamp updated_at
    }

    penduduk {
        bigint id PK
        bigint kk_id FK "current KK"
        varchar nik UK "16 digits"
        varchar full_name
        enum gender "LAKI_LAKI, PEREMPUAN"
        varchar birth_place
        date birth_date
        bigint religion_id FK "-> religions"
        bigint education_id FK "-> educations"
        bigint occupation_id FK "-> occupations"
        enum marital_status "BELUM_KAWIN, KAWIN, CERAI_HIDUP, CERAI_MATI"
        enum family_relation "KEPALA_KELUARGA, ISTRI, ANAK, MENANTU, CUCU, ORANG_TUA, MERTUA, FAMILI_LAIN, LAINNYA"
        enum blood_type "A, B, AB, O, TIDAK_DIKETAHUI"
        enum resident_status "ACTIVE, PINDAH, MENINGGAL"
        bigint rt_id FK "-> rts"
        date moved_at "nullable"
        varchar moved_destination "nullable"
        text moved_note "nullable"
        date deceased_at "nullable"
        text deceased_note "nullable"
        text notes "nullable"
        timestamp created_at
        timestamp updated_at
    }

    kk_anggota {
        bigint id PK
        bigint kk_id FK "-> kartu_keluarga"
        bigint penduduk_id FK "-> penduduk"
        enum family_relation "same enum as penduduk"
        enum status "AKTIF, KELUAR"
        date effective_date "member since"
        date end_date "nullable, left KK"
        timestamp created_at
        timestamp updated_at
    }

    kk_photos {
        bigint id PK
        bigint kk_id FK
        varchar original_filename
        varchar stored_filename
        varchar thumbnail_filename "nullable"
        varchar mime_type
        bigint file_size
        char sha256_hash "SHA-256, 64"
        varchar storage_disk "e.g. local"
        varchar storage_path "relative"
        enum photo_type "KK_PHOTO, RESIDENT_PHOTO" "default KK_PHOTO"
        boolean is_active "default true"
        bigint uploaded_by "FK users, nullable"
        timestamp uploaded_at
        bigint ocr_job_id "FK ocr_jobs, nullable"
        timestamp created_at
        timestamp updated_at
    }

    ocr_jobs {
        bigint id PK
        bigint kk_id "FK, nullable, SET NULL"
        char source_image_hash "perceptual, 64"
        varchar source_image_path
        enum status "PENDING, SUCCESS, LOW_CONFIDENCE, FAILED, CANCELLED"
        decimal confidence "nullable, 0-100"
        longtext raw_text "nullable"
        longtext corrected_text "nullable"
        json extracted_data "nullable"
        bigint operator_id "FK users, nullable"
        timestamp reviewed_at "nullable"
        enum outcome "SAVED, DISCARDED, MANUAL, null"
        text error_message "nullable"
        timestamp started_at
        timestamp finished_at "nullable"
        timestamp created_at
        timestamp updated_at
    }

    backup_logs {
        bigint id PK
        varchar filename UK
        enum backup_type "MANUAL, SCHEDULED"
        enum backup_status "SUCCESS, FAILED"
        bigint backup_size
        bigint operator_id "FK users, nullable"
        timestamp started_at
        timestamp finished_at "nullable"
        text message "nullable"
        timestamp created_at
    }

    audit_logs {
        bigint id PK
        varchar loggable_type "morphic"
        bigint loggable_id
        varchar actor_type "morphic, nullable"
        bigint actor_id "nullable"
        varchar event "created, updated, status_changed, restored"
        json old_values "nullable"
        json new_values "nullable"
        varchar ip_address "nullable"
        timestamp created_at
    }

    settings {
        bigint id PK
        varchar kelurahan_name
        varchar kecamatan_name
        varchar kabupaten_name
        varchar province_name
        varchar logo_path "nullable"
        varchar backup_path
        timestamp created_at
        timestamp updated_at
    }

    religions {
        bigint id PK
        varchar name
        timestamp created_at
        timestamp updated_at
    }

    educations {
        bigint id PK
        varchar name
        timestamp created_at
        timestamp updated_at
    }

    occupations {
        bigint id PK
        varchar name
        timestamp created_at
        timestamp updated_at
    }

    area_units {
        bigint id PK
        varchar name "display label, e.g. Lingkungan I / RW 01"
        varchar type "nullable config hint: lingkungan | rw"
        varchar code "nullable short code, e.g. I | 01"
        timestamp created_at
        timestamp updated_at
    }

    rts {
        bigint id PK
        bigint area_unit_id FK "-> area_units"
        varchar number "e.g. 01"
        timestamp created_at
        timestamp updated_at
    }
```

---

## 4. Relationship Explanation

- **`kartu_keluarga` → `penduduk` (1-to-many, current).** `penduduk.kk_id` points to the resident's *current* KK. FK `ON DELETE RESTRICT`, `ON UPDATE CASCADE`.
- **`kartu_keluarga` ↔ `penduduk` via `kk_anggota` (membership history).** Every resident↔KK association is a row with `effective_date` and (when left) `end_date` + `status = KELUAR`. The `penduduk.kk_id` value is kept in sync with the row where `status = AKTIF` by the Service layer. This preserves the old KK↔resident link when a resident is reassigned to a new KK (new number) per Q1. Both FKs `ON DELETE RESTRICT`.
- **`kartu_keluarga` → `kk_photos` (1-to-many, versioned).** A KK may have several photos; exactly one `is_active = true`. Replacing inserts a new row and flips `is_active` — old photo never deleted.
- **`kartu_keluarga` → `ocr_jobs` (1-to-many, optional).** Each OCR attempt logged. FK nullable + `SET NULL` so an audit row survives even if its KK is removed-in-error. Snapshot only; NOT source of truth.
- **`penduduk` → `religions` / `educations` / `occupations` (many-to-one).** Evolving taxonomies are masters, not ENUMs (Q4). All FKs `ON DELETE RESTRICT`.
- **`area_units` → `rts` → `penduduk` (flexible region hierarchy).** `penduduk.rt_id` FK → `rts`; `rts.area_unit_id` FK → `area_units`. The `area_units.type` column carries the local admin label (Lingkungan / RW) so the same schema serves Kelurahan Tanete (Lingkungan→RT) and other kelurahan (RW→RT) **without schema change**. Region FKs `ON DELETE RESTRICT`.
- **`users` → `ocr_jobs` / `kk_photos` / `backup_logs` / `audit_logs` (operator/actor).** Nullable — system actions have no human actor.
- **`audit_logs` (morphic).** `loggable_type` + `loggable_id` point to the changed row. No hard FK — audit outlives the row. Written by Laravel Model Observers (Q3) in the application phase, NOT here.
- **`settings` (singleton).** One row, enforced by Service layer (`firstOrCreate(['id'=>1])`); MySQL has no partial-unique, so app-level guard (ADR-020).
- **`penduduk.resident_status`** is an independent ENUM (`ACTIVE`/`PINDAH`/`MENINGGAL`), not a FK. Status flips preserve history; no physical delete.

---

## 5. Normalization Analysis

- **1NF:** All columns atomic. ✓
- **2NF:** Non-key attributes fully dependent on the PK. Region/lookup names live in their own masters (no partial dependency on `penduduk`). ✓
- **3NF:** No transitive dependency — descriptive names are in masters, referenced by FK. ✓
- **BCNF:** Every determinant is a candidate key. ✓
- **ENUMs retained only for fixed national values (Q4):** `gender`, `blood_type`, `marital_status`, `family_relation`, `resident_status`. These almost never change and are not operator-managed picklists.
- **Masters used for evolving values (Q4):** `religions`, `educations`, `occupations`, `area_units`, `rts`. Adding/renaming a value is a data change, never a migration or column-type change (Filament `Select::relationship()`).

---

## 6. Future Scalability Analysis

- **Volume:** Tens of thousands of residents. Within InnoDB limits. No partitioning before ~200K rows.
- **Filters:** RT (via `penduduk.rt_id`), Area Level 1 (join `rts→area_units`), Gender, Religion, Education, Occupation, Status, Age-range, Name, NIK, KK — all indexed + `WHERE`. No app-side filtering.
- **Age:** Never stored. Computed via `Carbon::parse($birth_date)->age`. Index on `birth_date`.
- **OCR / Audit growth:** `ocr_jobs.raw_text` LONGTEXT (list views lightweight only); `audit_logs` append-only, indexed.
- **Multi-PC / cloud (backlog):** morphic `audit_logs` + `ocr_jobs.kk_id SET NULL` tolerate row deletion.
- **Resident photo (future):** `kk_photos.photo_type` accommodates `RESIDENT_PHOTO`; adding `resident_id` FK is a single additive migration.
- **Dashboard stats (decision #5):** computed entirely from business tables at query time. App file-cache (5-min TTL) allowed; NO statistics column stored in DB.
- **Portability (decision: area structure):** same schema works for any kelurahan regardless of whether Area Level 1 is Lingkungan or RW.

---

## 7. Index Strategy

**`kartu_keluarga`** — `UNIQUE (kk_number)`; `INDEX (address)`.

**`penduduk`**
- `UNIQUE (nik)`
- `INDEX (kk_id)`, `INDEX (full_name)`, `INDEX (resident_status)`, `INDEX (gender)`, `INDEX (birth_date)`
- `INDEX (rt_id)` — filter + dashboard per RT
- `INDEX (religion_id)`, `INDEX (education_id)`, `INDEX (occupation_id)`
- `INDEX (kk_id, resident_status)`
- `INDEX (blood_type)`

**`kk_anggota`** — `INDEX (kk_id)`, `INDEX (penduduk_id)`, `INDEX (status)`, `INDEX (effective_date)`.

**`kk_photos`** — `INDEX (kk_id, is_active)`; `INDEX (sha256_hash)`; `INDEX (ocr_job_id)`; `INDEX (uploaded_by)`.

**`ocr_jobs`** — `INDEX (kk_id)`; `INDEX (source_image_hash)`; `INDEX (status, created_at)`.

**`backup_logs`** — `UNIQUE (filename)`; `INDEX (backup_status, created_at)`; `INDEX (started_at)`.

**`audit_logs`** — `INDEX (loggable_type, loggable_id)`; `INDEX (actor_id)`; `INDEX (created_at)`.

**`settings`** — PK only.

**Masters `religions`/`educations`/`occupations`** — `UNIQUE (name)`.
**`area_units`** — `UNIQUE (name)`; `INDEX (type)`.
**`rts`** — `UNIQUE (area_unit_id, number)`.

---

## 8. Foreign Key Strategy

- All FKs `BIGINT UNSIGNED`, InnoDB, `utf8mb4_unicode_ci`.
- **ON UPDATE:** `CASCADE` everywhere (surrogate keys never change; identical behavior to RESTRICT, conventional Laravel default). Explicit on every FK per decision #6.
- **ON DELETE:**
  - `penduduk.kk_id` → `RESTRICT`
  - `kk_anggota.kk_id` → `RESTRICT`
  - `kk_anggota.penduduk_id` → `RESTRICT`
  - `penduduk.religion_id` → `RESTRICT`
  - `penduduk.education_id` → `RESTRICT`
  - `penduduk.occupation_id` → `RESTRICT`
  - `penduduk.rt_id` → `RESTRICT`
  - `rts.area_unit_id` → `RESTRICT`
  - `kk_photos.kk_id` → `RESTRICT`
  - `kk_photos.ocr_job_id` → `SET NULL`
  - `kk_photos.uploaded_by` → `SET NULL`
  - `ocr_jobs.kk_id` → `SET NULL`
  - `ocr_jobs.operator_id` → `SET NULL`
  - `backup_logs.operator_id` → `SET NULL`
  - `audit_logs` → no hard FK (morphic).
- **No KK number or NIK as FK** (string business keys; integer surrogate `id` is the only FK target) — per `database.md §9`.
- DB user `sipeta_app` has `SELECT/INSERT/UPDATE/DELETE` on schema only; no `DROP/GRANT/CREATE USER` (ADR-004).

---

## 9. Naming Convention (from `database.md §9`, reused + extended)

- Tables: `snake_case`, plural where natural (`kartu_keluarga`, `penduduk`, `kk_anggota`, `kk_photos`, `ocr_jobs`, `backup_logs`, `audit_logs`, `religions`, `educations`, `occupations`, `area_units`, `rts`).
- Columns: `snake_case`. PK: `id` `BIGINT UNSIGNED`. FK: `<parent_singular>_id` (`kk_id`, `rt_id`, `religion_id`, `area_unit_id`).
- Unique: descriptive (`kk_number`, `nik`, `filename`, `sha256_hash`, `name`).
- Timestamps: `created_at`, `updated_at` on every table **except intentionally append-only** (`audit_logs`, `backup_logs` → `created_at` only).
- DB enums: `UPPER_SNAKE` (`ACTIVE`, `LAKI_LAKI`). PHP enums: PascalCase class + `UPPER_SNAKE` cases.
- Models: Singular PascalCase (`KartuKeluarga`, `Penduduk`, `KkAnggota`, `KkPhoto`, `OcrJob`, `BackupLog`, `AuditLog`, `Setting`, `Religion`, `Education`, `Occupation`, `AreaUnit`, `Rt`).
- NEVER use KK number or NIK as a foreign key.

---

## 10. Migration Order

Run after Laravel base migrations. Each migration is its own file with a `down()` rollback.

1. `create_settings_table` — no dependencies (singleton seed in 2.3).
2. `create_religions_table` — no dependencies.
3. `create_educations_table` — no dependencies.
4. `create_occupations_table` — no dependencies.
5. `create_area_units_table` — no dependencies.
6. `create_rts_table` — depends on `area_units`.
7. `create_kartu_keluarga_table` — no dependencies (rt/rw/lingkungan strings removed).
8. `create_ocr_jobs_table` — depends on `kartu_keluarga`, `users` (`kk_id` nullable + `SET NULL`).
9. `create_penduduk_table` — depends on `kartu_keluarga`, `religions`, `educations`, `occupations`, `rts`.
10. `create_kk_anggota_table` — depends on `kartu_keluarga`, `penduduk`.
11. `create_kk_photos_table` — depends on `kartu_keluarga`, `users`, `ocr_jobs`.
12. `create_backup_logs_table` — depends on `users`.
13. `create_audit_logs_table` — no dependencies (morphic).

**Ordering constraints:** `kk_photos.ocr_job_id` hard FK to `ocr_jobs` → `ocr_jobs` (8) before `kk_photos` (11). `penduduk` (9) before `kk_anggota` (10). All masters (2–5) + `rts` (6) before `penduduk` (9). FKs to `users` rely on Phase 1 `users` table.

---

## 11. Potential Risks

| # | Risk | Impact | Mitigation |
| --- | --- | --- | --- |
| R1 | `ocr_jobs` + `audit_logs` beyond original KKN boundary | More code/tests | **Approved.** Audit-only, low risk. |
| R2 | Conflict with `ocr.md §8` stateless | Contradiction | Resolved: `ocr_jobs` logs + snapshot; pipeline still does NOT auto-write form data. |
| R3 | MariaDB 11.8 vs MySQL 8.0 semantics | Minor DDL differences | Both InnoDB/`utf8mb4`/`JSON`/`ENUM`. Avoid MySQL-8-only syntax. |
| R4 | `kk_photos` "active" invariant | Two active rows via race | App-level transaction in Service; optional future partial-unique emulation — deferred. |
| R5 | `raw_text`/`corrected_text` LONGTEXT bloat | Disk growth | Only on OCR; never in list views; optional GC. |
| R6 | `blood_type` / `moved_destination` late | Alter later | Included in initial `penduduk` migration. |
| R7 | KK re-issue semantics | Duplicate `kk_number` | **Resolved (Q1):** new number → new row; old archived (`kk_number` UNIQUE); `kk_anggota` preserves old link. No `superseded_by`. |
| R8 | Region schema too rigid | Rework per kelurahan | **Resolved (final):** `area_units` (type = lingkungan|rw) + `rts` is kelurahan-agnostic. No hardcoded Lingkungan→RW→RT. |
| R9 | Lookup value retire/delete | `RESTRICT` blocks delete of used value | Optional future `is_active` on masters; not added now (keep minimal). Operator retires by leaving unused. |

---

## 12. Final Architectural Decisions (locked)

- **Area structure:** flexible `area_units` (type: lingkungan|rw) + `rts`; `penduduk.rt_id → rts`. NOT hardcoded Lingkungan→RW→RT. Portable across kelurahan.
- **KK history:** new KK number → new `kartu_keluarga` row; previous archived; `kk_anggota` preserves relationships. No `superseded_by`.
- **Lookup tables:** `religions`, `educations`, `occupations`, `area_units`, `rts`. Future master data extendable without schema change.
- **ENUMs (fixed):** `gender`, `blood_type`, `resident_status`, `family_relation`, `marital_status`.
- **`ocr_jobs`:** approved, audit only, never source of truth.
- **`audit_logs`:** approved; Model Observers later, no implementation now.
- **File storage:** full metadata on `kk_photos` (original_filename, stored_filename, thumbnail_filename, mime_type, file_size, sha256_hash, storage_disk, storage_path, uploaded_at, uploaded_by).
- **Data integrity:** never delete population/KK history; never store age (birth_date only); business status `ACTIVE`/`PINDAH`/`MENINGGAL`.
- **Conventions:** every table `id`+timestamps (except append-only); explicit `ON DELETE`/`ON UPDATE` on every FK; Laravel naming.

---

## 13. Alternative Designs Considered

- **A. Hardcoded `lingkungans` → `rws` → `rts`.** **Rejected (final):** administrative structure differs between kelurahan; would force schema change. Replaced by flexible `area_units` + `rts`.
- **B. `family_links` pivot (KK ↔ resident many-to-many).** **Rejected as primary model:** one current KK per resident; `penduduk.kk_id` + `kk_anggota` history is correct.
- **C. `status_history` table.** **Rejected for KKN:** event columns on resident row suffice.
- **D. Reference tables for every enum.** **Partially adopted (Q4):** evolving → masters; fixed → ENUM.
- **E. Single `kk_photo_path` column.** **Rejected:** cannot keep old photos archived.
- **F. Polymorphic central `files` table.** **Considered; deferred:** decision #2 enumerates file metadata on the upload record; generic table is optional future.
- **G. `superseded_by` on `kartu_keluarga`.** **Rejected (user instruction):** not added; history via archived rows + `kk_anggota`.

---

## 14. Reasons This Design Is Chosen

1. **Data integrity over convenience** (ADR-008): status flips; photo history; KK history; audit trail.
2. **Matches approved docs**; expands/refines only where explicitly required.
3. **Flexibility without over-build** (Q4 + area structure): evolving taxonomies/regions are data, not migrations.
4. **No destructive updates:** insert or status flip; `audit_logs` captures old/new.
5. **Future-proof:** `photo_type`, `SET NULL` OCR FK, morphic audit, `JSON` snapshot, `area_units`/`rts`, lookup masters.
6. **Performance:** every filter/search column indexed; age computed; long-text excluded from list queries.

---

## 15. Data Integrity Rules

- Population data > application code.
- If convenience conflicts with data integrity, choose data integrity.
- Never duplicate resident identity (NIK unique; KK number unique).
- Never lose historical records (status flips; archived photos; archived KK; `kk_anggota`; audit trail).
- Never design a table requiring destructive updates.
- Every major decision has written justification (this document + ADR).
- If unsure, stop and ask before designing.

---

## 16. Phase 2 Subdivision Plan (gated)

- **2.1 Architecture** — THIS document (Final). ✅ Approved.
- **2.2 Migration** — one migration per entity; verify/review/explain/commit per milestone. **IN PROGRESS.**
- **2.3 Seeder** — `settings` singleton, masters, enums, fixtures, admin user. (after approval)
- **2.4 Model Relations** — Eloquent models, `kk_anggota` relations, accessors (`age`), scopes (`active`), enums, Model Observers for `audit_logs`. (after approval)
- **2.5 Database Testing** — `migrate:fresh`, FK integrity, uniqueness, status-flip, KK re-issue history, seeder sanity. (after approval)

No step proceeds without the prior step's approval.

---

## 17. Revision Diff (v0.1.0-draft → v0.3.0-final)

**v0.2.0 (Revision 1):** added `kk_anggota`; master tables `religions`/`educations`/`occupations`; rejected ENUMs for religion/education/occupation (→ FKs); added `lingkungans`/`rws`/`rts` region hierarchy; `penduduk.rt_id`; full file metadata on `kk_photos`; `resident_status` → ACTIVE/PINDAH/MENINGGAL; explicit FK policy.

**v0.3.0 (Final) — area structure fix:** collapsed `lingkungans`/`rws` into a single **`area_units`** table with `type` (lingkungan|rw) + `code`; kept `rts` (`area_unit_id`, `number`). `penduduk.rt_id → rts` unchanged. Removed the three-level hardcoded hierarchy. Schema now kelurahan-agnostic.

**Added (net):** `kk_anggota`, `religions`, `educations`, `occupations`, `area_units`, `rts` (6 new tables).

**Removed columns:** `kartu_keluarga.rt/rw/lingkungan`.

**ENUMs retained:** `gender`, `blood_type`, `marital_status`, `family_relation`, `resident_status`.

**Status:** Final — approved with all decisions locked.
