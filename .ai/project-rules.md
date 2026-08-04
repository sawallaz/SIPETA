| Field | Value |
|---|---|
| **Title** | SIPETA Business Rules |
| **Purpose** | Define the business rules of SIPETA. These rules are mandatory for all AI agents. |
| **Scope** | Domain rules for KK, Penduduk, status, OCR, search, filter, export, backup, validation. |
| **Version** | 1.1.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/database.md`, `.ai/workflow.md`, `.ai/ocr.md`, `docs/REQUIREMENTS.md`, `docs/USER_GUIDE.md` |

---

# SIPETA Business Rules

## 1. Core Business

SIPETA is a Population Data Management System based on Kartu Keluarga (KK). It is NOT a replacement for Dukcapil or SIAK.

## 2. General Rules

1. Simplicity over complexity.
2. Data integrity is the highest priority.
3. Never lose historical data.
4. Every action should reduce operator workload.

## 3. Kartu Keluarga Rules

- One KK has one unique KK number.
- One KK can contain many residents.
- One KK stores one KK photo.
- KK number must be unique across the database.
- KK photo belongs to the KK, not individual residents.

## 4. Resident Rules

Each resident:

- Belongs to one KK.
- Has one unique NIK.
- Has exactly one status.
- Has one birth date.
- May never share a NIK.

## 5. Resident Status

Allowed values: `ACTIVE`, `MOVED`, `DECEASED`.

| Status | Rule |
|--------|------|
| `ACTIVE` | Counted in dashboard. Included in active reports. |
| `MOVED` | Not counted as active. History remains. |
| `DECEASED` | Not counted as active. History remains. |

- Never delete a record because a resident moved or died.
- Changing status to `MOVED` requires a date and a note.
- Changing status to `DECEASED` requires a date and a note.

## 6. Age Rules

- Never store age.
- Always calculate from `birth_date`.
- All reports and dashboard use calculated age.

## 7. OCR Rules

- OCR is an assistant.
- OCR never inserts directly into the database.
- Operator must review every extracted field before saving.
- If confidence < 70%, the field is highlighted.

## 8. Search Rules

- Search must support: Name, NIK, KK Number.
- Search should be fast (≤ 500 ms on 50K records).
- Search is case-insensitive.

## 9. Filter Rules

- Filters: RT, RW, Lingkungan, Gender, Religion, Education, Occupation, Resident Status, Exact Age, Age Range.
- Multiple filters can be combined (AND semantic).
- A **Reset Filter** button must exist.

## 10. Export Rules

- Exports always respect active filters.
- Formats: PDF, Excel (.xlsx), CSV.
- Filenames include date and filter summary.

## 11. Backup Rules

- Backup must include: database, KK photos, settings.
- Backup is a ZIP archive.
- Backup never deletes previous backups.
- Default backup path is the data folder (`backup/`).

## 12. Restore Rules

- Restore requires confirmation.
- ZIP integrity is validated before applying.
- After restore: validate database, notify the operator, recommend restart.

## 13. Data Validation

**Unique fields:**

- KK number
- NIK

**Required fields (Penduduk):**

- Full name
- NIK
- Birth date
- Gender
- KK linkage

**Required fields (KK):**

- KK number
- Address
- RT
- RW
- Lingkungan

## 14. Delete Rules

- Physical deletion is allowed only for:
  - Duplicate data created in error.
  - Wrong input discovered immediately.
- Never delete valid historical residents.

## 15. Dashboard Rules

- Dashboard displays only `ACTIVE` residents.
- Required cards: Total KK, Penduduk Aktif, Laki-laki, Perempuan, Pindah, Meninggal.

## 16. Performance Rules

- Searching should remain responsive.
- Filtering should re-render without page reload when possible.
- Index searchable columns.

## 17. Security Rules

- Validate every request via Form Request.
- Never trust OCR output.
- Never expose database errors to operators.
- DB user has limited privileges.

## 18. UI Rules

- All UI in Bahasa Indonesia.
- Action buttons large (≥ 44 px tall).
- Color is never the only indicator.
- Stack traces are never shown.

## 19. Future Features (Not in KKN)

Captured in `docs/BACKLOG.md`:

- Multi-user
- Mobile app
- Cloud sync
- WhatsApp
- API
- Face recognition

## 20. Success Criteria

The application is considered successful when:

- Operator can learn it in under 15 minutes.
- Data entry is faster than manual records.
- Reports are generated in a few clicks.
- Backup is simple.
- OCR reduces typing effort.
- The application is stable.

## 21. Implementation Notes

- All enums live in `App\Enums\*`.
- Validation messages are in Bahasa Indonesia.
- Form Requests own all validation rules.

## 22. Future Improvements

- Add detailed field-level permissions if multi-user is enabled.
- Add a workflow audit trail if compliance is required.
