| Field | Value |
| --- | --- |
| **Title** | SIPETA Phase 4 — Dashboard |
| **Purpose** | Track Phase 4 (Admin Panel Dashboard) sub-phase progress. |
| **Scope** | 4.1 Dashboard foundation (layout + placeholder KPI cards). Later: statistics, charts, filters, exports, polish. |
| **Version** | 1.0.0 |
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
