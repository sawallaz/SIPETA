| Field | Value |
| --- | --- |
| **Title** | SIPETA Phase 4 — Dashboard |
| **Purpose** | Track Phase 4 (Admin Panel Dashboard) sub-phase progress. |
| **Scope** | 4.1 Dashboard foundation (layout + placeholder KPI cards). 4.2 Enhanced KPI cards (population statistics). 4.3 Distribution charts (per RT, per Lingkungan, per Pekerjaan). Later: recent activity, quick actions, polish. |
| **Version** | 1.2.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-06 |
| **Related Documents** | `.ai/hermes.md`, `.ai/filament.md`, `docs/PHASE3.md`, `docs/REQUIREMENTS.md`, `app/Filament/Pages/Dashboard.php`, `app/Filament/Widgets/SipetaStatsOverview.php`, `app/Filament/Widgets/PendudukPerRTChart.php`, `app/Filament/Widgets/PendudukPerLingkunganChart.php`, `app/Filament/Widgets/PendudukPerPekerjaanChart.php` |

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

---

## Phase 4.3

### 4.3.1 Objective

Add the three distribution charts from the Phase 4 roadmap (`.ai/roadmap.md`
§5 "Charts (per RT, per Lingkungan, per Pekerjaan)") to the dashboard.
Per `docs/REQUIREMENTS.md` §5.5, **charts reflect active residents only**
(`resident_status = ACTIVE`). No tables, exports, filters, new models, or
new migrations.

### 4.3.2 Deliverables

- **`app/Filament/Widgets/PendudukPerRTChart.php`** — bar chart of active
  residents per RT. Every RT is shown, including RTs with zero active
  residents (zero-padded), so the chart mirrors the kelurahan structure
  (19 RTs across 3 area units per `RegionSeeder`). RTs are ordered naturally
  by number ("RT 01" before "RT 10"). One query via
  `Rt::withCount(['penduduks as active_count' => active-only])`.
- **`app/Filament/Widgets/PendudukPerLingkunganChart.php`** — bar chart of
  active residents per Lingkungan / RW. Residents are attributed through
  their RT (`penduduk.rt_id → rts.area_unit_id`) in a single aggregate join
  query; every area unit is shown (zero-padded), ordered by name.
- **`app/Filament/Widgets/PendudukPerPekerjaanChart.php`** — doughnut chart
  of active residents per occupation (`occupations` lookup, 12 rows seeded).
  Only occupations with at least one active resident are shown, sorted by
  count descending with ties broken by name (largest share first).
- **Dashboard page** (`app/Filament/Pages/Dashboard.php`) — `getWidgets()`
  (public) now mounts the three charts after `SipetaStatsOverview`.
- Chart type choice is a presentation decision: bar for RT and Lingkungan
  (ordered, comparable administrative units), doughnut for Pekerjaan (share
  of population). All three use Filament v4's built-in `ChartWidget`
  (Chart.js); no frontend asset, Tailwind class, or Blade view was added.
  Widgets are eager-rendered (`$isLazy = false`, cheap aggregate queries),
  matching the KPI cards, with Bahasa Indonesia empty states.

### 4.3.3 Not done (explicitly out of scope for 4.3)

- No tables, exports, PDF/Excel/CSV, or filters on the dashboard.
- No trend/line charts, no per-gender-per-RT statistics (F-LOW-02 remains
  backlog), no chart filters or date ranges.
- No new models, migrations, or database fields — every chart uses existing
  tables (`penduduk`, `rts`, `area_units`, `occupations`).
- No changes to `SipetaStatsOverview`, Resources, or prior-phase code.

### 4.3.4 Files changed (4.3 only)

| File | Change |
| --- | --- |
| `app/Filament/Widgets/PendudukPerRTChart.php` | New — bar chart, active residents per RT. |
| `app/Filament/Widgets/PendudukPerLingkunganChart.php` | New — bar chart, active residents per Lingkungan / RW. |
| `app/Filament/Widgets/PendudukPerPekerjaanChart.php` | New — doughnut chart, active residents per Pekerjaan. |
| `app/Filament/Pages/Dashboard.php` | Modified — mounts the three chart widgets. |
| `tests/Feature/Phase4/DashboardChartTest.php` | New — headings render + chart data matches controlled records, active-only verified (2 tests). |
| `docs/PHASE4.md` | Updated — this section; metadata Version 1.1.0 → 1.2.0. |
| `docs/CHANGELOG.md` | Updated — Phase 4.3 entry; Version 1.3.0 → 1.4.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-01 status Planned → Implemented, phase corrected to Phase 4. |

### 4.3.5 Verification

```text
php artisan test       93 passed (437 assertions), 3 skipped
./vendor/bin/pint --test  PASS (122 files)
```

`npm run build` not applicable — no frontend asset, Tailwind class, or Blade
view was added (Chart.js ships with Filament; widgets use the shipped
`filament-widgets::chart-widget` view).

### 4.3.6 Commit

`feat(dashboard): Phase 4.3 — distribution charts (per RT, Lingkungan, Pekerjaan)`
