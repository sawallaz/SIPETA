| Field | Value |
| --- | --- |
| **Title** | SIPETA Phase 5 — OCR |
| **Purpose** | Track Phase 5 (OCR) sub-phase progress. |
| **Scope** | 5.1 OCR upload foundation (upload validation, accepted file types, size limit, secure storage, upload status handling). OCR extraction, parsing, review UI, confidence highlighting, and duplicate detection land in later 5.x sub-phases. |
| **Version** | 1.0.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-06 |
| **Related Documents** | `.ai/ocr.md`, `.ai/decisions.md` (ADR-009, ADR-016, ADR-017), `docs/PHASE4.md`, `docs/REQUIREMENTS.md` (§2.4, §5.4), `app/Services/KkDocumentUploadService.php`, `app/Models/OcrJob.php` |

---

# Phase 5 — OCR

## 5.1 OCR Upload Foundation

### 5.1.1 Objective

Build only the upload workflow required before OCR processing can run:
validate the incoming KK document, accept only supported file types, enforce
the size limit, store the file in a secure (private) location, and record the
upload as a PENDING OCR job. No OCR extraction, parsing, or recognition yet —
this phase is the foundation the pipeline consumes.

### 5.1.2 Deliverables

- **Upload service** (`app/Services/KkDocumentUploadService.php`, new —
  `App\Services\*` per ADR-016):
  - `upload(UploadedFile $file, ?User $operator = null): OcrJob` — validates,
    stores, and registers the upload; returns the new PENDING `ocr_jobs` row.
  - `validate(UploadedFile $file): void` — throws `ValidationException` on any
    rejection without persisting anything.
  - `rules(): array` — static, reusable validation rules for the future upload
    UI (controller / FormRequest / Filament action).
- **Upload validation** (NFR-SEC-05 — "Uploaded files validated by MIME type
  and size"; `.ai/ocr.md` §4.1):
  - Accepted file types: JPG, JPEG, PNG — enforced both by extension
    (`mimes:jpg,jpeg,png`) and by content MIME sniffing
    (`mimetypes:image/jpeg,image/png`), so a text file renamed to `.jpg` is
    rejected.
  - Maximum size: 5 MB (`max:5120` KB), per `.ai/ocr.md` §4.1.
- **Secure storage location**: files are stored on the private local `kk_uploads`
  disk (`storage/app/kk_uploads`, `visibility = 'private'`; registered in the
  Phase 1.5 storage layout). Stored name is a UUID with the validated extension;
  the original client filename is never used for storage.
- **Upload status handling** — an accepted upload creates an `ocr_jobs` row
  with `status = PENDING`, `kk_id = null` (the KK record does not exist yet at
  upload time; `kk_id` is nullable by schema), `operator_id` = uploading user,
  `started_at` = now, and `source_image_hash` = SHA-256 of the stored file (the
  seed for FR-OCR-05 duplicate detection in a later sub-phase). On rejection
  nothing is written: no file on disk, no job row.

### 5.1.3 Not done (explicitly out of scope for 5.1)

- No OCR extraction, Tesseract invocation, AI recognition, or text parsing.
- No resolution validation (min 800×600 / max 4000×4000) — that belongs to the
  preprocessing stage of the pipeline (`.ai/ocr.md` §4.1), not the upload gate.
- No duplicate-upload warning (FR-OCR-05) — the hash is recorded now, the
  detection logic is a pipeline sub-phase.
- No upload UI (Filament page/action/controller) — the service API is the
  contract; the operator-facing upload screen ships with the OCR processing
  sub-phase.
- No queue workers, no temp-file GC, no `kk_photos` rows (that archive is
  populated only once a KK record exists and the operator saves).
- No dashboard changes; Phase 4 remains frozen.
- No new database fields, migrations, or schema changes — the existing
  `ocr_jobs` table fully covers upload recording.

### 5.1.4 Files changed (5.1 only)

| File | Change |
| --- | --- |
| `app/Services/KkDocumentUploadService.php` | New — upload service (validation, secure storage, PENDING job). |
| `tests/Feature/Phase5/KkDocumentUploadServiceTest.php` | New — 6 tests covering accept / reject / store. |
| `docs/PHASE5.md` | New — this document. |
| `docs/CHANGELOG.md` | Updated — Phase 5.1 entry; Version 1.7.0 → 1.8.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-12 (OCR upload foundation) added, status Implemented. |

### 5.1.5 Verification

```text
php artisan test        110 passed (514 assertions), 3 skipped
./vendor/bin/pint --test  PASS (129 files)
```

`npm run build` not applicable — no frontend asset changed (no `resources/css`,
`resources/js`, `vite.config`, or Blade view touched; pure PHP + docs).

### 5.1.6 Commit

`feat(ocr): Phase 5.1 — upload foundation`
