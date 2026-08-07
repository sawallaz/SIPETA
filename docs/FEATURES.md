| Field | Value |
|---|---|
| **Title** | SIPETA Features Catalog |
| **Purpose** | Categorize and track every feature by priority and implementation status. |
| **Scope** | KKN-deliverable features plus future backlog references. |
| **Version** | 1.7.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-07 |
| **Related Documents** | `docs/REQUIREMENTS.md`, `docs/BACKLOG.md`, `.ai/roadmap.md`, `.ai/architecture.md`, `.ai/ocr.md` |

---

# SIPETA Features Catalog

Feature status legend:
- **Implemented** — code merged and verified
- **Planned** — scoped and scheduled in `roadmap.md`
- **Backlog** — captured but not in KKN scope (see `docs/BACKLOG.md`)
- **Dropped** — explicit decision not to build

## 1. Core Features (Must ship)

| ID | Feature | Status | Phase | Owner Doc |
|----|---------|--------|-------|-----------|
| F-CORE-01 | Single admin login | Implemented | Phase 1 | `.ai/hermes.md` §8 |
| F-CORE-02 | Dashboard statistics | Planned | Phase 3 | `.ai/architecture.md` §4 |
| F-CORE-03 | KK CRUD | Planned | Phase 3 | `.ai/database.md` |
| F-CORE-04 | Penduduk CRUD | Planned | Phase 3 | `.ai/database.md` |
| F-CORE-05 | Search (Nama, NIK, KK Number) | Planned | Phase 3 | `.ai/workflow.md` |
| F-CORE-06 | Filter (RT, RW, Lingkungan, Gender, Religion, Education, Occupation, Status, Exact Age, Age Range) | Planned | Phase 3 | `.ai/workflow.md` |
| F-CORE-07 | Resident status workflow (ACTIVE / PINDAH / MENINGGAL) | Planned | Phase 3 | `.ai/project-rules.md` |
| F-CORE-08 | KK photo upload and display | Planned | Phase 3 | `.ai/database.md` |
| F-CORE-09 | OCR from KK photo | Planned | Phase 4 | `.ai/ocr.md` |
| F-CORE-10 | OCR review and manual correction | Planned | Phase 4 | `.ai/ocr.md` |
| F-CORE-11 | Export PDF | Implemented | Phase 6 | `.ai/architecture.md` |
| F-CORE-12 | Export Excel | Implemented | Phase 6 | `.ai/architecture.md` |
| F-CORE-13 | Export CSV | Implemented | Phase 6 | `.ai/architecture.md` |
| F-CORE-14 | Backup ZIP | Implemented | Phase 6 | `.ai/architecture.md` §11 |
| F-CORE-15 | Restore from ZIP | Implemented | Phase 6 | `.ai/workflow.md` |
| F-CORE-16 | Settings (kelurahan identity, logo) | Implemented | Phase 6 | `.ai/database.md` |
| F-CORE-17 | Tauri desktop shell | Planned | Phase 7 | `.ai/architecture.md` |
| F-CORE-18 | Windows installer (.exe) | Planned | Phase 7 | `.ai/deployment.md` |

## 2. High Priority

