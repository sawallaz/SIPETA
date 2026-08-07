| Field | Value |
|---|---|
| **Title** | SIPETA Product Decision Log |
| **Purpose** | Design contract for every UI/UX and workflow improvement between Phase 6 and Phase 7. Consolidates previous UI audits, product-owner decisions, workflow decisions, UI-consistency rules, terminology rules, dashboard rules, and future direction. |
| **Scope** | Dashboard, charts, OCR, Kartu Keluarga, Penduduk, PDF reporting, backup, terminology, UI consistency, and product philosophy. Applies to all operator-facing polish work before Phase 7 (Desktop Packaging). |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-08 |
| **Related Documents** | `.ai/ui-ux.md`, `.ai/workflow.md`, `.ai/ocr.md`, `.ai/decisions.md` (ADR-009, ADR-016), `docs/REQUIREMENTS.md` (FR-DB, FR-SF, FR-OCR, FR-EX, FR-BR), `docs/FEATURES.md`, `docs/USER_GUIDE.md`, `docs/PHASE4.md`, `docs/PHASE5.md`, `docs/PHASE6.md` |

---

# SIPETA Product Decision Log

This document is the **authoritative record of product decisions** that govern UI/UX
and workflow polish after Phase 6 and before Phase 7. It consolidates:

- the previous UI audit findings (see `ui-audit/` — `findings.json`, `measure.json`);
- product-owner decisions;
- workflow decisions;
- UI-consistency rules;
- terminology rules.

It records **decisions only**. It does **not** specify implementation details, and it
does **not** authorize or implement any feature yet. Every Phase 7 UI/UX work item must
satisfy this contract; any ambiguity is resolved in favor of these decisions.

> **Integration contract.** The established canon (`docs/README.md` reading order;
> precedence: `.ai/decisions.md` ADRs › `.ai/hermes.md` › `docs/REQUIREMENTS.md`)
> stays authoritative for scope and behaviour. This log records the operator-facing
> product intent and refines UI/UX presentation; it does **not** alter requirements,
> ADRs, or the constitution. Where this log is more specific than `.ai/ui-ux.md` /
> `.ai/workflow.md`, it takes precedence for UI presentation decisions, and those
> files may be updated to match.

---

## 1. Product Philosophy

**Decision D-PHIL-01.** SIPETA is designed for **Kelurahan operators, not software
developers**.

Consequences (mandatory properties of every workflow):

- Every workflow must minimise the number of clicks.
- Technical complexity must be hidden from the operator.
- Speed and clarity outrank visual flourish.
- The operator must never need to understand the technical term *OCR*,
  *confidence*, *pipeline*, *job*, or *import* (see §8 Terminology).

---

## 2. Dashboard

**Decision D-DASH-01.** Remove the duplicated Dashboard. There must be exactly one
Dashboard entry point and exactly one dashboard page.

**Decision D-DASH-02.** Remove Filament default widgets from the dashboard. The
dashboard must render **only** the custom widgets (KPI cards, Quick Actions, charts,
Recent Activity).

**Decision D-DASH-03.** The dashboard must use only the custom dashboard; no stock
Filament widget (e.g. the *Welcome* widget or the default stats overview/widget that
shows the framework version) appears on it.

**Decision D-DASH-04.** Maximum four major dashboard areas visible without excessive
scrolling. The four areas are:

1. KPI cards,
2. Quick Actions,
3. charts,
4. Recent Activity.

**Decision D-DASH-05.** KPI cards **always appear first** (top of the dashboard).

**Decision D-DASH-06.** Recent Activity stays **at the bottom**.

**Decision D-DASH-07.** Quick Actions stays **near the top** (directly below the KPI
cards, above the charts).

**Decision D-DASH-08.** Chart sizing must be **balanced**.

**Decision D-DASH-09.** Charts must **never dominate the page**. (Audit evidence:
the dashboard page scroll height reached ~4 369 px at 1440×900 before this rule; chart
sections rendered ~801 px each. Charts are to be compacted so the dashboard data fits
in the limit of D-DASH-04.)

Rationale: a single-kelurahan operator opens the dashboard mostly for an at-a-glance
overview; a long page defeats the purpose.

---

## 3. Charts

**Decision D-CHT-01. Pie/Doughnut charts are used only for:**

- Gender,
- Resident Status.

**Decision D-CHT-02. Horizontal Bar charts are used for:**

- Occupation,
- Education,
- Religion (once religion categories become large).

**Decision D-CHT-03. Vertical Bar charts are used for:**

- RT,
- RW.

**Decision D-CHT-04.** If a category exceeds approximately **six** items, replace the
pie/doughnut chart with a horizontal bar, or group small categories into **"Lainnya"**
("Other").

Rationale: a pie with more than ~6 slices is unreadable. Bars preserve labels and
absolute values better. (This is a hardening of the earlier Phase 4 chart-choice
decision; the mapping of *dimension → chart type* is now explicit.)

---

## 4. OCR (removed as a standalone concept)

**Decision D-OCR-01.** OCR is **not** a standalone feature. The operator-facing
concepts of an **OCR Page**, **OCR Jobs**, and **Review OCR** are removed from the
operator experience.

**Decision D-OCR-02.** The operator-facing workflow is:

```
Tambah Kartu Keluarga
        │
        ▼
  Choose:
   • Input Manual
        or
   • Scan Foto KK
        │
        ▼
  Upload Photo
        │
        ▼
  Automatic Extraction
        │
        ▼
  Review Filled Form
        │
        ▼
  Save
        │
        ▼
  KK and all Penduduk created automatically
```

**Decision D-OCR-03.** The operator should never need to understand the term **OCR**.
The flow is described as "scan a KK photo and review the pre-filled form".

