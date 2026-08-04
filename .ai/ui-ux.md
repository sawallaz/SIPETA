| Field | Value |
|---|---|
| **Title** | SIPETA UI/UX Guidelines |
| **Purpose** | Visual and interaction design rules for a non-technical operator. |
| **Scope** | All screens, components, navigation, typography, color, error handling, accessibility. |
| **Version** | 1.1.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/workflow.md`, `.ai/ocr.md`, `docs/USER_GUIDE.md` |

---

# SIPETA UI/UX Guidelines

## 1. Goal

Design an interface usable by a first-time operator with minimal training. The application must feel like a simple desktop application, not a complex government system.

## 2. Design Principles

- Clean — no decorative widgets.
- Fast — perceived latency matters.
- Consistent — same style across screens.
- Accessible — large targets, readable text, high contrast.
- Minimal clicks — common tasks in 2–3 clicks.
- Bahasa Indonesia — all labels in Indonesian.
- Large action buttons.

## 3. Navigation

Five menu items only:

1. **Dashboard**
2. **Data Penduduk**
3. **Laporan**
4. **Backup**
5. **Pengaturan**

No additional menus without project owner approval.

## 4. Dashboard

KPI cards (counts of `ACTIVE` residents only):

- Penduduk Aktif
- Total KK
- Laki-laki
- Perempuan
- Pindah
- Meninggal

Charts:

- Penduduk per RT
- Penduduk per Lingkungan
- Penduduk per Pekerjaan

No decorative widgets.

## 5. Data Penduduk (Main Screen)

This is the primary workspace. Layout (top to bottom):

1. Search bar.
2. **+ TAMBAH PENDUDUK** button.
3. Filter row.
4. Table.
5. Export buttons (PDF / Excel / CSV).

### 5.1 Search

- Single search box.
- Searches Nama, NIK, Nomor KK.
- Updates as you type.

### 5.2 Filters

- Visible without opening a modal.
- Multiple filters combinable.
- **RESET FILTER** button always visible.

### 5.3 Add Resident

- One button: **+ TAMBAH PENDUDUK**.
- Opens two options:
  - **Upload Foto KK** (OCR path).
  - **Input Manual**.

## 6. OCR Screen

Steps:

1. Select photo.
2. OCR processing — show progress.
3. Review extracted fields.
4. Highlight low-confidence fields.
5. Click **SIMPAN**.

OCR never saves automatically.

## 7. Forms

Group fields into sections:

- **Section 1 — KK Information**
- **Section 2 — Penduduk Information**
- **Section 3 — Status**
- **Section 4 — Notes**

Avoid long scrolling.

## 8. Table

Columns:

- Nama
- NIK
- Umur
- RT
- Lingkungan
- Status
- Actions (Detail, Edit)

No excessive action buttons.

## 9. Detail Screen

Show:

- Penduduk information.
- KK information.
- Status history.
- KK photo.

Buttons:

- **EDIT**
- **UNDUH FOTO KK**
- **UBAH STATUS**

## 10. Status Colors

Color is never the only indicator. Always pair with text.

- **Active** = Green + "Aktif"
- **Moved** = Orange + "Pindah"
- **Deceased** = Red + "Meninggal"

## 11. Notifications

Short, friendly messages in Bahasa Indonesia:

- "Data berhasil disimpan."
- "Backup berhasil."
- "Export selesai."

No technical language.

## 12. Loading

Always show a loading indicator during:

- OCR
- Export
- Backup
- Restore

## 13. Icons

- One icon set only (Heroicons via Filament/Breadcrumbs).
- Do not mix icon libraries.

## 14. Responsive

- Primary target: 1920×1080.
- Supported: 1366×768 (minimum).
- Layout is desktop-first; not designed for mobile.

## 15. Accessibility

- Readable fonts (system default, ≥ 14 px body, ≥ 16 px headings).
- Adequate spacing (≥ 8 px padding).
- Large clickable targets (≥ 44 px).
- High contrast (WCAG AA minimum).

## 16. Error Messages

Every error message must:

1. Explain the problem in Bahasa Indonesia.
2. Suggest a solution.
3. Never expose stack traces.

## 17. Indonesian Typography

- Use sentence case, not Title Case, for buttons.
- Use Title Case for headings.
- Avoid mixing English and Indonesian in the same UI.

## 18. Golden Rules

- One page → one primary action.
- Minimal typing.
- Minimal clicks.
- Consistency over decoration.
- Operator productivity > visual effects.

## 19. Implementation Notes

- Use Filament's built-in form and table components.
- Use Breeze/Filament theme with Tailwind utility classes.
- Loading indicators use Filament's `Section` loading state or custom Alpine.
- Filament's notification system for short messages.

## 20. Future Improvements

Captured in `docs/BACKLOG.md`:

- Dark mode.
- Multi-language toggle.
- Operator-customizable dashboard layout.
