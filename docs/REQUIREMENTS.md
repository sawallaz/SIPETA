| Field | Value |
|---|---|
| **Title** | SIPETA Requirements Specification |
| **Purpose** | Single source of truth for what SIPETA must and must not do. Approved by project owner. |
| **Scope** | Functional, non-functional, user stories, acceptance criteria, and constraints for the KKN deliverable. |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/database.md`, `.ai/workflow.md`, `.ai/ui-ux.md`, `.ai/ocr.md`, `.ai/project-rules.md`, `.ai/roadmap.md`, `docs/FEATURES.md`, `docs/BACKLOG.md` |

---

# SIPETA Requirements Specification

## 1. Product Summary

SIPETA (Sistem Informasi Pendataan Penduduk Kelurahan Tanete) is a desktop application that digitizes population records of Kelurahan Tanete based on Kartu Keluarga (KK). The single operator records residents, performs OCR on KK photos, queries the data, and exports reports — all from a Windows desktop application launched by a double-click.

## 2. Functional Requirements

### 2.1 Authentication (FR-AUTH)
- **FR-AUTH-01** The system shall provide exactly one admin login.
- **FR-AUTH-02** Password shall be hashed using bcrypt or argon2id.
- **FR-AUTH-03** After 5 failed login attempts within 10 minutes, the account shall be locked for 15 minutes.
- **FR-AUTH-04** Logout shall clear the session token and return to the login screen.
- **FR-AUTH-05** Password reset shall be performed only by the developer (out-of-band); no self-service reset.

### 2.2 Data Penduduk (FR-DP)
- **FR-DP-01** Operator can create, read, update a Kartu Keluarga (KK) record.
- **FR-DP-02** Operator can create, read, update a Penduduk (resident) record.
- **FR-DP-03** Each resident belongs to exactly one KK.
- **FR-DP-04** Each NIK is unique across the database.
- **FR-DP-05** Each KK number is unique across the database.
- **FR-DP-06** KK photo is stored once per KK (not duplicated per resident).
- **FR-DP-07** KK photo formats accepted: JPG, JPEG, PNG. Maximum size: 5 MB. Minimum resolution: 800×600.
- **FR-DP-08** Resident deletion is forbidden for valid historical data. Only records created in error may be physically deleted.
- **FR-DP-09** Resident status may be ACTIVE, MOVED, or DECEASED. Status change requires a date and a note.

### 2.3 Search and Filter (FR-SF)
- **FR-SF-01** Single search box supports: Nama, NIK, Nomor KK.
- **FR-SF-02** Search returns results within 500 ms on up to 50,000 records.
- **FR-SF-03** Filters: RT, RW, Lingkungan, Gender, Religion, Education, Occupation, Resident Status, Exact Age, Age Range.
- **FR-SF-04** Multiple filters can be combined (AND semantic).
- **FR-SF-05** A "Reset Filter" button shall clear all filters.
- **FR-SF-06** Exports shall respect the active filter set.

### 2.4 OCR (FR-OCR)
- **FR-OCR-01** Operator can upload a KK photo and trigger OCR.
- **FR-OCR-02** OCR pipeline shall extract: KK number, alamat, RT, RW, lingkungan, nama, NIK, tempat lahir, tanggal lahir, jenis kelamin, agama, pendidikan, pekerjaan, status hubungan keluarga.
- **FR-OCR-03** OCR shall never write directly to the database. All extracted fields are presented to the operator for review.
- **FR-OCR-04** Fields with confidence < 70% shall be visually highlighted.
- **FR-OCR-05** The pipeline shall detect duplicate uploads by image hash + KK number and warn the operator.
- **FR-OCR-06** If OCR fails, the manual input form shall remain available.

### 2.5 Dashboard (FR-DB)
- **FR-DB-01** Display: Total Penduduk Aktif, Total KK, Laki-laki, Perempuan, Pindah, Meninggal.
- **FR-DB-02** Charts: Penduduk per RT, Penduduk per Lingkungan, Penduduk per Pekerjaan.
- **FR-DB-03** All counts use ACTIVE residents only.

### 2.6 Export (FR-EX)
- **FR-EX-01** Export to PDF, Excel (.xlsx), and CSV.
- **FR-EX-02** Export shall respect active filters.
- **FR-EX-03** Export filename shall include filter summary and date.

### 2.7 Backup and Restore (FR-BR)
- **FR-BR-01** Backup creates a ZIP containing: SQL dump, KK photos, settings.
- **FR-BR-02** Backup filename pattern: `backup_YYYY-MM-DD_HHMMSS.zip`.
- **FR-BR-03** Existing backups shall never be overwritten.
- **FR-BR-04** Restore shall validate the ZIP integrity before applying.
- **FR-BR-05** Restore shall require explicit confirmation.
- **FR-BR-06** After restore, the operator shall be advised to restart the application.

### 2.8 Settings (FR-SET)
- **FR-SET-01** Single settings record: kelurahan name, kecamatan name, kabupaten name, provinsi name, logo path, backup path.
- **FR-SET-02** Settings row is created at first launch and is never deleted.

### 2.9 Audit (FR-AUD)
- **FR-AUD-01** Every backup is logged in `backup_logs` table with timestamp, filename, status, size, duration.
- **FR-AUD-02** OCR attempts are logged with confidence distribution, errors, and review outcome.

## 3. Non-Functional Requirements

### 3.1 Performance (NFR-PERF)
- **NFR-PERF-01** Dashboard shall render within 1 second on 50,000 records.
- **NFR-PERF-02** Search shall return within 500 ms on 50,000 records.
- **NFR-PERF-03** Filter change shall re-render the table within 800 ms.
- **NFR-PERF-04** OCR shall complete within 10 seconds per KK photo on a target desktop (i5, 8 GB RAM).
- **NFR-PERF-05** Export shall stream rows (no full memory load).

### 3.2 Usability (NFR-UX)
- **NFR-UX-01** A first-time operator shall complete the core workflow within 15 minutes of training.
- **NFR-UX-02** Common tasks (search, add, edit) shall complete in ≤ 3 clicks.
- **NFR-UX-03** All UI labels shall be in Bahasa Indonesia.
- **NFR-UX-04** Action buttons shall be ≥ 44 px tall.
- **NFR-UX-05** Minimum supported resolution: 1366×768. Primary: 1920×1080.

### 3.3 Reliability (NFR-REL)
- **NFR-REL-01** Data integrity is the highest priority. No silent data loss.
- **NFR-REL-02** Historical records shall never be physically deleted except for duplicate/wrong input created in the same session.
- **NFR-REL-03** Backup must succeed before any data is finalized as safe.

### 3.4 Security (NFR-SEC)
- **NFR-SEC-01** All inputs validated via Form Request.
- **NFR-SEC-02** All outputs escaped via Blade/Eloquent.
- **NFR-SEC-03** Database credentials stored only in `.env` (never in installer).
- **NFR-SEC-04** Dedicated DB user `sipeta_app` with limited privileges (no DROP, no GRANT).
- **NFR-SEC-05** Uploaded files validated by MIME type and size.

### 3.5 Maintainability (NFR-MAINT)
- **NFR-MAINT-01** Service layer for all business logic.
- **NFR-MAINT-02** PSR-12 compliance enforced.
- **NFR-MAINT-03** Strict types where appropriate.
- **NFR-MAINT-04** No business logic in Controllers.

### 3.6 Portability (NFR-PORT)
- **NFR-PORT-01** Single Windows installer (.exe) deploys the entire application.
- **NFR-PORT-02** Application and data folders are separated to support safe updates.

## 4. User Stories

| ID | As a | I want to | So that |
|----|------|-----------|---------|
| US-01 | Admin | login with my password | I can access SIPETA |
| US-02 | Admin | view the dashboard | I know the current population statistics |
| US-03 | Admin | search by name, NIK, or KK number | I find a resident quickly |
| US-04 | Admin | filter by RT, RW, lingkungan | I focus on a specific area |
| US-05 | Admin | upload a KK photo and run OCR | I avoid typing fields manually |
| US-06 | Admin | review OCR results before saving | I confirm data is correct |
| US-07 | Admin | add a new resident manually | I can record residents without OCR |
| US-08 | Admin | edit a resident | I can correct or update data |
| US-09 | Admin | mark a resident as MOVED or DECEASED | I keep history accurate |
| US-10 | Admin | export filtered data to PDF/Excel/CSV | I share reports with stakeholders |
| US-11 | Admin | create a backup | I protect data from loss |
| US-12 | Admin | restore from a backup | I recover from data loss |
| US-13 | Admin | configure kelurahan name and logo | I customize the application |

## 5. Acceptance Criteria

### 5.1 Authentication
- Operator can login with correct password.
- Operator cannot login with wrong password 5 times consecutively.
- Logout ends the session completely.

### 5.2 Data
- Creating a KK with a duplicate KK number is rejected with a clear Bahasa Indonesia message.
- Creating a resident with a duplicate NIK is rejected.
- KK photo is displayed correctly on the KK detail page.
- Resident status change to MOVED requires a date and note.

### 5.3 Search and Filter
- Typing a partial name returns matching residents.
- Combining filters narrows results correctly.
- Reset Filter clears all active filters.

### 5.4 OCR
- Uploading a clear KK photo produces all required fields.
- Fields with low confidence are visually highlighted.
- No data is written to the database before the operator clicks Save.
- A duplicate KK detected from a previous upload shows a warning.

### 5.5 Dashboard
- Counts match the underlying database query.
- Charts reflect active residents only.

### 5.6 Export
- PDF export contains the filtered rows.
- Excel and CSV exports contain the same rows.
- Filenames include the date and filter summary.

### 5.7 Backup
- Backup ZIP contains the database, photos, and settings.
- Existing backups are not overwritten.
- Restore warns the operator before applying changes.

### 5.8 Deployment
- The installer produces a desktop shortcut.
- Launching the shortcut starts the application without terminal interaction.
- Application data survives an upgrade.

## 6. Constraints

| ID | Constraint |
|----|------------|
| C-01 | Single operator, single PC. |
| C-02 | Windows 10/11 target. |
| C-03 | Develop on Parrot OS. |
| C-04 | No internal network required. |
| C-05 | No cloud dependency. |
| C-06 | No mobile app. |
| C-07 | No multi-user roles. |
| C-08 | No API. |
| C-09 | No public portal. |
| C-10 | All UI text in Bahasa Indonesia. |
| C-11 | Complete within KKN timeline. |

## 7. Out of Scope (Explicit Non-Goals)

- Letter generation (surat menyurat)
- Finance, tax, payment modules
- Mobile application
- WhatsApp integration
- Multi-tenant support
- Public portal
- Face recognition
- Cloud synchronization
- LAN multi-PC sync

## 8. Implementation Notes

- Authentication: Filament built-in auth with a single admin user seeded at deployment.
- Storage: PHP-side storage. Tauri window uses local file URLs from the PHP server.
- File paths: store filename only in DB; resolve absolute path on the server side.
- Domain logic: implemented in Services, not Controllers or Filament Resources.
- Cross-reference: every functional requirement is implemented in exactly one Service.

## 9. Future Improvements

Captured in `docs/BACKLOG.md`:
- Multi-user roles
- Multi-PC LAN sync
- Cloud backup
- Automatic in-app update
- Mobile companion app
- WhatsApp notification
- Face recognition
- Public API
- Activity audit log
