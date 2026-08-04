| Field | Value |
|---|---|
| **Title** | SIPETA Operator Workflow |
| **Purpose** | Define the standard workflow for every action. Minimize steps, clicks, and confusion. |
| **Scope** | All operator-facing workflows: search, filter, add, OCR, edit, status change, export, backup, restore. |
| **Version** | 1.1.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/database.md`, `.ai/ui-ux.md`, `.ai/ocr.md`, `docs/REQUIREMENTS.md`, `docs/USER_GUIDE.md` |

---

# SIPETA Operator Workflow

This document is the authoritative reference for how the operator performs each task. Most workflows should be completable in 2–3 clicks.

## 1. Navigation

Five menus only:

1. Dashboard
2. Data Penduduk
3. Laporan
4. Backup
5. Pengaturan

No additional menus are added without project owner approval.

## 2. Main Screen Philosophy

The operator spends almost all working time inside Data Penduduk. Everything starts from this page: search, filter, add, edit, OCR, export.

## 3. Dashboard Workflow

1. Open application.
2. Dashboard displays automatically.
3. View KPI cards and charts.
4. If a deeper inspection is needed, click "Data Penduduk".

## 4. Search Workflow

1. Open Data Penduduk.
2. Click the search box.
3. Type: Name, NIK, or KK Number.
4. Results update as you type.

## 5. Filter Workflow

All filters are visible on the same page. Multiple filters can be combined:

1. Choose RT, RW, Lingkungan, Gender, Religion, Education, Occupation, Status, Exact Age, or Age Range.
2. Table updates automatically.
3. Click **RESET FILTER** to clear all.

## 6. Add Resident Workflow

1. Click **+ TAMBAH PENDUDUK**.
2. Choose one:
   - **Upload Foto KK** — proceed to OCR workflow.
   - **Input Manual** — proceed to manual form.

## 7. OCR Workflow

See `.ai/ocr.md` for the full pipeline. Operator-level summary:

1. Choose KK photo.
2. Click **MULAI OCR**.
3. Wait for processing (≤ 10 seconds).
4. Review the pre-populated form.
5. Highlight indicates low confidence.
6. Edit any field as needed.
7. Click **SIMPAN**.

OCR never saves automatically.

## 8. Manual Input Workflow

1. Click **+ TAMBAH PENDUDUK**.
2. Choose **Input Manual**.
3. Fill the form fields.
4. Click **SIMPAN**.

## 9. Edit Workflow

1. Search for the resident.
2. Click **DETAIL**.
3. Click **EDIT**.
4. Update fields.
5. Click **SIMPAN**.

## 10. Resident Status Workflow

1. Open resident detail.
2. Click **UBAH STATUS**.
3. Choose new status:
   - **MOVED** — date and note required.
   - **DECEASED** — date and note required.
4. Click **SIMPAN**.

History is preserved.

## 11. Delete Workflow

Physical deletion is reserved for invalid records created in error. For valid historical data, change the status instead.

1. Open resident detail.
2. Click **HAPUS** (only available for invalid records).
3. Confirm in the dialog.
4. Click **HAPUS**.

## 12. KK Photo Workflow

1. Open KK detail.
2. View the photo.
3. Click **ZOOM** for full screen.
4. Click **UNDUH** to download.
5. Click **GANTI FOTO** to replace with a clearer version.

## 13. Export Workflow

1. Apply filters (if needed).
2. Click **PDF**, **EXCEL**, or **CSV** under the table.
3. Choose the destination.
4. Wait for the file to be generated.

Export always uses the current filter set. The filename includes the date and filter summary.

## 14. Backup Workflow

1. Open **Backup**.
2. Click **BUAT BACKUP**.
3. Wait for the ZIP archive.
4. The backup is saved to the configured `backup_path`.

## 15. Restore Workflow

1. Open **Backup**.
2. Click **RESTORE**.
3. Choose a ZIP file.
4. The system validates the ZIP.
5. Click **KONFIRMASI** to proceed.
6. Wait for the restore to complete.
7. Restart the application.

Restore cannot be undone.

## 16. Pengaturan Workflow

1. Open **Pengaturan**.
2. Edit kelurahan identity, logo, backup path.
3. Click **SIMPAN**.

## 17. Error Handling

If anything fails:

1. The system shows a friendly message in Bahasa Indonesia.
2. The message explains the problem.
3. The message suggests a solution.
4. Stack traces are never shown.

OCR errors specifically: confidence < 70% triggers a visual highlight. The operator must review or correct.

## 18. UI Principles

- Maximum 2–3 clicks for common tasks.
- Avoid unnecessary popup dialogs.
- Show loading indicators for OCR, export, backup, restore.
- Use consistent button styles.
- Use clear Bahasa Indonesia labels.
- Color is never the only indicator — always pair with text.

## 19. Workflow Golden Rules

1. Simple first.
2. Fast first.
3. Accurate first.
4. Never sacrifice data integrity.
5. Every workflow must be understandable by a first-time operator.

## 20. Implementation Notes

- Workflows are implemented as Filament Actions or Service methods.
- The UI uses Filament's Table filters and Searchable behavior.
- No business logic lives in the Controller.

## 21. Future Improvements

Captured in `docs/BACKLOG.md`:

- Bulk operations (e.g., change status for many residents at once).
- Saved filter presets.
- Workflow shortcuts (keyboard).
