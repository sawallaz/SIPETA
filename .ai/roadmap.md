| Field | Value |
|---|---|
| **Title** | SIPETA Development Roadmap |
| **Purpose** | Schedule features into phases that complete within the KKN period. Tauri is intentionally deferred until the web application is stable. |
| **Scope** | Foundation, database, CRUD, dashboard, OCR, reporting, backup, deployment. Tauri is a separate deferred phase. |
| **Version** | 1.2.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/database.md`, `.ai/ocr.md`, `.ai/deployment.md`, `docs/INSTALLATION.md`, `docs/REQUIREMENTS.md`, `docs/FEATURES.md`, `docs/CHANGELOG.md` |

---

# SIPETA Development Roadmap

## 1. Objective

Deliver a stable desktop application that can be used daily by Kelurahan Tanete.

Priority order:

1. Working software.
2. Stable software.
3. Easy software.
4. Beautiful software.

The application is built in two layers:

1. **Web foundation** (Phases 1–6) — Laravel + Filament + MySQL + OCR pipeline, all running in a normal browser for development.
2. **Desktop packaging** (Phase 7 — deferred) — Tauri 2 wrapper around the running web app.

Each phase is scoped strictly to its own layer. Phase 1 must NOT touch Tauri configuration. Phase 7 only wraps an already-stable web application.

## 2. Phase 1 — Foundation (Day 1–2)

**Goals**

- Initialize Git repository.
- Create Laravel 12 project.
- Configure MySQL.
- Install Filament 4 (no resources, no CRUD).
- Set up project structure.
- Configure admin authentication.
- Finalize baseline documentation.

**Out of scope for Phase 1**

- Tauri configuration.
- `cargo tauri init`.
- `src-tauri/` directory.
- Desktop runtime.
- WebView configuration.

**Deliverable**

- Application opens successfully and shows a login screen in the browser.
- Laravel, Filament, and MySQL work together.
- Git commits logical and pushed to GitHub.
- No Tauri files in the repository.

## 3. Phase 2 — Database (Day 2–3)

**Goals**

- Migrations, models, relationships, seeders, factories.
- Validation rules in Form Requests.
- Tables: `kartu_keluarga`, `penduduk`, `settings`, `backup_logs`.

**Deliverable**

- Database ready for development.

## 4. Phase 3 — CRUD (Day 4–7)

**Goals**

- CRUD Kartu Keluarga.
- CRUD Penduduk.
- Resident status workflow.
- Search.
- Filters.
- KK photo upload and viewer.

**Deliverable**

- Operator can manage data manually.

## 5. Phase 4 — Dashboard (Day 8)

**Goals**

- KPI cards.
- Charts (per RT, per Lingkungan, per Pekerjaan).

**Deliverable**

- Dashboard reflects active residents correctly.

## 6. Phase 5 — OCR (Day 9–11)

**Goals**

- Upload KK.
- OCR pipeline.
- Rule-based parser.
- Review screen.
- Confidence highlighting.
- Duplicate detection.

**Deliverable**

- Operator no longer types most fields manually.

## 7. Phase 6 — Reporting and Backup (Day 12–13)

**Goals**

- PDF export.
- Excel export.
- CSV export.
- ZIP backup.
- Restore.
- Backup log.
- Settings page.

**Deliverable**

- Reports generated from filters.
- Safe recovery process.

## 8. Phase 7 — Desktop Packaging (DEFERRED)

**Status.** Deferred until the web application is stable. Will be executed after Phase 6 is complete and the operator-facing workflows are validated.

**Goals (when reactivated)**

- `cargo tauri init` to scaffold the desktop shell.
- Configure Tauri 2 to wrap the Laravel backend (PHP sidecar) and load the running web app.
- Windows installer via Inno Setup.
- Desktop shortcut.
- End-to-end testing on the operator's PC.

**Permitted before this phase**

- The Tauri CLI binary may already be installed on the developer machine (it is a CLI tool, not a project file). This is acceptable.

**Forbidden until this phase**

- `cargo tauri init` in the project directory.
- `src-tauri/` directory.
- Tauri configuration files (`tauri.conf.json`, `Cargo.toml` for desktop).
- Desktop runtime / WebView configuration.
- Inno Setup scripts.

**Trigger condition.** This phase starts only after explicit user instruction to begin desktop packaging.

**Deliverable (when reactivated)**

- The web application runs as a Windows desktop application via double-click.

## 9. Phase 8 — Deployment

**Goals**

- Install on Kelurahan PC.
- Import production database.
- Configure backup folder.
- Train operator.
- Observe workflow.
- Fix issues.

**Deliverable**

- Operator uses the application without assistance.

## 10. Success Criteria

The project is successful if:

- Operator uses the application without assistance.
- OCR reduces manual typing.
- Reports generated in minutes.
- Backup completed successfully.
- Dashboard reflects active residents correctly.
- (After Phase 7) Application launches by double-click.

## 11. Feature Priority

### Critical

- CRUD
- Search
- Filters
- Status
- OCR
- Backup

### High

- Dashboard
- Export

### Medium

- Settings
- Backup log
- OCR confidence highlighting

### Low

- UI enhancements
- Additional statistics

## 12. Backlog (After KKN)

See `docs/BACKLOG.md` for the full list. Highlights:

- Multi-user
- LAN synchronization
- Cloud backup
- Auto update
- Mobile application
- Public dashboard
- API
- Audit log

## 13. AI Instructions

- Hermes / OpenCode must always complete the current phase before starting the next.
- Never implement backlog items unless explicitly requested.
- Tauri integration is deferred until Phase 7 is explicitly started. Do not run `cargo tauri init`, do not create `src-tauri/`, do not modify Tauri configuration files.
- Always update `docs/CHANGELOG.md` after completing a phase.
- Always update `docs/FEATURES.md` status at the end of each phase.

## 14. Implementation Notes

- Phase boundaries are guidance, not hard rules. If a phase finishes early, start the next.
- Documentation is updated per phase, not at the end.
- The Tauri CLI binary may already be installed on the developer machine. This is acceptable — it is a developer tool, not a project file. What is forbidden is invoking it inside the project before Phase 7.

## 15. Future Improvements

- Add a `release-candidate` gating phase before operator deployment.
- Add a soak-testing phase (1 week production data on dev PC).
- Consider promoting Phase 7 (Desktop) into the main flow once the web application is stable and operator-tested.
