| Field | Value |
| --- | --- |
| **Title** | SIPETA Phase 5 — OCR |
| **Purpose** | Track Phase 5 (OCR) sub-phase progress. |
| **Scope** | 5.1 OCR upload foundation (upload validation, accepted file types, size limit, secure storage, upload status handling); 5.2 OCR processing pipeline foundation (start processing, load uploaded image, validate prerequisites, PENDING → PROCESSING → FAILED transitions); 5.3 OCR image preprocessing (image validation, EXIF orientation correction, grayscale conversion, resize/normalization, preprocessing result tracking); 5.4 OCR engine integration (Tesseract invocation, raw text extraction, confidence aggregation, failure/timeout handling, job status update, raw extracted text persistence); 5.5 OCR parsing and mapping (structured DTO, raw-text parsing into project-defined fields, confidence handling, and required-field validation); 5.6 OCR review and validation (resource page, operator-facing review form, parsed-field display, missing-required and low-confidence highlighting, manual correction, pre-approval validation gate — no persistence, no import); 5.7 Import Kartu Keluarga (import service persisting a validated review result, duplicate KK-number detection, transactional write, OCR job marked saved on success, import result DTO); 5.8 Import Penduduk (import service persisting the approved review members as Penduduk rows + active KkAnggota membership under the Phase 5.7 KK, duplicate NIK detection, transactional write, OCR job marked penduduk-imported on success, import result DTO); 5.9 OCR finalization (final status transition to COMPLETED, completion timestamp, import summary + final processing metrics, cleanup of the pipeline's transient artifacts, audit logging, centralized success/failure completion handler, idempotent completion). |
| **Version** | 1.8.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-07 |
| **Related Documents** | `.ai/ocr.md`, `.ai/decisions.md` (ADR-009, ADR-016, ADR-017), `docs/PHASE4.md`, `docs/REQUIREMENTS.md` (§2.4, §5.4), `app/Services/KkDocumentUploadService.php`, `app/Services/OcrProcessingService.php`, `app/Services/OcrParsingService.php`, `app/Services/ParsedOcrResult.php`, `app/Services/ParsedResident.php`, `app/Services/ImagePreprocessor.php`, `app/Services/PreprocessResult.php`, `app/Services/OcrEngine.php`, `app/Services/TesseractOcrEngine.php`, `app/Services/OcrResult.php`, `app/Services/OcrReviewService.php`, `app/Services/OcrReviewResult.php`, `app/Services/OcrImportService.php`, `app/Services/OcrImportResult.php`, `app/Services/PendudukImportService.php`, `app/Services/PendudukImportResult.php`, `app/Models/OcrJob.php`, `config/ocr.php`, `app/Filament/Resources/OcrJobs/OcrJobResource.php`, `app/Filament/Resources/OcrJobs/Pages/ReviewOcrJob.php` |

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

---

## 5.2 OCR Processing Pipeline

### 5.2.1 Objective

Build the processing pipeline foundation that prepares PENDING OCR jobs for
future extraction: start processing a job, load its uploaded source image,
validate processing prerequisites, and drive the status transitions
PENDING → PROCESSING → FAILED (when processing cannot continue). No OCR
recognition, Tesseract, AI vision, parsing, or queue workers yet — the
pipeline ends at a job that is ready for the extraction sub-phase.

### 5.2.2 Deliverables

- **Pipeline service** (`app/Services/OcrProcessingService.php`, new):
  - `start(OcrJob $job): OcrJob` — the single entry point:
    1. `assertStartable()` — rejects any job whose status is not PENDING
       (`InvalidArgumentException`; nothing persisted).
    2. Transitions the job to the `PROCESSING` runtime state.
    3. `loadUploadedImage()` — reads the source image from the private
       `kk_uploads` disk (same disk the 5.1 upload service writes to).
    4. On any prerequisite failure (`OcrProcessingException`) the job is
       persisted as `FAILED` with `error_message` and `finished_at`, then the
       exception re-surfaces to the caller.
- **Processing prerequisites** (`.ai/ocr.md` §4.2 groundwork):
  - Source image file exists on the `kk_uploads` disk.
  - File is non-empty and readable.
  - Content carries a supported image signature (JPEG `FF D8 FF` / PNG
    `89 50 4E 47 ...`) — format validation only, no decoding, no parsing.
- **Status transitions** — implemented exactly as scoped:
  ```text
  PENDING
      ↓
  PROCESSING        (runtime state — see note below)
      ↓
  FAILED            (persisted, when processing cannot continue)
  ```
- **PROCESSING persistence note** (design decision): the `ocr_jobs.status`
  column constraint from the Phase 2 migration (`SQLite CHECK` / `MySQL ENUM`)
  lists only `PENDING, SUCCESS, LOW_CONFIDENCE, FAILED, CANCELLED`. The
  `PROCESSING` value is not part of that constraint — so it is added to the PHP enum
  `OcrJobStatus` as a runtime state only, and **cannot be persisted yet**
  (verified: `CHECK constraint failed` on SQLite; MySQL ENUM would reject it
  identically). `OcrJobStatus::persistable()` documents the five statuses the
  column can currently store, and the factory now samples from that list so
  fixtures never attempt an illegal write. Widening the column constraint is a
  deliberate future schema change (requires a migration) and is out of scope
  for this phase.

### 5.2.3 Not done (explicitly out of scope for 5.2)

- No Tesseract, AI vision, OCR text extraction, or image parsing.
- No resolution/quality preprocessing (`.ai/ocr.md` §4.2) — that is a later
  pipeline stage.
- No queue workers, no async dispatch — processing is synchronous service
  code.
- No database writes outside `ocr_jobs` status updates (no `kk_photos` rows,
  no extracted data, no temp files).
- No new migrations, no schema changes, no new resources, no dashboard
  changes; Phase 4 and Phase 5.1 remain frozen.
- No `SUCCESS` / `LOW_CONFIDENCE` transitions — they belong to the extraction
  sub-phase that consumes this foundation.

### 5.2.4 Files changed (5.2 only)

| File | Change |
| --- | --- |
| `app/Services/OcrProcessingService.php` | New — processing pipeline (start, load image, prerequisites, FAILED handling). |
| `app/Exceptions/OcrProcessingException.php` | New — domain exception thrown when processing cannot continue (job already FAILED in DB). |
| `app/Enums/OcrJobStatus.php` | Updated — `PROCESSING` case added (runtime state) + `persistable()` helper. |
| `database/factories/OcrJobFactory.php` | Updated — samples `OcrJobStatus::persistable()` so fixtures never write the non-persistable PROCESSING value. |
| `tests/Feature/Phase5/OcrProcessingServiceTest.php` | New — 7 tests covering transition, failure paths, invalid-job rejection, persistence. |
| `docs/PHASE5.md` | Updated — this §5.2 section; Version 1.0.0 → 1.1.0. |
| `docs/CHANGELOG.md` | Updated — Phase 5.2 entry; Version 1.8.0 → 1.9.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-13 (OCR processing pipeline) added, status Implemented. |

### 5.2.5 Verification

```text
php artisan test        117 passed (535 assertions), 3 skipped
./vendor/bin/pint --test  PASS (132 files)
```

`npm run build` not applicable — no frontend asset changed (pure PHP + docs).

### 5.2.6 Commit

`feat(ocr): Phase 5.2 — processing pipeline`

---

## 5.3 OCR Image Preprocessing

### 5.3.1 Objective

Run image preprocessing before OCR extraction: validate the uploaded image
(decode + resolution bounds), correct orientation, convert to grayscale,
resize/normalize, and track the preprocessing result for the caller and the
logs. No OCR recognition, Tesseract, AI vision, or parsing yet — the stage
ends with a preprocessed image ready for the OCR engine sub-phase.

### 5.3.2 Deliverables

- **Preprocessing stage** (`app/Services/ImagePreprocessor.php`, new —
  GD-based; GD + exif are the only image-processing libraries available in
  this repository, so no new dependency was introduced):
  - `preprocess(string $bytes, string $sourcePath): PreprocessResult` —
    decodes the image, enforces the resolution gate, corrects EXIF
    orientation, converts to grayscale, downscales past the dimension cap,
    measures sampled mean brightness, stores a lossless PNG on the private
    `ocr_temp` disk, and logs a `pipeline_stage=preprocess` line
    (`.ai/ocr.md` §9).
  - **Image validation** — the resolution gate explicitly deferred from 5.1
    to preprocessing (`docs/PHASE5.md` §5.1.3; `.ai/ocr.md` §4.1):
    - Minimum 800×600: images below it are rejected (`OcrProcessingException`
      → job persisted `FAILED`), matching `.ai/ocr.md` §4.9 "Resolution too
      low → Reject".
    - Maximum 4000×4000: larger images are downscaled proportionally (bicubic
      `imagecopyresampled`) to control downstream processing time.
    - Undecodable content (valid image signature but corrupt body) is
      rejected at the same gate.
  - **Orientation correction** — EXIF orientation tags 2–8 applied with GD's
    native `imageflip` / `imagerotate`. This is the orientation correction
    form supported by the current libraries; automatic skew-angle detection
    is not (see §5.3.3).
  - **Grayscale conversion** — `IMG_FILTER_GRAYSCALE`, `.ai/ocr.md` §4.2
    step 1.
  - **Resize/normalization** — proportional downscale to the 4000×4000 cap
    (`.ai/ocr.md` §4.1); aspect ratio preserved.
  - **Preprocessing result tracking** (`app/Services/PreprocessResult.php`,
    new readonly DTO — in-memory only, never persisted, matching the
    stateless-pipeline design of `.ai/ocr.md` §8):
    - processed image path on `ocr_temp`, processed width/height,
    - sampled mean brightness (quality metric, `.ai/ocr.md` §4.10),
    - skew angle (`null` — auto-deskew not implemented yet),
    - ordered `appliedTransforms` list (`exif_orientation`, `grayscale`,
      `resize`) — the transform pipeline later stages slot into,
    - non-blocking quality `warnings` (brightness outside the acceptable
      100–200 band, `.ai/ocr.md` §4.10),
    - `durationMs` wall time.
- **Pipeline integration** (`app/Services/OcrProcessingService.php`):
  - `start()` now runs the preprocessing stage after loading the source
    image; preprocessing failures follow the same `FAILED` persistence path
    as load failures.
  - `preprocessResult(): ?PreprocessResult` — exposes the result of the last
    `start()` run to the caller (the future extraction sub-phase consumes
    the preprocessed path).
- **No schema changes** — preprocessing tracking is a DTO + log line; the
  `ocr_jobs` table is untouched (roadmap requires no preprocessing fields).

### 5.3.3 Not done (explicitly out of scope for 5.3)

- No OCR extraction, Tesseract invocation, AI recognition, or parsing.
- No denoise (bilateral filter), adaptive binarization, border removal, or
  automatic deskew (`.ai/ocr.md` §4.2 steps 2–5): GD has no bilateral filter,
  adaptive threshold, or projection-profiling primitives, and no image
  library providing them (intervention/image, OpenCV) is present in the
  repository. The `appliedTransforms` pipeline structure is ready for them;
  they land with the OCR engine phase, per the "implement the pipeline
  structure only and document what remains" instruction.
- No persisted preprocessing fields, no migrations, no schema changes —
  tracking stays in-memory + logs (`.ai/ocr.md` §8 stateless pipeline).
- No temp-file GC (24-hour cycle), no queue workers, no dashboard changes;
  Phase 4, 5.1, and 5.2 remain frozen except for the 5.2 test fixture
  adjustment below.
- No `SUCCESS` / `LOW_CONFIDENCE` transitions — still the extraction
  sub-phase's job. `PROCESSING` remains a runtime-only state.

### 5.3.4 Files changed (5.3 only)

| File | Change |
| --- | --- |
| `app/Services/ImagePreprocessor.php` | New — preprocessing stage (decode + resolution gate, EXIF orientation, grayscale, resize, brightness, `ocr_temp` output, logging). |
| `app/Services/PreprocessResult.php` | New — in-memory preprocessing result DTO (path, dimensions, brightness, skew, transforms, warnings, duration). |
| `app/Services/OcrProcessingService.php` | Updated — `start()` runs the preprocessing stage; `preprocessResult()` accessor added. |
| `tests/Feature/Phase5/ImagePreprocessorTest.php` | New — 7 tests: valid flow, output generated, corrupt image rejected, low resolution fails, oversized downscaled, EXIF orientation applied, brightness warning non-blocking. |
| `tests/Feature/Phase5/OcrProcessingServiceTest.php` | Updated — fixtures raised to 800×600 and `ocr_temp` faked, so the 5.2 transition tests keep exercising the pipeline under the new resolution gate. |
| `docs/PHASE5.md` | Updated — this §5.3 section; Version 1.1.0 → 1.2.0. |
| `docs/CHANGELOG.md` | Updated — Phase 5.3 entry; Version 1.9.0 → 1.10.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-14 (OCR image preprocessing) added, status Implemented. |

### 5.3.5 Verification

```text
php artisan test        124 passed (581 assertions), 3 skipped
./vendor/bin/pint --test  PASS (135 files)
```

`npm run build` not applicable — no frontend asset changed (pure PHP + docs).

### 5.3.6 Commit

`feat(ocr): Phase 5.3 — image preprocessing`

---

## 5.4 OCR Engine Integration

### 5.4.1 Objective

Integrate the OCR engine the roadmap specifies — Tesseract 5 (`.ai/ocr.md`
§4.3) — into the pipeline: run text extraction over the preprocessed image,
aggregate word-level confidence, handle engine failures and timeouts, update
the job status, and persist the raw extracted text on the existing `ocr_jobs`
schema. No parsing, no KartuKeluarga/Penduduk creation, no review UI.

### 5.4.2 Deliverables

- **Engine contract** (`app/Services/OcrEngine.php`, new — interface):
  `run(string $imagePath): OcrResult`; the abstraction through which the
  pipeline invokes OCR. Tests bind a fake (`.ai/ocr.md` §12) so the suite
  never depends on the real executable.
- **Tesseract engine** (`app/Services/TesseractOcrEngine.php`, new):
  invokes `tesseract <image> stdout -l ind --psm 6 tsv` through Laravel's
  Process facade (`.ai/ocr.md` §4.3: Indonesian language pack, single uniform
  text block, TSV for word confidence). Parses word-level TSV rows into raw
  text (words grouped back into lines in reading order) and a mean word
  confidence (0–100, 2 decimals). Non-zero exit → `OcrEngineException` with
  stderr; timeout (`config/ocr.php` `timeout_seconds`, 10 s per `.ai/ocr.md`
  §4.9) → `OcrEngineException`; empty/no-word output → empty `OcrResult`
  (`''`, confidence 0.0, 0 words) — the engine ran, it just saw nothing.
- **OCR result DTO** (`app/Services/OcrResult.php`, new — readonly):
  `rawText`, `confidence`, `wordCount`, `durationMs`. In-memory only; the
  extractable fields are persisted by the pipeline, never the DTO.
- **Engine exception** (`app/Exceptions/OcrEngineException.php`, new):
  engine failures and timeouts; the pipeline persists the job FAILED before
  rethrowing.
- **Pipeline stage** (`app/Services/OcrProcessingService.php`, updated):
  new `extract(OcrJob)` stage that follows `start()` — resolves the
  preprocessed image path on the `ocr_temp` disk, runs the engine, and
  persists the outcome on the existing columns (no migration):
  - mean confidence ≥ 70 (config `confidence_threshold`) → `SUCCESS`;
  - below the threshold, including empty results → `LOW_CONFIDENCE`;
  - in both cases `raw_text`, `confidence`, and `finished_at` are persisted;
  - engine failure/timeout → `FAILED` with `error_message` + `finished_at`
    (same mark-failed path as load/preprocess failures);
  - `ocrResult()` accessor exposes the last result (in-memory).
  `start()` is unchanged from 5.2/5.3 — the engine stage is a separate call,
  so the Phase 5.1–5.3 tests were not rewritten.
- **Configuration** (`config/ocr.php`, new — `.ai/ocr.md` §6):
  `tesseract_path` (env `TESSERACT_PATH`, default `tesseract` on PATH; the
  Windows desktop packaging sets the explicit path), `language` `ind`,
  `psm` `6`, `confidence_threshold` 70, `timeout_seconds` 10,
  `temp_retention_hours` 24. Resolution/size bounds stay owned by
  `ImagePreprocessor` / `KkDocumentUploadService` (not duplicated).
- **DI binding** (`app/Providers/AppServiceProvider.php`, updated):
  `OcrEngine` → `TesseractOcrEngine`.
- **Tests**:
  - `tests/Feature/Phase5/TesseractOcrEngineTest.php` (6 tests): successful
    run parses TSV into raw text + mean confidence; invocation shape
    (`-l ind`, `--psm 6`, `tsv`, `stdout`, image path) and timeout wiring
    asserted via `Process::assertRan`; empty output → empty result; non-zero
    exit with stderr → exception; non-zero exit without stderr → exit-code
    message; plus an env-gated real-binary test (`RUN_TESSERACT_TESTS=1`, the
    same gating as the Phase 3 real-MySQL test) that renders a NIK with
    GD + DejaVu and asserts the real tesseract 5.5 + `ind` extracts it.
  - `tests/Feature/Phase5/OcrEnginePipelineTest.php` (9 tests,
    `FakeOcrEngine` bound in the container): SUCCESS persisted with raw text
    and confidence; LOW_CONFIDENCE persisted; threshold boundary (70.0 →
    SUCCESS); empty result → LOW_CONFIDENCE with `''`/0.0; engine failure →
    FAILED with error_message; timeout → FAILED with "timed out"; DB status
    sequence (PENDING after `start()`, SUCCESS after `extract()`); extract
    without `start()` rejected; extract on a non-PROCESSING job rejected.
  - `tests/Support/FakeOcrEngine.php` (new): shared engine test double.

### 5.4.3 Not done (deferred)

- Parsing KK fields and creating KartuKeluarga / Penduduk rows — the next
  sub-phase; this phase persists only the raw extracted text.
- Confidence highlighting, review UI, dashboard changes — none in this phase.
- No new migrations: `raw_text`, `confidence`, `status`, `finished_at` all
  exist on `ocr_jobs` from Phase 2; `SUCCESS` / `LOW_CONFIDENCE` became
  persistable outcomes here (previously documented as "extraction
  sub-phase's job" in §5.2.3).
- Character whitelist (`.ai/ocr.md` §4.3: digits, uppercase, punctuation):
  deliberately **not** applied — a digits/uppercase whitelist would mangle
  lowercase name/address text before the parsing stage exists to handle
  casing. Revisit during the parsing sub-phase.
- Auto-deskew, queued processing, and temp-file GC remain out of scope (as
  in 5.1–5.3).

### 5.4.4 Files changed (5.4 only)

| File | Change |
| --- | --- |
| `config/ocr.php` | New — engine configuration per `.ai/ocr.md` §6. |
| `app/Services/OcrEngine.php` | New — engine contract (`.ai/ocr.md` §12). |
| `app/Services/TesseractOcrEngine.php` | New — Tesseract integration (Process facade, TSV parsing, timeout + failure mapping). |
| `app/Services/OcrResult.php` | New — in-memory OCR result DTO. |
| `app/Exceptions/OcrEngineException.php` | New — engine failure/timeout exception. |
| `app/Services/OcrProcessingService.php` | Updated — `extract()` stage + `ocrResult()` accessor; `start()` untouched. |
| `app/Providers/AppServiceProvider.php` | Updated — `OcrEngine` → `TesseractOcrEngine` binding. |
| `tests/Support/FakeOcrEngine.php` | New — shared engine test double. |
| `tests/Feature/Phase5/TesseractOcrEngineTest.php` | New — 6 engine tests (5 Process-faked + 1 env-gated real binary). |
| `tests/Feature/Phase5/OcrEnginePipelineTest.php` | New — 9 pipeline tests with the fake engine. |
| `docs/PHASE5.md` | Updated — this §5.4 section; Version 1.2.0 → 1.3.0. |
| `docs/CHANGELOG.md` | Updated — Phase 5.4 entry; Version 1.10.0 → 1.11.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-15 (OCR engine integration) added, status Implemented. |

### 5.4.5 Verification

```text
php artisan test        138 passed (628 assertions), 4 skipped (3 MySQL + 1 Tesseract, env-gated)
./vendor/bin/pint --test  PASS (143 files)
```

`npm run build` not applicable — no frontend asset changed (pure PHP + docs).

Real-binary smoke on this host: `RUN_TESSERACT_TESTS=1 php artisan test
--filter=real_tesseract` passes (tesseract 5.5.0, `ind` pack installed) —
the integration layer extracts a rendered NIK end to end.

### 5.4.6 Commit

`feat(ocr): Phase 5.4 — OCR engine integration`

---

## 5.5 OCR Parsing and Mapping

### 5.5.1 Objective

Convert the raw OCR text produced in 5.4 into a structured, in-memory result
object that maps onto the project's defined fields — without persisting
anything (ADR-009: OCR is an assistant). A single admin later reviews and
saves; this phase builds the mapping layer that pre-populates that review
form.

Only project-defined fields are extracted (FR-OCR-02, `.ai/ocr.md` §4.5) —
nothing is invented:

- **KK level**: nomor KK, alamat, RT, RW, lingkungan.
- **Member**: nama, NIK, jenis kelamin, tempat lahir, tanggal lahir, agama,
  pendidikan, pekerjaan, status perkawinan, status hubungan keluarga.

### 5.5.2 Deliverables

- **Parsing service** (`app/Services/OcrParsingService.php`, new — rule-based
  per ADR-017): `parse(string $rawText, float $confidence): ParsedOcrResult`,
  a pure function of the raw text with **no database access** (constructor-less,
  stateless, trivially testable).
  - **Header scan** — recognizes `NOMOR KARTU KELUARGA` / `NOMOR KK` / `NO KK`,
    `ALAMAT`, `RT/RW`, `RT`, `RW`, `LINGKUNGAN` labels with `:` or space
    separators (longest label first); the address may wrap to a following
    non-label line. Required labels resolved from the member table (nomor KK)
    and place-holder header fields (kelurahan/kecamatan/etc.) are intentionally
    left to the review/save sub-phases.
  - **Member-table scan** — finds the `NIK`/`NAMA` column header row, then
    reads rows that carry a valid 16-digit NIK (also recovered when Tesseract
    splits the number across spaced tokens). After-NIK tokens are attributed
    in column order (gender → place of birth → date of birth → religion →
    education → occupation → marital status → relation) via longest-match
    against the curated vocabularies (religions, educations, occupations,
    marital statuses, family relations).
  - **Confidence handling** — aggregate engine confidence (5.4) is carried onto
    every extracted member; `lowConfidence = confidence < ocr.confidence_threshold`;
    `< 30` adds an `Gambar tidak terbaca` warning (`.ai/ocr.md` §4.9).
  - **Required-field validation** (`.ai/ocr.md` §4.7) — nomor KK present and 16
    digits, at least one member NIK, sane birth dates (1900..today). Problems
    land in `validationErrors` on the result — never thrown exceptions.
  - **Graceful degradation** for every failure mode: missing values stay null;
    duplicated labels keep the first occurrence (conflicting duplicates warn);
    duplicate NIKs keep the first row; malformed rows are skipped with a
    warning; empty input yields an empty result with a clear warning.
  - **Stage logging** — a `pipeline_stage=parse` log line matching the
    preprocess convention (`.ai/ocr.md` §9).
- **Structured DTOs** (new, both `final readonly`, in-memory only):
  - `ParsedOcrResult` — confidence, lowConfidence, kkNumber, address, rt, rw,
    lingkungan, `ParsedResident[]` members, warnings[], validationErrors[],
    durationMs; `isEmpty()` and `memberCount()` helpers.
  - `ParsedResident` — nama, nik, gender, birthPlace, birthDate (normalized
    `Y-m-d`), religion, education, occupation, maritalStatus, familyRelation,
    confidence, lowConfidence.
- **Pipeline stage** (`app/Services/OcrProcessingService.php`, updated):
  `parse(OcrJob)` runs the parsing service over the in-memory `OcrResult`
  (extract must have run), publishes `parsedResult()`, and persists **nothing**
  — the `ocr_jobs` row keeps its extracted state untouched.
- **Tests**:
  - `tests/Feature/Phase5/OcrParsingServiceTest.php` (11 tests) — covers all six
    required scenarios plus robustness: valid full parse; missing optional
    fields stay null; missing required fields reported; malformed OCR (bad NIK,
    impossible date, junk lines); duplicate labels + duplicate NIK; low
    confidence (`< threshold` and `< 30` warning); threshold boundary (70.0);
    empty / whitespace input; RT/RW/lingkungan variants; wrapped KK number and
    spaced NIK recovery.
  - `tests/Feature/Phase5/OcrParsingPipelineTest.php` (6 tests,
    `FakeOcrEngine`) — parse is a pure in-memory stage: SUCCESS and
    LOW_CONFIDENCE both parse; `parse()` without extraction rejected;
    non-terminal (PENDING/FAILED) rejected; a SUCCESS job with no extraction on
    this instance rejected; parsing persists nothing (row unmutated, no
    `extracted_data`).

### 5.5.3 Not done (deferred)

- **No persistence** — no `KartuKeluarga`, `Penduduk`, or `KkAnggota` rows are
  created or updated, and nothing is written to the `ocr_jobs` row. This phase
  only maps.
- No review UI, no confidence highlighting, no duplicate-upload detection, no
  dashboard changes (as in 5.1–5.4).
- **Field-level confidence** (`.ai/ocr.md` §4.4 minimum word confidence per
  field): the engine exposes only an aggregated mean, so the aggregate is
  carried onto every member. Word-level attribution requires a per-token
  confidence stream from the engine and is deferred until confidence
  highlighting.
- **Enum/lookup resolution** — religion, education, occupation, marital
  status, and family relation are extracted into canonical labels (reliability per
  `.ai/ocr.md` §4.5) but their mapping to seed `religions` / `educations` /
  `occupations` rows and to `Gender`/`MaritalStatus`/`FamilyRelation` enum
  values is the save sub-phase's job. Religion, education, and occupation are
  lookup-table backed (not PHP enums, confirmed in §4.5-conformant audit).
- The character-whitelist note from 5.4.3 remains open; parsed text is kept
  verbatim (case preserved) for free-text fields.

### 5.5.4 Files changed (5.5 only)

| File | Change |
| --- | --- |
| `app/Services/OcrParsingService.php` | New — rule-based parsing service (vocabularies, header + member-table scan, confidence, validation). |
| `app/Services/ParsedOcrResult.php` | New — structured parse-result DTO (KK level + members + warnings + validation errors). |
| `app/Services/ParsedResident.php` | New — per-member DTO. |
| `app/Services/OcrProcessingService.php` | Updated — `parse()` stage + `parsedResult()` accessor + `assertParsable()`; `start()`/`extract()` untouched. |
| `tests/Feature/Phase5/OcrParsingServiceTest.php` | New — 11 service tests (six scenarios + variants). |
| `tests/Feature/Phase5/OcrParsingPipelineTest.php` | New — 6 pipeline tests (no-persistence, stage ordering, status guards). |
| `docs/PHASE5.md` | Updated — this §5.5 appendix; Version 1.3.0 → 1.4.0. |
| `docs/CHANGELOG.md` | Updated — Phase 5.5 entry; Version 1.11.0 → 1.12.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-16 (OCR parsing and mapping) added, status Implemented. |

### 5.5.5 Verification

```text
php artisan test        155 passed (753 assertions), 4 skipped (3 MySQL + 1 Tesseract, env-gated)
./vendor/bin/pint --test  PASS (148 files)
```

`npm run build` not applicable — no frontend asset changed (pure PHP + docs).

Phase 5.5 scope is a net additive change: the entire Phase 5.4 pipeline and
its tests are untouched (`start()`/`extract()` behavior, status transitions,
and gated real-binary smoke all unchanged).

### 5.5.6 Commit

`feat(ocr): Phase 5.5 — OCR parsing and mapping`

---

## 5.6 OCR Review and Validation

### 5.6.1 Objective

Expose the parsed OCR result (Phase 5.5) to the operator through a Filament
review page: display every parsed field for inspection, highlight missing
required fields and low-confidence values (`.ai/ocr.md` §5), let the operator
correct them, and run the pre-approval validation gate. Per ADR-009 the review
is an **assistant** — nothing is ever written to the database here; accepting
and importing the validated data is a later phase.

### 5.6.2 Deliverables

- **Review service** (`app/Services/OcrReviewService.php`, new — the operator
  validation layer over the Phase 5.5 `ParsedOcrResult`):
  - `validate(ParsedOcrResult $parsed, array $corrections = []): OcrReviewResult`
    — merges the parsed baseline with operator corrections into one effective
    dataset, validates it against the schema-grounded rule set, and returns an
    in-memory result. No database writes, no Kk/Penduduk creation, no job
    mutation.
  - `missingRequiredFields(array $data): array` — the labels of required
    fields still empty, used by the page's "Wajib diisi" highlight.
  - `isReviewable(OcrJob $job): bool` — gates review to terminal OCR states
    (`SUCCESS` / `LOW_CONFIDENCE`) that carry raw text to re-parse.
  - `confidenceBand(float $confidence): ?string` — `.ai/ocr.md` §5: `≥ 90`
    normal (null), `70–90` subtle yellow (`warning`), `< 70` red (`danger`,
    "Harap periksa").
  - `REQUIRED_FIELDS` constant — field path ⇒ required-field label, grounded
    in the actual schema (kk_number 16 digits, address, the NOT NULL penduduk
    columns) so a passing result is importable without surprises.
- **Review resource** (`app/Filament/Resources/OcrJobs/`, new) — the operator
  entry point:
  - `OcrJobResource` — index table (ID, status badge, confidence, started/finished),
    a `review` action linking to the Review page, and routes `index` (`/`) and
    `review` (`/{record}/review`). Navigation group `Kependudukan`, label
    "Review OCR".
  - `ListOcrJobs` — lists finished jobs for the operator to pick one.
  - `ReviewOcrJob` (page) — loads the job record via `InteractsWithRecord`,
    re-parses its raw text in-memory (`OcrParsingService`), and renders the
    review form. Implements the canonical Filament v4 form-state pattern
    (`public ?array $data = []` bound via `statePath('data')` in a
    `defaultForm()`), mapping parsed fields into `kk_number`, `address`, `rt`,
    `rw`, `lingkungan`, and a `members` Repeater (nama, nik, gender, place/date
    of birth, religion, education, occupation, marital status, family
    relation). A `validateReview()` action runs the pre-approval validation
    gate and reports the outcome as a notification ("Validasi berhasil" /
    "Validasi gagal" / "Belum dapat divalidasi") — it never imports.
- **Detail status sections** (`statusComponents()`): the page shows conditional
  Filament Sections for parse problems, missing required fields, and
  low-confidence members — the `.ai/ocr.md` §5 highlighting requirement, driven
  by `currentData()` (which falls back to the parsed baseline while the schema
  is being built and normalizes Repeater UUID keys back to a numeric list for
  the service).
- **Blade view**
  (`resources/views/filament/resources/ocr-jobs/review-ocr-job.blade.php`):
  renders the form, the "Validasi Data" button, and the "belum dapat direview"
  rejection panel for non-reviewable jobs.
- **Tests**:
  - `tests/Feature/Phase5/OcrReviewServiceTest.php` (11 tests) — complete parse
    validates; missing kk_number rejected; more than one member required;
    operator malformed NIK correction breaks validation; invalid gender /
    marital-status corrections rejected; corrections fix a parse problem;
    corrections override parsed values in the effective data; missing-required
    returned as labels; complete result has none; confidence band matches
    `.ai/ocr.md` §5 boundaries (90, 70); `isReviewable` gates terminal states
    with raw text.
  - `tests/Feature/Phase5/OcrReviewPageTest.php` (9 tests) — review page
    loads; parsed fields are displayed (asserted against the Livewire
    component state via canonical Filament/Livewire testing practice, since
    Filament renders deferred form values in partials rather than the initial
    HTTP shell); missing-required fields highlighted; low-confidence values
    highlighted; high confidence not flagged; validation succeeds + reports
    ready-to-import; validation fails on a malformed operator correction
    (surfacing a field error); non-reviewable job rejected without the form;
    review never writes to the database (asserts KK and Penduduk tables remain
    unchanged).

### 5.6.3 Not done (deferred)

- **No persistence / import** — no KartuKeluarga, Penduduk, or KkAnggota rows
  are created or updated, and the `ocr_jobs` row is never mutated. The review
  validation is purely in-memory (ADR-009). Accepting and importing the
  validated data is the Save / import sub-phase.
- **Duplicate-upload detection** (FR-OCR-05, image hash + KK number) — deferred
  to a later sub-phase; the `source_image_hash` seed is already persisted at
  upload (Phase 5.1).
- **Field-level confidence** per `.ai/ocr.md` §4.4 (minimum word confidence per
  field) — still approximated by carrying the engine's aggregate onto each
  member. Per-field word confidence needs a per-token stream from the engine
  and remains deferred.
- No dashboard changes, no export, no analytics, no schema changes or
  migrations — the review uses the existing `ocr_jobs` schema.

### 5.6.4 Files changed (5.6 only)

| File | Change |
| --- | --- |
| `app/Services/OcrReviewService.php` | New — operator validation layer (`validate()`, `missingRequiredFields()`, `isReviewable()`, `confidenceBand()`). |
| `app/Services/OcrReviewResult.php` | New — in-memory validation result DTO (isValid, errors, correctedData, duration). |
| `app/Filament/Resources/OcrJobs/OcrJobResource.php` | New — OCR review resource (nav, index table, Review action, routes). |
| `app/Filament/Resources/OcrJobs/Pages/ListOcrJobs.php` | New — index page listing candidate jobs. |
| `app/Filament/Resources/OcrJobs/Pages/ReviewOcrJob.php` | New — review page (form, status highlights, validate gate). |
| `resources/views/filament/resources/ocr-jobs/review-ocr-job.blade.php` | New — review page blade. |
| `tests/Feature/Phase5/OcrReviewServiceTest.php` | New — 11 review-service tests. |
| `tests/Feature/Phase5/OcrReviewPageTest.php` | New — 9 page tests (Livewire-aware assertions, no-persistence guard). |
| `docs/PHASE5.md` | Updated — this §5.6 section; Version 1.4.0 → 1.5.0. |
| `docs/CHANGELOG.md` | Updated — Phase 5.6 entry; Version 1.12.0 → 1.13.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-17 (OCR review and validation) added, status Implemented. |

### 5.6.5 Verification

```text
php artisan test        175 passed (818 assertions), 4 skipped (3 MySQL + 1 Tesseract, env-gated)
./vendor/bin/pint --test  PASS (155 files)
```

`npm run build` not applicable — no compiled frontend asset changed (a Blade
view + Filament resource; the panel has no custom Vite theme).

---

## 5.7 Import Kartu Keluarga

### 5.7.1 Objective

Persist a validated OCR review result into the Kartu Keluarga domain — the
operator-triggered "SIMPAN" write (ADR-009: OCR is an assistant; the Service
layer writes only after the operator explicitly approves). This phase
creates **only** the `KartuKeluarga` record. Penduduk membership (the
review `members` rows) is **not** created here — that is a later sub-phase.

### 5.7.2 Deliverables

- **Import service** (`app/Services/OcrImportService.php`, new — `App\Services\*`
  per ADR-016):
  - `import(OcrJob $job, array $correctedData, ?User $operator = null): OcrImportResult`
    — consumes the Phase 5.6 approved review data and persists a
    `KartuKeluarga` record (`kk_number` + `address`, the only importable
    fields on the existing `kartu_keluarga` schema).
  - **Existing validation** — the supplied corrections are re-run through
    `OcrReviewService::validate()` (the same schema-grounded gate the review
    page uses) before anything is written, so an un-validated or tampered
    payload is rejected up front (returns an `invalid` result, zero writes).
  - **Duplicate KK detection** (FR-OCR-05, KK-number rule) — `kk_number` is a
    unique column; existence is pre-checked and the insert is wrapped in
    `DB::transaction`, so a concurrent insert that wins the race also resolves
    to a `duplicate` result rather than a partial write.
  - **Transactional write** — the KK insert and the OCR-job update happen in
    one transaction; a failed job update rolls the KK creation back (no orphan
    KK row).
  - **OCR job updated on success** — the job is marked saved: `outcome` =
    SAVED, `kk_id` linked, `reviewed_at`, `operator_id`, and the approved data
    snapshot persisted to `extracted_data` for audit. The `status` column is
    left untouched (it records the OCR extraction outcome, not the save action).
  - **Mutation guards** — non-reviewable jobs (not SUCCESS/LOW_CONFIDENCE with
    raw text) throw `InvalidArgumentException` (programmer error, matching the
    pipeline conventions); an already-saved job (`kk_id` set or `outcome`
    SAVED) returns an `already_saved` result and writes nothing.
- **Import result DTO** (`app/Services/OcrImportResult.php`, new — `final
  readonly`, in-memory only): status `saved` / `duplicate` / `invalid` /
  `already_saved` with `kartuKeluargaId`, `kkNumber` and (for invalid) the
  validation `errors`; convenience `isSaved()` / `isDuplicate()` /
  `isInvalid()` / `isAlreadySaved()`.

### 5.7.3 Not done (explicitly out of scope for 5.7)

- **No Penduduk / KkAnggota creation** — this phase creates only the
  `KartuKeluarga` record. Importing the approved `members` rows into
  `Penduduk` (+ `kk_anggota` membership) is a later sub-phase.
- **No review-page UI wiring** — no SIMPAN action is added to the Phase 5.6
  `ReviewOcrJob` page; this phase delivers the service-layer contract the UI
  will call.
- **No migrations / schema changes** — the existing `kartu_keluarga` and
  `ocr_jobs` tables fully cover the import. The reviewed `rt` / `rw` /
  `lingkungan` fields still have no persistable columns (unchanged).
- **No image-hash duplicate-upload detection beyond the KK-number rule** —
  FR-OCR-05 image-hash matching remains deferred.
- No changes to OCR parsing, the OCR engine, preprocessing, the dashboard,
  or the Phase 5.1–5.6 code (the `OcrJob` model's missing `outcome` cast and
  the factory's eager-KK default are pre-existing and left untouched).

### 5.7.4 Files changed (5.7 only)

| File | Change |
| --- | --- |
| `app/Services/OcrImportService.php` | New — import service (validation, duplicate KK detection, transactional write, job marked saved). |
| `app/Services/OcrImportResult.php` | New — import result DTO (saved / duplicate / invalid / already_saved). |
| `tests/Feature/Phase5/OcrImportServiceTest.php` | New — 8 tests covering the required scenarios. |
| `docs/PHASE5.md` | Updated — this §5.7 section; Version 1.5.0 → 1.6.0. |
| `docs/CHANGELOG.md` | Updated — Phase 5.7 entry; Version 1.13.0 → 1.14.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-18 (OCR import of Kartu Keluarga) added, status Implemented. |

### 5.7.5 Verification

```text
php artisan test        183 passed (853 assertions), 4 skipped (3 MySQL + 1 Tesseract, env-gated)
./vendor/bin/pint --test  PASS (158 files)
```

`npm run build` not applicable — no compiled frontend asset changed (pure PHP
service + tests + docs; the panel has no custom Vite theme).

---

## 5.8 Import Penduduk

### 5.8.1 Objective

Persist the approved OCR review members as `Penduduk` records under the
`KartuKeluarga` that Phase 5.7 already created — the second half of the
operator-triggered "SIMPAN" write (ADR-009: OCR is an assistant; nothing is
persisted until the operator has reviewed and approved). The approved
dataset is Phase 5.7's `extracted_data` snapshot (the Phase 5.6 corrected
review data), so this sub-phase needs no new UI: it completes the family
import at the service layer.

### 5.8.2 Deliverables

- **Penduduk import service** (`app/Services/PendudukImportService.php`, new
  — `App\Services\*` per ADR-016):
  - `import(OcrJob $job, ?User $operator = null): PendudukImportResult` —
    consumes the job's Phase 5.7 SAVED state (`kk_id`, `outcome` SAVED,
    `raw_text`, `extracted_data`) and persists every approved member as a
    `Penduduk` row linked to the KK, plus one ACTIVE `KkAnggota` membership
    row per member (the membership-history baseline, ADR-008).
  - **Existing validation** — the approved snapshot is re-run through
    `OcrReviewService::validate()` (re-parsing `raw_text` and re-applying
    the snapshot as corrections — the same schema-grounded gate the review
    page uses), so a tampered or incomplete dataset returns an `invalid`
    result with zero writes.
  - **Domain mapping** — enumerated fields map onto the existing domain:
    `gender` / `marital_status` / `family_relation` from their enums;
    `blood_type` defaults to `TIDAK_DIKETAHUI` and `resident_status` to
    `ACTIVE` (the OCR review never captures them); `religion` / `education`
    / `occupation` resolve case-insensitively to the evolving lookup
    masters (`religions` / `educations` / `occupations`), creating a
    title-cased master row when an approved label is absent; `birth_date`
    is normalized to `Y-m-d` (the parser emits `Y-m-d`, operator
    corrections may use `d/m/Y` / `d-m-Y`); the reviewed `rt` resolves to
    an existing `Rt` by number (`"001"` → `"01"`, matching the seeded
    `rts.number`), else the import fails `invalid` with a clear message —
    nothing is fabricated.
  - **Duplicate NIK detection** (FR-OCR-05 / `penduduk.nik` unique) — the
    approved NIK set is checked for repeats inside the list and against
    existing `penduduk` rows; a collision returns a `duplicate` result
    (with the offending NIK) and nothing is written. The insert is wrapped
    in `DB::transaction` so a concurrent insert that wins the NIK race also
    resolves to `duplicate`, never a partial family.
  - **Transactional write** — every Penduduk insert, KkAnggota insert and
    the OCR-job update happen in one transaction; a failed job update rolls
    the whole family back (no orphan residents).
  - **OCR job updated on success** — the approved `extracted_data` snapshot
    is augmented with `penduduk_imported_at`, the created `penduduk_ids`
    and `penduduk_operator_id`, recording the completed family import for
    audit. The `status` / `outcome` / `kk_id` columns are left untouched
    (they already reflect the Phase 5.7 KK save).
  - **Mutation guards** — jobs without a Phase 5.7-imported KK (no `kk_id`
    or `outcome` ≠ SAVED) throw `InvalidArgumentException` (programmer
    error, matching the pipeline conventions); a job whose snapshot already
    carries the `penduduk_imported_at` marker returns `already_imported`
    and writes nothing (idempotence).
- **Import result DTO** (`app/Services/PendudukImportResult.php`, new —
  `final readonly`, in-memory only): status `saved` / `duplicate` /
  `invalid` / `already_imported` with `kartuKeluargaId`, `kkNumber`,
  `importedCount`, `duplicateNik` and (for invalid) the validation `errors`;
  convenience `isSaved()` / `isDuplicate()` / `isInvalid()` /
  `isAlreadyImported()`.

### 5.8.3 Not done (explicitly out of scope for 5.8)

- **No review-page UI wiring** — no SIMPAN action is added to the Phase 5.6
  `ReviewOcrJob` page; this sub-phase delivers the service-layer contract
  the UI will call (same as 5.7).
- **No migrations / schema changes** — the existing `penduduk`,
  `kk_anggota` and `ocr_jobs` tables fully cover the import. The reviewed
  `rt` / `rw` / `lingkungan` fields still have no persistable columns; `rt`
  is resolved to an existing `rts` row by number, and RT-to-area-unit
  disambiguation (a KK card carries no area unit) is deliberately out of
  scope.
- **No changes to OCR parsing, the OCR engine, preprocessing, the review
  page, the dashboard, or Phase 5.1–5.7 code** — the `OcrJob` model's
  missing `outcome` cast and the factory's eager-KK default remain
  pre-existing and untouched.

### 5.8.4 Files changed (5.8 only)

| File | Change |
| --- | --- |
| `app/Services/PendudukImportService.php` | New — Penduduk import service (validation, duplicate NIK detection, domain mapping, transactional write, job marked penduduk-imported). |
| `app/Services/PendudukImportResult.php` | New — import result DTO (saved / duplicate / invalid / already_imported). |
| `tests/Feature/Phase5/PendudukImportServiceTest.php` | New — 12 tests covering the required scenarios. |
| `docs/PHASE5.md` | Updated — this §5.8 section; Version 1.6.0 → 1.7.0. |
| `docs/CHANGELOG.md` | Updated — Phase 5.8 entry; Version 1.14.0 → 1.15.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-19 (OCR import of Penduduk) added, status Implemented. |

### 5.8.5 Verification

```text
php artisan test        195 passed (924 assertions), 4 skipped (3 MySQL + 1 Tesseract, env-gated)
./vendor/bin/pint --test  PASS (161 files)
```

`npm run build` not applicable — no compiled frontend asset changed (pure PHP
service + tests + docs; the panel has no custom Vite theme).

---

## 5.9 OCR Finalization

### 5.9.1 Objective

Close the OCR lifecycle cleanly after a successful import, without touching
the import logic built in 5.7 / 5.8. Once the operator has approved the review
(5.6), Phase 5.7 created the KartuKeluarga and Phase 5.8 imported the family
members, the finalization step marks the job **COMPLETED**, records the
completion, and tidies up the pipeline's transient artifacts. No parsing,
review, engine, KK-import, Penduduk-import, dashboard, or resource logic is
changed.

### 5.9.2 Deliverables

- **Final status transition to COMPLETED** — the job status column gains the
  terminal `COMPLETED` value:
  - `app/Enums/OcrJobStatus.php` — `COMPLETED` case added and included in
    `persistable()`. This is the *persisted* terminal state (the widened
    column constraint accepts it — see the migration below).
  - `database/migrations/2026_08_07_101500_add_completed_status_to_ocr_jobs_table.php`
    — widens the `ocr_jobs.status` constraint (SQLite CHECK / MySQL ENUM) from
    `PENDING, SUCCESS, LOW_CONFIDENCE, FAILED, CANCELLED` to include
    `COMPLETED`. This is the exact "deliberate future schema change" Phase 5.2
    documented; it is purely additive (existing values/rows/NOT-NULL untouched).
    On SQLite the grammar handles `change()` by rebuilding the table
    (preserving FKs + indexes + the widened CHECK); on MySQL it is a native
    `ALTER TABLE ... MODIFY ENUM(...)`.
- **Completion service** (`app/Services/OcrCompletionService.php`, new — the
  centralized success/failure completion handler):
  - `finalize(OcrJob $job, ?User $operator = null): OcrCompletionResult` —
    transitions a fully imported job (outcome SAVED + kk_id + the Phase 5.8
    `penduduk_imported_at` marker) to `COMPLETED`.
  - **Completion timestamp** — recorded on the audit snapshot
    (`extracted_data.ocr_completed_at`); the existing `finished_at` (set at
    extraction) is never overwritten.
  - **Import summary generation** — stored on the snapshot as
    `completion_summary` (imported flag, kk_number, kartu_keluarga_id,
    member_count, penduduk_count, completed_at).
  - **Final processing metrics** — stored as `processing_metrics` (ocr_status,
    confidence, duration_ms from started→finished, word_count, member_count,
    imported_penduduk_count).
  - **Cleanup of temporary processing artifacts** — after the completion is
    persisted, best-effort removal of the OCR pipeline's transient files on the
    private `ocr_temp` disk (`ImagePreprocessor::DISK`) — the only artifacts the
    pipeline manages; the uploaded source document on `kk_uploads` (the
    persistent archive) is never touched. A cleanup failure is logged as a
    warning and never rolls back (or breaks) the completion.
  - **Audit/event logging** using the project's existing approach — an
    `AuditLog` row (`event` `ocr.completed`, actor + summary values) in the same
    DB transaction, plus a `Log::info('OCR finalize …',
    pipeline_stage=finalize)` line matching `.ai/ocr.md §9`.
  - **Idempotence** — a job already in `COMPLETED` returns `already_completed`
    and writes nothing (no duplicate completion).
  - **Fault handling** — a failing job-save step rolls the whole finalization
    back (no COMPLETED state, no summary, no audit entry); a not-fully-imported
    / failed job is rejected by the guard with `InvalidArgumentException`.
    `markJobCompleted()` is `protected` so rollback behaviour is verifiable.
- **Completion result DTO** (`app/Services/OcrCompletionResult.php`, new —
  `final readonly`, in-memory only): status `completed` / `already_completed`
  with `jobId`, `kartuKeluargaId`, `kkNumber`, `importedPendudukCount` and the
  `summary` / `metrics` arrays; `isCompleted()` / `isAlreadyCompleted()`.
- No changes to the OCR engine, parsing, review workflow, KK import, Penduduk
  import, dashboard, or Filament resources.

### 5.9.3 Not done (explicitly out of scope for 5.9)

- **No logic change to Phase 5.1–5.8** — the upload, pipeline, preprocessing,
  engine, parsing, review, KK-import and Penduduk-import services are
  unmodified; only the `status` column constraint is widened (the documented
  Phase 5.2 schema change) and the `OcrJobStatus` enum gains the new value.
- **No UI wiring** — finalization is a service-layer contract; no Filament
  action is added.
- No new columns / tables — the completion timestamp, summary and metrics live
  in the existing `extracted_data` JSON; the audit entry on the existing
  `audit_logs` table; cleanup touches only the `ocr_temp` disk.
- The uploaded source document is **not** deleted (it is the persistent KK
  archive, not a pipeline temp).

### 5.9.4 Files changed (5.9 only)

| File | Change |
| --- | --- |
| `app/Enums/OcrJobStatus.php` | Updated — `COMPLETED` case added + included in `persistable()`. |
| `database/migrations/2026_08_07_101500_add_completed_status_to_ocr_jobs_table.php` | New — widen `ocr_jobs.status` (SQLite CHECK / MySQL ENUM) to include `COMPLETED`. |
| `app/Services/OcrCompletionService.php` | New — completion service (guard + idempotence, COMPLETED transition, timestamp, import summary, metrics, cleanup, audit log, success/failure logging). |
| `app/Services/OcrCompletionResult.php` | New — completion result DTO (completed / already_completed). |
| `tests/Feature/Phase5/OcrCompletionServiceTest.php` | New — 11 tests covering the required scenarios (success, summary + metrics, timestamp, audit, result DTO, no duplicate completion, rollback on failed save, guards, cleanup). |
| `docs/PHASE5.md` | Updated — this §5.9 section; Version 1.7.0 → 1.8.0. |
| `docs/CHANGELOG.md` | Updated — Phase 5.9 entry; Version 1.15.0 → 1.16.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-20 (OCR workflow finalization) added, status Implemented. |

### 5.9.5 Verification

```text
php artisan test        206 passed (983 assertions), 4 skipped (3 MySQL + 1 Tesseract, env-gated)
./vendor/bin/pint --test  PASS (165 files)
```

`npm run build` not applicable — no compiled frontend asset changed (pure PHP
service + enum + migration + tests + docs; the panel has no custom Vite theme).

### 5.9.6 Commit

`feat(ocr): Phase 5.9 — finalize OCR workflow`
