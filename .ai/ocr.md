| Field | Value |
|---|---|
| **Title** | SIPETA OCR Pipeline Specification |
| **Purpose** | Define the complete OCR pipeline from image upload to operator-confirmed data save. Authoritative reference for Hermes and OpenCode. |
| **Scope** | Preprocessing, extraction, confidence scoring, regex parsing, AI parsing, validation, duplicate detection, failure handling, image quality, performance. |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/architecture.md`, `.ai/database.md`, `.ai/workflow.md`, `.ai/ui-ux.md`, `.ai/coding.md`, `.ai/testing.md`, `.ai/project-rules.md`, `docs/REQUIREMENTS.md`, `docs/USER_GUIDE.md` |

---

# SIPETA OCR Pipeline Specification

OCR is an **assistant**, not a writer. The operator must review every extracted field before anything is persisted. This document is the authoritative reference for how OCR works in SIPETA.

## 1. Goals

1. Reduce manual typing for KK data capture.
2. Never silently corrupt data.
3. Provide clear feedback when OCR is uncertain.
4. Support graceful fallback to manual input.

## 2. Non-Goals

- Auto-save OCR output to the database.
- Replace the Dukcapil/sistem kependudukan nasional.
- Process documents other than KK.
- Face recognition, signature verification, or other biometric features.

## 3. High-Level Flow

```
Upload KK foto
    ↓
Validate image (size, format, resolution)
    ↓
Preprocess (grayscale, denoise, deskew, binarize)
    ↓
Tesseract OCR (Indonesian language pack)
    ↓
Raw text + word-level confidence
    ↓
Regex extraction (NIK, KK number, dates, gender)
    ↓
Rule-based parsing (line clustering, header detection)
    ↓
Field-level confidence aggregation
    ↓
Populate form (operator review UI)
    ↓
Highlight fields with confidence < 70%
    ↓
Duplicate detection (image hash + KK number)
    ↓
Operator edits / confirms
    ↓
