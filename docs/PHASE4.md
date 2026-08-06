| Field | Value |
| --- | --- |
| **Title** | SIPETA Phase 4 — Dashboard |
| **Purpose** | Track Phase 4 (Admin Panel Dashboard) sub-phase progress. |
| **Scope** | 4.1 Dashboard foundation (layout + placeholder KPI cards). 4.2 Enhanced KPI cards (population statistics). 4.3 Distribution charts (per RT, per Lingkungan, per Pekerjaan). 4.4 Recent activity (5 newest KK + Penduduk). 4.5 Quick actions (Tambah / Data KK & Penduduk). 4.6 Dashboard polish (layout, ordering, spacing, readability, colors). |
| **Version** | 1.5.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-06 |
| **Related Documents** | `.ai/hermes.md`, `.ai/filament.md`, `docs/PHASE3.md`, `docs/REQUIREMENTS.md`, `app/Filament/Pages/Dashboard.php`, `app/Filament/Widgets/SipetaStatsOverview.php`, `app/Filament/Widgets/PendudukPerRTChart.php`, `app/Filament/Widgets/PendudukPerLingkunganChart.php`, `app/Filament/Widgets/PendudukPerPekerjaanChart.php`, `app/Filament/Widgets/RecentActivityWidget.php`, `app/Filament/Widgets/QuickActionsWidget.php` |

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

---

## Phase 4.4

### 4.4.1 Objective

Add a Recent Activity section to the dashboard showing the latest activity
from existing data only: the 5 newest Kartu Keluarga and the 5 newest
Penduduk. No new tables, migrations, seeders, observers, or audit log —
`kartu_keluarga` and `penduduk` already carry `created_at`.

### 4.4.2 Deliverables

- **`app/Filament/Widgets/RecentActivityWidget.php`** — extends
  `Filament\Widgets\Widget` (eager-rendered like the other dashboard
  widgets). Data is read-only: the 5 newest KK (`KartuKeluarga::latest()`,
  limit 5) and the 5 newest Penduduk (`Penduduk::latest()`, limit 5) merged
  into a single chronological list (newest first). Each entry carries:
  - `icon` — `heroicon-o-home-modern` (KK) / `heroicon-o-user` (Penduduk);
  - `title` — "KK {kk_number}" / full name;
  - `subtitle` — address / "NIK {nik}";
  - `created_at` — rendered human-readable in Bahasa Indonesia
    (`->locale('id')->diffForHumans()`, e.g. "2 jam yang lalu");
  - `url` — the record's edit page via existing resource routes
    (`KartuKeluargaResource::getUrl('edit', ...)` /
    `PendudukResource::getUrl('edit', ...)`).
- **`resources/views/filament/widgets/recent-activity-widget.blade.php`** —
  new Blade view. Wraps Filament's `x-filament::section`; when there is no
  data at all it renders Filament's `x-filament::empty-state` ("Belum ada
  aktivitas"). Rows are `<a>` links with icon, title, subtitle, and a
  `<time>` element. The panel does not compile arbitrary Tailwind utilities
  (no custom Vite theme is registered — verified against the compiled
  `public/css/filament/filament/app.css`), so the list is styled by a small
  scoped `<style>` block (`fi-wi-recent-activity-*` classes, light + dark
  variants) instead of utility classes.
- **Dashboard page** (`app/Filament/Pages/Dashboard.php`) — the widget is
  appended LAST in `getWidgets()` (below KPI cards and charts); no previous
  widget was reordered or modified.

### 4.4.3 Not done (explicitly out of scope for 4.4)

- No audit log implementation, no observers, no activity table, no
  migrations, no seeders.
- No changes to `SipetaStatsOverview`, the chart widgets, Resources, or
  prior-phase code (only the required mount line in `Dashboard.php`).
- No edit/create actions from the widget, no pagination, no filters.

### 4.4.4 Files changed (4.4 only)

| File | Change |
| --- | --- |
| `app/Filament/Widgets/RecentActivityWidget.php` | New — recent activity widget (5 newest KK + 5 newest Penduduk, merged newest-first). |
| `resources/views/filament/widgets/recent-activity-widget.blade.php` | New — widget Blade view with scoped styling and Filament empty state. |
| `app/Filament/Pages/Dashboard.php` | Modified — `RecentActivityWidget` appended to `getWidgets()` (no reordering). |
| `tests/Feature/Phase4/RecentActivityWidgetTest.php` | New — renders, empty state, 5 newest KK, 5 newest Penduduk (4 tests). |
| `docs/PHASE4.md` | Updated — this section; metadata Version 1.2.0 → 1.3.0. |
| `docs/CHANGELOG.md` | Updated — Phase 4.4 entry; Version 1.4.0 → 1.5.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-09 (recent activity) added, status Implemented. |

### 4.4.5 Verification

```text
php artisan test       97 passed (458 assertions), 3 skipped
./vendor/bin/pint --test  PASS (124 files)
```