Note: the OCR scan is internally driven by the existing pipeline (`.ai/ocr.md`,
ADR-009: OCR is an assistant, never auto-saves). This decision is about the
**operator-facing presentation**, not the removal of the OCR capability. The internal
engine/service, the `ocr_jobs` persistence (for audit and bookkeeping), the parsing,
and the review gate all remain; the UI simply presents them as "scan → review → save".

---

## 5. Kartu Keluarga (KK)

**Decision D-KK-01.** The KK list must show, per KK:

- Photo thumbnail
- KK number
- Head of family
- RT/RW
- Address
- Member count

**Decision D-KK-02.** Opening a KK must **immediately show its members**.

**Decision D-KK-03.** Avoid forcing the operator to navigate repeatedly between the
KK and Penduduk pages. Viewing a KK’s members must not require leaving the KK screen
to a separate Penduduk page.

Rationale: members are part of the KK context; splitting them forces extra clicks that
violate D-PHIL-01.

---

## 6. Penduduk

**Decision D-PEN-01.** Required filters:

- Name
- NIK
- KK Number
- RT
- RW
- Gender
- Religion
- Education
- Occupation
- Resident Status

**Decision D-PEN-02.** Age filtering is by:

- **Preset ranges:**
  - Balita (0–5)
  - Anak (6–12)
  - Remaja (13–17)
  - Dewasa (18–59)
  - Lansia (60+)
- **and a Custom Min Age / Max Age pair.**

**Decision D-PEN-03.** A **Reset Filter** control is always available.

**Decision D-PEN-04.** Implementation note: the backend already supports
`age_min` / `age_max` (see `PendudukExportService` and the Penduduk filters).
Only the UI mapping of the presets to `age_min`/`age_max` is required later; no
backend change is authorised by this document.

---

## 7. PDF / Reports

**Decision D-RPT-01.** Every report must include, before the detailed table:

1. The kelurahan **logo**
2. **Government heading** (Kelurahan Tanete identity; see the `Setting` singleton)
3. **Report title**
4. **Statistics summary**
5. **Active filters**
6. **Print date**
7. **Signature area**

(Existing PDF export already heads with the kelurahan identity; this decision then extends
the header to a defined block and adds a signature area.)

---

## 8. Backup

**Decision D-BAK-01. Current:** Local backup (`app/Services/BackupService.php`,
`app/Services/BackupIntegrityService.php`; see `docs/PHASE6.md` §6.2/6.3/6.4/6.6).

**Decision D-BAK-02. Future direction (roadmap only, NOT implemented):**

- Google Drive Backup
- Google Drive Restore
- Backup History
- Backup Verification

**Decision D-BAK-03.** The future direction above is **documented as roadmap only**.
Nothing in D-BAK-02 is implemented by this document.

---

## 9. Terminology

**Decision D-TERM-01.** All operator-facing text must use **Bahasa Indonesia**.

**Decision D-TERM-02.** The following technical terms must **never** appear operator-facing:

- OCR
- Confidence
- OCR Job
- Pipeline
- Import Result

**Decision D-TERM-03.** Replace them with operator-friendly wording:

| Avoid | Use instead |
|-------|-------------|
| OCR | "Scan Foto KK" / "memindai foto" |
| Confidence | "tingkat keyakinan" or simply hidden (level shown only as colour/hint, optional) |
| OCR Job / OCR Page / Review OCR | the add-KK flow with "Scan Foto KK" and "Review" |
| Pipeline | (never shown) |
| Import Result | "hasil" / a normal success message |

---

## 10. UI Consistency (global rules)

**Decision D-UI-01.** Minimum button height **44 px**.

**Decision D-UI-02.** Consistent spacing across all screens.

**Decision D-UI-03.** Consistent margins across all screens.

**Decision D-UI-04.** Consistent card padding.

**Decision D-UI-05.** Consistent icon usage (single icon set — Heroicons).

**Decision D-UI-06.** Consistent page header layout.

**Decision D-UI-07.** Action buttons in the same location across screens.

**Decision D-UI-08.** **Filters always above tables.**

---

## 11. Prioritization and Open Items

This log records decisions; it does not schedule them. The concrete improvement
queue to satisfy D-PHIL-01..D-UI-08 is tracked in the phase reports (`docs/PHASE4.md`,
`docs/PHASE5.md`, `docs/PHASE6.md`) and `docs/CHANGELOG.md` as each item lands. Any
item not yet implemented is pending, not dropped.

---

## 12. Consolidation Sources

This log consolidates previous audits and decisions, specifically:

- **Previous UI audit data:** `ui-audit/findings.json`, `ui-audit/measure.json`
  (D-DASH-02/03/08/09 — duplicated dashboard headings, Filament default widgets
  such as the "Welcome" / version text, oversized per-chart sections, low button
  height of 36 px against the 44 px rule).
- **Prior chart decision:** `docs/PHASE4.md` §4.3 → hardened into D-CHT-01..04.
- **OCR ADR & architecture:** `.ai/ocr.md`, ADR-009 (OCR as assistant) — renamed in
  the UI layer only by §4.
- **Workflow & UI philosophy:** `.ai/workflow.md`, `.ai/ui-ux.md`.
- **Requirements:** `docs/REQUIREMENTS.md` (FR-DB, FR-SF, FR-OCR, FR-EX, FR-BR).

No decisions here contradict the current Phase 5–6 implementation or requirements;
where this document extends the earlier text, it only refines UI intent and does not
terminate any requirement or ADR. Requirement/ADR terms (FR-DB*, FR-SF*, FR-OCR*,
FR-EX*, FR-BR*) continue to win on scope.