Operator clicks SAVE → Service layer writes
```

The OCR pipeline **never** writes to the database. The Service layer writes only after the operator explicitly saves.

## 4. Stages

### 4.1 Image Upload

- Accepted formats: JPG, JPEG, PNG.
- Maximum size: 5 MB.
- Minimum resolution: 800×600.
- Maximum resolution: 4000×4000 (downscaled if larger to control processing time).

File is stored temporarily in `storage/ocr/tmp/` until the operator saves or discards. Temporary files are deleted on a 24-hour GC cycle.

### 4.2 Preprocessing

Performed before Tesseract is invoked:

1. **Grayscale conversion** — strip color to reduce noise.
2. **Denoise** — bilateral filter to remove speckles while preserving edges.
3. **Deskew** — rotate the image to align text horizontally (max ±15°).
4. **Binarize** — adaptive threshold to make text black-on-white.
5. **Border removal** — crop shadows that may be misread as text.

The preprocessor receives a `PreprocessResult` containing:
- the processed image path,
- detected skew angle,
- mean brightness (for quality reporting).

If preprocessing fails (e.g., open image source), the raw image is used and a warning is logged.

### 4.3 Tesseract Invocation

- **Engine**: Tesseract 5.x.
- **Language pack**: `ind` (Indonesian). English fallback `eng` only if Indonesian fails.
- **Page segmentation mode**: `--psm 6` (single uniform block of text). KK layout is regular text in rows.
- **Output mode**: `tsv` to capture word-level confidence (`text`, `conf`, `left`, `top`, `width`, `height`).
- **Whitelist**: digits, uppercase letters, common punctuation.

Tesseract runs inside the Laravel backend via a `Process` facade call. The binary path is configured at install time.

### 4.4 Confidence Scoring

- **Tesseract word confidence** is on a 0–100 scale.
- **Field-level confidence** is the minimum word confidence across all words assigned to that field.
- **Aggregate confidence** is the average field confidence, used to display an overall "OCR quality" indicator.

### 4.5 Regex Extraction

Patterns (case-insensitive, anchored where possible):

| Field | Regex / Strategy |
|-------|------------------|
| NIK | `\b\d{16}\b` |
| KK Number | `\b\d{16}\b` (validated against `kartu_keluarga` table context) |
| Birth date | `\b\d{2}[-/]\d{2}[-/]\d{4}\b` and a context check against Indonesian date ranges |
| Gender | `LAKI-LAKI\|PEREMPUAN` |
| Religion | `ISLAM\|KRISTEN\|KATOLIK\|HINDU\|BUDDHA\|KONGHUCU` |
| Marital status | `BELUM KAWIN\|KAWIN\|CERAI HIDUP\|CERAI MATI` |
| Family relation | `KEPALA KELUARGA\|ISTRI\|ANAK\|...` (curated list) |
| RT / RW | `\b\d{1,3}\b` near an `RT`/`RW` label token |
| Name | Lines not matching any other pattern, used in order |

Regex extraction is **first pass**. If it fails, the AI parser (see 4.6) is invoked.

### 4.6 AI Parsing (Rule-Based)

SIPETA uses **rule-based parsing** as the primary AI strategy. Reasons:

- Deterministic and auditable.
- No external LLM dependency at runtime (offline-capable).
- Predictable performance.

The rule-based parser:

1. Clusters Tesseract words into lines by Y-coordinate.
2. Detects header rows (e.g., the row with `NO` `NIK` `NAMA`... is the table header).
3. Groups lines under each NIK (each row of KK is a resident).
4. Maps column positions to fields using the relative X-coordinate of each word.

If parsing yields fewer than 1 resident, the OCR pipeline returns a `LowYieldResult` and prompts the operator to use manual input.

**Future option** (not in KKN scope): an LLM-based fallback could be used for low-confidence cases. This is documented in `docs/BACKLOG.md` as B-DATA-02.

### 4.7 Validation

Each field, after extraction, is validated against `form_requests` rules:

- NIK: 16 digits, unique.
- KK Number: 16 digits, unique.
- Birth date: parseable, year between 1900 and current year.
- Gender: in enum.
- Religion: in enum.
- Marital status: in enum.
- Family relation: in enum.
- RT / RW: positive integer.

Validation errors are surfaced in the form, not silenced.

### 4.8 Duplicate Detection

Two strategies, combined:

1. **Image hash** (perceptual hash, e.g., dHash 64-bit). If a hash matches an existing KK photo's hash (or a hash stored in `ocr_temp_hashes`), warn the operator.
2. **KK number match**. If the extracted KK number matches an existing `kartu_keluarga.kk_number`, warn the operator.

Warnings are **soft**, not blocking. The operator decides to proceed or cancel.

### 4.9 Failure Handling

| Failure | Handling |
|---------|----------|
| Unsupported file format | Reject upload with clear message |
| Resolution too low | Reject, show min-res tip |
| Tesseract binary missing | Show fatal error, fall back to manual form |
| Preprocess error | Use raw image, log warning |
| Number extraction yields zero NIK | Show "OCR tidak menemukan NIK", prompt manual review |
| Confidence < 30% | Show "Gambar tidak terbaca", suggest retake |
| Tesseract timeout (>10s) | Cancel, show timeout, suggest smaller image |

Every failure is logged with sufficient context for debugging.

### 4.10 Image Quality Rules

The OCR pipeline computes a quality score at the preprocessing stage:

- **Brightness**: 100–200 (out of 255) is acceptable.
- **Sharpness**: Laplacian variance ≥ 100 is acceptable.
- **Skew**: ≤ 5° is acceptable without manual correction.

If quality is below threshold, the operator is advised to retake the photo. The pipeline still attempts OCR but flags the result.

## 5. Operator Review UI

The form is pre-populated with extracted values. For each field:

- If confidence ≥ 90%: field shown normally.
- If 70% ≤ confidence < 90%: field shown with a subtle yellow indicator.
- If confidence < 70%: field shown with a red indicator and a tooltip "Harap periksa".

Buttons:

- **SIMPAN** — saves to DB.
- **ULANGI OCR** — re-runs OCR (e.g., if operator uploaded a clearer photo).
- **INPUT MANUAL** — clears extracted values, opens empty form.

OCR never saves automatically.

## 6. Configuration

OCR settings live in `config/ocr.php`:

```php
return [
    'tesseract_path' => env('TESSERACT_PATH', 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'),
    'language' => 'ind',
    'min_resolution' => 800_600, // width × height
    'max_file_size' => 5_242_880, // 5 MB in bytes
    'confidence_threshold' => 70, // %
    'timeout_seconds' => 10,
    'temp_retention_hours' => 24,
];
```

## 7. Performance

- **OCR target**: ≤ 10 seconds per KK photo on i5 / 8 GB RAM.
- **Tesseract invocation**: synchronous. OCR is not queued in KKN scope; the operator waits.
- **Caching**: extracted text from the same image hash is cached for 1 hour to allow fast re-review.
- **Memory**: Tesseract is told to use ≤ 1 GB.
- **GC**: temp files > 24 hours old are deleted on application launch.

## 8. Storage of OCR Intermediates

- **Preprocessed image**: stored transiently in `storage/ocr/tmp/`.
- **Raw Tesseract output (TSV)**: stored in `storage/ocr/cache/{hash}.tsv` for 1 hour.
- **Final extracted data**: never stored until operator saves via the Service layer.

The database does not have an `ocr_results` table during KKN. The OCR pipeline is stateless across requests.

## 9. Logging

OCR logs go to Laravel's default log channel but with a dedicated context:

- `pipeline_stage`: upload, preprocess, tesseract, regex, parse, validate
- `image_hash`: short hash for correlation
- `duration_ms`: stage timing
- `confidence_summary`: per-field mean
- `outcome`: success, low_confidence, validation_error, failure

Logs are never shown to the operator. They are for developer diagnostics.

## 10. Security

- Uploaded files are validated by MIME type and extension.
- Files are stored outside the public webroot.
- Filenames are randomized (UUID).
- Files are deleted after the operator saves or discards.
- OCR never executes arbitrary code from the image.

## 11. Testing Strategy

Test categories:

1. **Golden images** — a curated set of KK photos with known-correct field values.
2. **Adversarial images** — rotated, dark, low-res, blurry.
3. **Edge cases** — handwritten annotations, watermarks, fold lines.
4. **Failure cases** — unsupported formats, missing tesseract binary, corrupted files.

Each test asserts:
- Extracted fields match expected (within tolerance).
- Confidence is reported.
- Database remains empty until a save is explicitly triggered.

See `.ai/testing.md` for the full test plan.

## 12. Implementation Notes

- The OCR pipeline is implemented as a Service: `App\Services\OCRService`.
- Stages are individual classes: `ImagePreprocessor`, `TesseractRunner`, `RegexExtractor`, `RuleParser`, `FieldValidator`, `DuplicateDetector`.
- The Service is called from a Filament Action, not from a Controller.
- The pipeline returns a `OCRResult` DTO containing extracted fields, confidence, and warnings.

## 13. Future Improvements

Captured in `docs/BACKLOG.md`:

- LLM-based fallback for low-confidence cases.
- OCR for other documents (KTP, Akta Nikah).
- Multi-page KK support.
- Persistent OCR review history (separate table).
- Operator override of confidence threshold per session.