`npm run build` not applicable — no frontend build asset changed (no
Tailwind classes in the new view, no `resources/css` / `resources/js` /
`vite.config` edits; the panel does not load the app Vite bundle).

### 4.4.6 Commit

`feat(dashboard): Phase 4.4 — recent activity widget`

---

## Phase 4.5

### 4.5.1 Objective

Add a Quick Actions section to the dashboard with shortcuts to the
existing Kartu Keluarga / Penduduk resource routes. Exactly one widget
(`app/Filament/Widgets/QuickActionsWidget.php`) plus its Blade view; no
new resources, pages, migrations, models, controllers, or Livewire
components.

### 4.5.2 Deliverables

- **`app/Filament/Widgets/QuickActionsWidget.php`** — extends
  `Filament\Widgets\Widget` (eager-rendered like the other dashboard
  widgets, `$isLazy = false`). Exposes four actions via `getViewData()`,
  each a statically defined link generated from the existing resource
  routes (no queries at all):
  - `Tambah Kartu Keluarga` — `heroicon-o-plus-circle` →
    `KartuKeluargaResource::getUrl('create')`
    (`filament.admin.resources.kartu-keluargas.create`);
  - `Tambah Penduduk` — `heroicon-o-user-plus` →
    `PendudukResource::getUrl('create')`
    (`filament.admin.resources.penduduks.create`);
  - `Data Kartu Keluarga` — `heroicon-o-rectangle-stack` →
    `KartuKeluargaResource::getUrl('index')`
    (`filament.admin.resources.kartu-keluargas.index`);
  - `Data Penduduk` — `heroicon-o-users` →
    `PendudukResource::getUrl('index')`
    (`filament.admin.resources.penduduks.index`).
  Each action carries `label`, `description`, `icon`, and `url`.
- **`resources/views/filament/widgets/quick-actions-widget.blade.php`** —
  new Blade view. Wraps Filament's `x-filament::section` (heading "Aksi
  Cepat"); the four actions render as link cards in a responsive CSS grid
  (`repeat(auto-fit, minmax(11rem, 1fr))`). As in Phase 4.4, the panel does
  not compile arbitrary Tailwind utilities (no custom Vite theme), so the
  cards are styled by a small scoped `<style>` block
  (`fi-wi-quick-actions-*` classes, light + dark variants).
- **Dashboard page** (`app/Filament/Pages/Dashboard.php`) — the widget is
  mounted AFTER `RecentActivityWidget` in `getWidgets()`; no previous
  widget was reordered or modified.

### 4.5.3 Not done (explicitly out of scope for 4.5)

- No new resources, pages, migrations, models, controllers, or Livewire
  components — every shortcut reuses the existing resource routes.
- No custom action buttons, forms, modals, or permission logic — the
  widget is a static link grid only.
- No changes to `SipetaStatsOverview`, the chart widgets,
  `RecentActivityWidget`, Resources, or prior-phase code (only the mount
  line and docblock in `Dashboard.php`).

### 4.5.4 Files changed (4.5 only)

| File | Change |
| --- | --- |
| `app/Filament/Widgets/QuickActionsWidget.php` | New — quick actions widget (4 static links to existing resource routes). |
| `resources/views/filament/widgets/quick-actions-widget.blade.php` | New — widget Blade view with scoped styling and responsive grid. |
| `app/Filament/Pages/Dashboard.php` | Modified — `QuickActionsWidget` appended after `RecentActivityWidget` (no reordering). |
| `tests/Feature/Phase4/QuickActionsWidgetTest.php` | New — renders, four actions exposed, all four visible, every action points to an existing Filament route (4 tests). |
| `docs/PHASE4.md` | Updated — this section; metadata Version 1.3.0 → 1.4.0. |
| `docs/CHANGELOG.md` | Updated — Phase 4.5 entry; Version 1.5.0 → 1.6.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-10 (dashboard quick actions) added, status Implemented. |

### 4.5.5 Verification

```text
php artisan test       101 passed (481 assertions), 3 skipped
./vendor/bin/pint --test  PASS (126 files)
```

`npm run build` not applicable — no frontend build asset changed (no
Tailwind classes in the new view, no `resources/css` / `resources/js` /
`vite.config` edits; the panel does not load the app Vite bundle).

### 4.5.6 Commit

`feat(dashboard): Phase 4.5 — quick actions widget`

---

## Phase 4.6

### 4.6.1 Objective

Polish the dashboard presentation and usability only. No new business
features — no new widgets, charts, resources, migrations, models,
controllers, or Livewire components. Every change is presentational or
layout-related; all existing amounts, queries, and routes are unchanged.

### 4.6.2 Deliverables

- **Widget ordering** (`app/Filament/Pages/Dashboard.php`) — the dashboard
  is now ordered operator-first: `QuickActionsWidget` on top (the most
  frequent workflows — Tambah / Data KK & Penduduk), then
  `SipetaStatsOverview` (KPI cards), then the three distribution charts,
  and `RecentActivityWidget` last (a reference feed). Previously Quick
  Actions was mounted last.
