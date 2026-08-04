| Field | Value |
|---|---|
| **Title** | SIPETA Backlog (Post-KKN) |
| **Purpose** | Capture every feature that is intentionally out of KKN scope. Items here are NOT implemented during the KKN period. |
| **Scope** | Future features, alternative directions, deferred decisions. |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `docs/REQUIREMENTS.md`, `docs/FEATURES.md`, `.ai/roadmap.md`, `.ai/decisions.md` |

---

# SIPETA Backlog

This document lists features that are **explicitly out of scope** for the KKN period. They are deferred for future iterations.

**Hard rule:** No feature in this document is implemented during KKN. Bringing any item from this list into the current implementation requires an explicit update to `docs/REQUIREMENTS.md`, `docs/FEATURES.md`, and an ADR in `.ai/decisions.md`.

## 1. Identity & Access

### B-AUTH-01 Multi-user with role-based access
- Multiple operators with distinct roles (admin, viewer, data-entry).
- Filament Shield or Spatie permissions.
- Migration from `users` table with role column to full RBAC.

### B-AUTH-02 Self-service password reset
- Email-based reset.
- Requires SMTP infrastructure (out of scope).

### B-AUTH-03 Activity audit log
- Every create / update / delete event recorded with user, timestamp, before/after.
- Separate `activity_logs` table.
- Read-only audit viewer.

## 2. Network & Sync

### B-NET-01 Multi-PC LAN synchronization
- Multiple operators across multiple PCs.
- Master-slave or peer-to-peer replication.
- Conflict resolution policy.

### B-NET-02 Cloud backup
- Push ZIP backups to S3, Google Drive, or similar.
- Encrypted at rest.

### B-NET-03 Cloud sync
- Real-time database synchronization.
- Requires DB server hosting (out of scope).

## 3. Mobile & Web

### B-MOB-01 Mobile companion app
- Read-only access for kepala lingkungan.
- Flutter or React Native.

### B-MOB-02 WhatsApp integration
- Notify residents of status changes.
- Bot for simple queries.

### B-WEB-01 Public dashboard
- Anonymized statistics for the public.
- Requires web hosting.

### B-WEB-02 Public API
- REST/GraphQL for third-party use.
- Requires authentication layer.

## 4. Data Expansion

### B-DATA-01 Face recognition
- Match resident photos against KK photos.
- Privacy concerns require legal review.

### B-DATA-02 Document scanning general
- Beyond KK: KTP, Akta Nikah, Akta Lahir.
- New OCR templates per document type.

### B-DATA-03 Family tree
- Visualize family relationships.
- Requires lineage tracking in schema.

### B-DATA-04 Migration history
- Track pindah masuk / pindah keluar.
- Cross-kelurahan coordination.

### B-DATA-05 Letter generation (surat menyurat)
- Template-based letter generation.
- Multiple signatories.
- Out of scope per ADR-013.

## 5. Operations

### B-OPS-01 Automatic in-app update
- Self-updating mechanism.
- Code signing required.
- CDN hosting required.

### B-OPS-02 Real-time monitoring
- Operator analytics.
- Heatmaps of usage.

### B-OPS-03 Multi-language UI
- English / Bahasa Indonesia switch.
- Translation framework.

### B-OPS-04 Dark mode
- UI theme switch.
- Requires design system expansion.

## 6. Finance & Tax (Explicit Non-Goals)

### B-FIN-01 Financial module
- Tax tracking.
- Payment recording.
- Receipt generation.

These are explicitly excluded per ADR-013 and the product vision.

## 7. Hardware & Devices

### B-HW-01 Fingerprint scanner integration
- For attendance or verification.
- Driver compatibility required.

### B-HW-02 Printer integration
- Direct print to dot-matrix printers.
- Receipt-style KK print.

## 8. Deferred Decisions

| ID | Decision | Why deferred |
|----|----------|--------------|
| B-DEC-01 | Database engine substitution (PostgreSQL) | MySQL is sufficient for KKN scope |
| B-DEC-02 | Microservice split | Single Laravel app is simpler |
| B-DEC-03 | SPA frontend (Vue/React) | Filament provides enough |
| B-DEC-04 | Event sourcing | Schema is simpler without |
| B-DEC-05 | Blockchain audit trail | Out of scope |

## 9. Future Schema Tables (Already Decided)

These tables are explicitly NOT created during KKN. They are documented here so future implementers know the intent:

- `audit_logs` — change history
- `activity_logs` — operator actions
- `notifications` — in-app alerts
- `api_tokens` — API auth (no API yet)
- `family_relations` — extended family ties

## 10. Process

When a backlog item is selected for a future release:

1. Move it from this file to `docs/REQUIREMENTS.md` and `docs/FEATURES.md`.
2. Add an ADR entry in `.ai/decisions.md` recording the decision and date.
3. Add a roadmap phase in `.ai/roadmap.md`.
4. Update `docs/CHANGELOG.md`.

Only after these steps is the feature considered "in scope" for that future release.
