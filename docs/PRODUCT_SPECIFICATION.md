| Field | Value |
|---|---|
| **Title** | SIPETA Product Specification |
| **Purpose** | High-level product description for stakeholders and decision-makers. |
| **Scope** | Product positioning, target users, value proposition, scope boundaries, success metrics. |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `docs/REQUIREMENTS.md`, `docs/FEATURES.md`, `docs/USER_GUIDE.md`, `docs/BACKLOG.md` |

---

# SIPETA Product Specification

## 1. Product Name

**SIPETA** — Sistem Informasi Pendataan Penduduk Kelurahan Tanete.

## 2. Product Category

Population Data Management. Desktop application.

## 3. Target Customer

Kelurahan Tanete — a single kelurahan with one admin operator.

## 4. Problem Statement

Population data is currently maintained in paper records or disconnected spreadsheets. The operator:

- Spends time on manual data entry.
- Has no fast search across households.
- Cannot easily produce reports for stakeholders.
- Risks data loss with no reliable backup.

## 5. Solution

SIPETA is a Windows desktop application that:

- Stores population data in a structured MySQL database.
- Provides a single-page workspace for KK and Penduduk.
- Reduces manual typing through OCR on KK photos.
- Provides fast search and filters.
- Produces reports in PDF, Excel, and CSV.
- Backs up the entire dataset in a single ZIP file.

## 6. Target User

| Role | Count | Permissions |
|------|-------|-------------|
| Admin Operator | 1 | Full access: login, CRUD, OCR, export, backup, restore, settings |

No other roles. No multi-user.

## 7. Value Proposition

- **For the operator**: less typing, faster lookups, instant reports.
- **For the kelurahan**: durable digital record, safe backup, professional reports.
- **For the project owner**: simple deployment, no terminal knowledge required.

## 8. Scope

### 8.1 In Scope

- KK and Penduduk CRUD.
- OCR from KK photo (assistant, not auto-save).
- Search, filter, sort.
- PDF, Excel, CSV export.
- Backup and restore.
- Single admin login.
- Desktop application.

### 8.2 Out of Scope (KKN)

- Mobile app.
- Multi-user.
- Cloud sync.
- LAN sync.
- WhatsApp integration.
- Public portal.
- API.
- Letter generation.
- Finance, tax, payment.
- Face recognition.

See `docs/BACKLOG.md` for the full future list.

## 9. KPIs

- Operator learns in under 15 minutes.
- Reports generated in under 1 minute.
- Backup completed in under 30 seconds.
- Dashboard renders in under 1 second.
- OCR completes in under 10 seconds per KK photo.

## 10. Success Criteria

The product is successful when:

1. The operator uses the application daily without assistance.
2. Reports can be generated in a few clicks.
3. Backups are reliable and recoverable.
4. OCR reduces manual typing for the majority of fields.
5. The application launches from a desktop shortcut.

## 11. Constraints

- Single PC, single operator.
- Windows 10/11 only.
- No required internet connection.
- Bahasa Indonesia UI.
- KKN delivery timeline.

## 12. Risks

| Risk | Mitigation |
|------|-----------|
| KKN timeline slips | Strict scope cut; backlog stays untouched. |
| OCR accuracy is low | Confidence threshold + manual review form. |
| Operator training is hard | 15-minute test before delivery. |
| Backup fails | Default backup location; backup log. |
| Data corruption | Daily recommended backup; restore tested. |

## 13. Pricing

Not applicable (KKN project).

## 14. Roadmap Highlights

- Phase 1–2: Foundation and database.
- Phase 3: Core CRUD.
- Phase 4: OCR.
- Phase 5: Reports.
- Phase 6: Backup.
- Phase 7: Desktop packaging.
- Phase 8: Deployment.

Details: `.ai/roadmap.md`.

## 15. Implementation Notes

- All technical decisions recorded in `.ai/decisions.md`.
- All business rules recorded in `.ai/project-rules.md`.
- All schema in `.ai/database.md`.
- All OCR in `.ai/ocr.md`.

## 16. Future Improvements

Planned for after KKN:

- Multi-user with roles.
- LAN sync.
- Cloud backup.
- Mobile companion.
- Public dashboard.
- API.