- **Responsive, full-width layout** — every dashboard widget now declares
  `protected int|string|array $columnSpan = 'full'`. Filament's dashboard
  grid defaults to two columns with each widget at `columnSpan = 1`, so
  previously all six widgets rendered cramped at half width. Full width
  gives the 11 KPI cards, the wide 19-RT bar charts, the action-card grid,
  and the activity list room to breathe and wraps gracefully on narrow
  screens. Applied to `SipetaStatsOverview`, the three charts,
  `RecentActivityWidget`, and `QuickActionsWidget`.
- **Chart descriptions** — each chart now carries a Bahasa Indonesia
  `description` clarifying its scope (active residents per
  `docs/REQUIREMENTS.md` §5.5):
  - `Penduduk per RT` → "Jumlah penduduk aktif di setiap RT";
  - `Penduduk per Lingkungan` → "Jumlah penduduk aktif di setiap RW / lingkungan";
  - `Penduduk per Pekerjaan` → "Sebaran penduduk aktif menurut pekerjaan".
- **Consistent chart colors** — the two bar charts (RT, Lingkungan) now
  render in the single brand color (`#f59e0b`, matching the panel's
  `Color::Amber` primary) instead of Chart.js's default palette. The
  Pekerjaan doughnut gets an explicit categorical 12-color palette
  (Tailwind 500-scale, anchored on the brand amber) covering the 12 seeded
  occupations, with a white slice border for clean separation.
- **Consistent KPI status colors** — `Penduduk Meninggal` changed from
  `gray` to `danger`, completing the status color family
  (Aktif `success`, Pindah `warning`, Meninggal `danger`).
- **Unchanged by design** — loading states are left eager
  (`$isLazy = false` on every widget already; no regression to lazy
  hydration), empty states already exist (charts + recent activity), number
  formatting was already Indonesian (`1.234.567`), and headings /
  descriptions already existed on the KPI cards and the two list widgets.
  No duplicated visual elements were found; the dashboard page title is not
  repeated by any widget.

### 4.6.3 Not done (explicitly out of scope for 4.6)

- No new widgets, charts, resources, migrations, models, controllers, or
  Livewire components.
- No business-feature change: KPI values, chart data, quick-action
  destinations, and recent-activity queries are byte-for-byte unchanged.
- KPI count/selection not reduced (the "lean dashboard" idea belongs to a
  future phase, not polish — removing cards would drop existing data).
- No custom dashboard page view (`filament.pages.dashboard`) — layout is
  improved purely via widget `columnSpan` and ordering, staying on the
  framework's default responsive grid.
- Filament's default grid gap (1rem) is retained as the widget spacing.

### 4.6.4 Files changed (4.6 only)

| File | Change |
| --- | --- |
| `app/Filament/Pages/Dashboard.php` | Modified — widget order changed to operator-first (Quick Actions first, Recent Activity last); docblock. |
| `app/Filament/Widgets/SipetaStatsOverview.php` | Modified — `columnSpan = 'full'`; `Penduduk Meninggal` color → `danger`. |
| `app/Filament/Widgets/PendudukPerRTChart.php` | Modified — `columnSpan = 'full'`, description added, dataset brand color `#f59e0b`. |
| `app/Filament/Widgets/PendudukPerLingkunganChart.php` | Modified — `columnSpan = 'full'`, description added, dataset brand color `#f59e0b`. |
| `app/Filament/Widgets/PendudukPerPekerjaanChart.php` | Modified — `columnSpan = 'full'`, description added, 12-color doughnut palette. |
| `app/Filament/Widgets/RecentActivityWidget.php` | Modified — `columnSpan = 'full'`. |
| `app/Filament/Widgets/QuickActionsWidget.php` | Modified — `columnSpan = 'full'`. |
| `tests/Feature/Phase4/DashboardLayoutTest.php` | New — locks in widget order + full-width spans + page renders (3 tests). |
| `docs/PHASE4.md` | Updated — this section; metadata Version 1.4.0 → 1.5.0. |
| `docs/CHANGELOG.md` | Updated — Phase 4.6 entry; Version 1.6.0 → 1.7.0. |
| `docs/FEATURES.md` | Updated — F-HIGH-11 (dashboard polish) added, status Implemented. |

### 4.6.5 Verification

```text
php artisan test       104 passed (491 assertions), 3 skipped
./vendor/bin/pint --test  PASS (127 files)
```

`npm run build` not applicable — no frontend build asset changed (no
`resources/css` / `resources/js` / `vite.config` edits and no widget view
was modified; the polish is pure PHP: ordering, `columnSpan`, chart
descriptions, and chart dataset colors, which ship via the framed
`chart-widget` view and Chart.js).

### 4.6.6 Commit

`feat(dashboard): Phase 4.6 — dashboard polish`
