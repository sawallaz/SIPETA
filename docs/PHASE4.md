| Field | Value |
| --- | --- |
| **Title** | SIPETA Phase 4 — Dashboard |
| **Purpose** | Track Phase 4 (Admin Panel Dashboard) sub-phase progress. |
| **Scope** | 4.1 Dashboard foundation (layout + placeholder KPI cards). 4.2 Enhanced KPI cards (population statistics). Later: charts, filters, exports, polish. |
| **Version** | 1.1.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-06 |
| **Related Documents** | `.ai/hermes.md`, `.ai/filament.md`, `docs/PHASE3.md`, `app/Filament/Pages/Dashboard.php`, `app/Filament/Widgets/SipetaStatsOverview.php` |

---

# Phase 4 — Dashboard

## 4.1 Dashboard Foundation

### 4.1.1 Objective

Build only the dashboard foundation for the Filament Admin Panel: the dashboard
page layout and placeholder KPI cards. No statistics, charts, OCR, exports, or
analytics yet. Phase 3 is untouched.

### 4.1.2 Deliverables

- **Dashboard page** (`app/Filament/Pages/Dashboard.php`) — custom page extending
  `Filament\Pages\Dashboard`, overriding `getWidgets()` (must be `public`) to mount
  the KPI widget. This satisfies the "create the dashboard layout" task.
- **KPI widget** (`app/Filament/Widgets/SipetaStatsOverview.php`) — a
  `StatsOverviewWidget` (Filament v4.12.5) with four placeholder cards:
  - Total Kartu Keluarga — `KartuKeluarga::count()`
  - Total Penduduk — `Penduduk::count()`
  - Laki-laki — `Penduduk::where('gender', Gender::LAKI_LAKI->value)->count()`
  - Perempuan — `Penduduk::where('gender', Gender::PEREMPUAN->value)->count()`
  - Cards use existing models only. Icons: `heroicon-o-home-modern`,
    `heroicon-o-users`, `heroicon-o-user`.
  - `$isLazy = false` so the cards render in the initial HTML (cheap count queries).

### 4.1.3 Not done (explicitly out of scope for 4.1)

- No charts (ChartWidget / BarChart / PieChart etc.).
- No widgets other than the KPI overview.
- No statistics breakdowns, filters, or exports.
- No changes to Resources, Models, Migrations, Seeders, or prior-phase tests.

### 4.1.4 Files changed (4.1 only)

| File | Change |
| --- | --- |
| `app/Filament/Pages/Dashboard.php` | New — dashboard page mounting the KPI widget. |
| `app/Filament/Widgets/SipetaStatsOverview.php` | New — placeholder KPI cards. |
| `tests/Feature/Phase4/DashboardTest.php` | New — dashboard renders + KPI labels visible (2 tests). |
| `docs/PHASE4.md` | New — this document. |

### 4.1.5 Verification

```text
php artisan test       89 passed (402 assertions), 3 skipped
./vendor/bin/pint --test  PASS (117 files)
```

`npm run build` not applicable — no frontend asset, Tailwind class, or Blade
view was added (widget reuses Filament's shipped `stats-overview-widget` view).

### 4.1.6 Commit

`feat(dashboard): Phase 4.1 — dashboard foundation`

---

## Phase 4.2

### 4.2.1 Objective

Enhance the dashboard KPI cards with useful population statistics derived
from the existing schema. No charts, tables, exports, filters, new models,
or new migrations.

### 4.2.2 Deliverables

- **KPI widget** (`app/Filament/Widgets/SipetaStatsOverview.php`) — the four
  4.1 placeholder cards are kept; seven cards are added (eleven total), all
  computed from existing tables only:
  - *Keluarga* group: Total Kartu Keluarga (`kartu_keluarga` count),
    Total Kepala Keluarga (`penduduk.family_relation = KEPALA_KELUARGA`),
    Total Anggota Keluarga (`penduduk.family_relation != KEPALA_KELUARGA`;
    the two partition Total Penduduk).
  - *Penduduk* group: Total Penduduk, Laki-laki / Perempuan
    (`penduduk.gender`), each with a share-of-total description
    ("N% dari total penduduk", guarded against division by zero).
  - *Wilayah* group: Total RT (`rts` count), Total RW / Lingkungan
    (`area_units` count).
  - *Status* group: Penduduk Aktif / Pindah / Meninggal
    (`penduduk.resident_status`).
  - Presentation: Heroicons, Bahasa Indonesia descriptions, per-group color
    families (primary/gray, info/danger, success/warning/gray), Indonesian
    thousands-separator formatting (`number_format`, e.g. "1.234.567"),
    logical ordering by group. Filament v4 has no native stat grouping, so
    grouping is conveyed via ordering + colors only — no custom view.
  - `$isLazy = false` retained (cheap count queries render eagerly).

### 4.2.3 Not done (explicitly out of scope for 4.2)

- No charts (ChartWidget / sparkline trend data), tables, exports, or
  filters on the dashboard.
- No new models, migrations, or database fields — every statistic uses
  existing columns.
- No changes to `app/Filament/Pages/Dashboard.php` or prior-phase code.

### 4.2.4 Files changed (4.2 only)

| File | Change |
| --- | --- |
| `app/Filament/Widgets/SipetaStatsOverview.php` | Modified — 4.1 placeholders extended to eleven statistics cards (grouped, colored, formatted). |
| `tests/Feature/Phase4/DashboardKpiTest.php` | New — labels render + values match controlled database records (2 tests). |
| `docs/PHASE4.md` | Updated — this section; metadata Version 1.0.0 → 1.1.0. |

### 4.2.5 Verification

```text
php artisan test       91 passed (427 assertions), 3 skipped
./vendor/bin/pint --test  PASS (118 files)
```

`npm run build` not applicable — no frontend asset, Tailwind class, or Blade
view was added (widget reuses Filament's shipped `stats-overview-widget`
view; icons are the bundled blade-heroicons set).

### 4.2.6 Commit

`feat(dashboard): Phase 4.2 — enhance KPI cards`