| ID | Feature | Status | Phase |
|----|---------|--------|-------|
| F-HIGH-01 | Dashboard charts (per RT, per Lingkungan, per Pekerjaan) | Implemented | Phase 4 |
| F-HIGH-02 | Backup log table | Implemented | Phase 6 |
| F-HIGH-03 | OCR confidence highlighting | Planned | Phase 4 |
| F-HIGH-04 | OCR duplicate detection (image hash + KK number) | Planned | Phase 4 |
| F-HIGH-05 | Reset Filter button | Planned | Phase 3 |
| F-HIGH-06 | Export filename includes filter summary | Implemented | Phase 6 |
| F-HIGH-07 | KK photo viewer with zoom | Planned | Phase 3 |
| F-HIGH-08 | KK photo download | Planned | Phase 3 |
| F-HIGH-09 | Dashboard recent activity (5 newest KK & Penduduk) | Implemented | Phase 4 |
| F-HIGH-10 | Dashboard quick actions (Tambah / Data KK & Penduduk) | Implemented | Phase 4 |
| F-HIGH-11 | Dashboard polish (full-width layout, ordering, consistent colors) | Implemented | Phase 4 |
| F-HIGH-12 | OCR upload foundation (validation, secure storage, pending OCR job) | Implemented | Phase 5 |
| F-HIGH-13 | OCR processing pipeline (PENDING → PROCESSING → FAILED transitions, source image load + prerequisites) | Implemented | Phase 5 |
| F-HIGH-14 | OCR image preprocessing (validation, EXIF orientation, grayscale, resize, quality tracking) | Implemented | Phase 5 |
| F-HIGH-15 | OCR engine integration (Tesseract extraction, raw text + confidence persistence, failure/timeout handling) | Implemented | Phase 5 |
| F-HIGH-16 | OCR parsing and mapping (structured extraction of KK number, address, RT/RW/lingkungan, member rows; confidence handling; required-field validation) | Implemented | Phase 5 |
| F-HIGH-17 | OCR review and validation (operator review page, parsed-field display, missing-required + low-confidence highlighting, manual correction, pre-approval validation gate) | Implemented | Phase 5 |
| F-HIGH-18 | OCR import Kartu Keluarga (persist validated review result, duplicate KK-number detection, transactional write, OCR job marked saved) | Implemented | Phase 5 |
| F-HIGH-19 | OCR import Penduduk (persist approved review members as Penduduk + active KkAnggota membership under the imported KK, duplicate NIK detection, transactional write, OCR job marked penduduk-imported) | Implemented | Phase 5 |
| F-HIGH-20 | OCR workflow finalization (final COMPLETED status transition, completion timestamp, import summary + final processing metrics, transient-artifact cleanup, audit logging, centralized success/failure completion handler, idempotent completion) | Implemented | Phase 5 |
| F-HIGH-21 | Reporting & export foundation (PDF/XLSX/CSV export service, Filament table toolbar actions, exports respect active filters, date + filter-summary filename) | Implemented | Phase 6 |
| F-HIGH-22 | Backup & Restore operator page (Backup menu — "Buat Backup" action, backup archive list, two-step restore with explicit confirmation and restart advice) | Implemented | Phase 6 |
| F-HIGH-23 | Pengaturan (Settings) operator page (identity, logo, backup path — "SIMPAN" persisted to the settings singleton) | Implemented | Phase 6 |

## 3. Medium Priority

| ID | Feature | Status | Phase |
|----|---------|--------|-------|
| F-MED-01 | Dashboard cache (5-min invalidation) | Planned | Phase 3 |
| F-MED-02 | Application log rotation | Planned | Phase 6 |
| F-MED-03 | OCR performance metrics (mean confidence, latency) | Planned | Phase 4 |
| F-MED-04 | Backup integrity check on launch | Implemented | Phase 6 |
| F-MED-05 | Restore dry-run option | Planned | Phase 6 |
| F-MED-06 | Keyboard shortcuts for common actions | Planned | Phase 3 |
| F-MED-07 | KK photo replacement (when a clearer photo is obtained) | Planned | Phase 3 |

## 4. Low Priority

| ID | Feature | Status | Notes |
|----|---------|--------|-------|
| F-LOW-01 | UI color theme alternatives | Planned | Must remain professional |
| F-LOW-02 | Dashboard additional statistics (per gender per RT, etc.) | Planned | Only if time permits |
| F-LOW-03 | Operator profile field (name, signature) | Planned | May be cut |
| F-LOW-04 | Light user-facing resize of windows | Planned | Tauri default behavior is sufficient |

## 5. Future (Backlog — Not in KKN Scope)

Refer to `docs/BACKLOG.md` for the full list. Summary:

- Multi-user with role-based access
- Multi-PC LAN synchronization
- Cloud backup
- Automatic in-app update
- Mobile companion app
- WhatsApp notification
- Face recognition
- Public API
- Public dashboard
- Activity audit log
- Letter generation (surat menyurat)
- Finance, tax, payment modules

## 6. Dropped Features (Explicit Non-Goals)

| Dropped | Reason |
|---------|--------|
| In-app automatic data sync | Single PC, single operator |
| Built-in chat / messaging | Out of scope |
| Realtime collaboration | Single user |
| Spelling auto-correction | Manual review is required |

## 7. Implementation Status Tracking

Will be updated at the end of each phase per `roadmap.md` Step "Update Docs". Status entries will move from **Planned** → **Implemented** only after:
1. Code complete
2. Tested per `.ai/testing.md`
3. Documented in `docs/CHANGELOG.md`
4. Verified on the operator's workstation

## 8. Future Improvements

- Add a `screenshots/` folder feature to attach photos to a resident record (not just KK)
- Add a "household head" flag for the kepala keluarga (currently inferred from family_relation)
- Add a printable KK cover sheet layout
