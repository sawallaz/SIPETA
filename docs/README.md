| Field | Value |
|---|---|
| **Title** | SIPETA — Project Overview |
| **Purpose** | Public entry point for the SIPETA project. Provides a brief overview, audience map, and reading order. |
| **Scope** | Project-wide introduction. |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | All files in `.ai/` and `docs/` |

---

# SIPETA

Sistem Informasi Pendataan Penduduk Kelurahan Tanete.

A desktop application for digitizing population records of Kelurahan Tanete based on Kartu Keluarga (KK). Built for one operator who needs a simple, fast, reliable way to record residents, perform OCR on KK photos, and produce reports.

## Why SIPETA

- Eliminates paper-based or spreadsheet-based record keeping.
- Reduces manual typing through OCR.
- Provides fast search and filtering.
- Produces reports in PDF, Excel, and CSV.
- Backs up and restores the entire dataset with a single ZIP.

## Quick Facts

- **Stack**: Laravel 12, Filament 4, MySQL 8, Tesseract OCR, Tauri 2.
- **Platform**: Windows 10 / 11 desktop.
- **Development**: Parrot OS.
- **Operator**: One admin user.
- **Language**: Bahasa Indonesia (UI and labels).

## Reading Order

If you are new to the project, read in this order:

1. `docs/PRODUCT_SPECIFICATION.md` — high-level product.
2. `docs/REQUIREMENTS.md` — what SIPETA must do.
3. `docs/FEATURES.md` — what is in scope.
4. `docs/USER_GUIDE.md` — how the operator uses it.
5. `docs/PRODUCT_DECISIONS.md` — product owner decisions, UI/UX & workflow contract for post-Phase-6 polish.
6. `docs/INSTALLATION.md` — how to install the development environment.
7. `.ai/hermes.md` — AI agent constitution.
8. `.ai/architecture.md` — runtime architecture.
9. `.ai/database.md` — schema.
10. `.ai/ocr.md` — OCR pipeline.
11. `.ai/coding.md` — code style.
12. `.ai/testing.md` — testing strategy.
13. `.ai/deployment.md` — installer and deployment.
14. `.ai/roadmap.md` — schedule.
15. `.ai/decisions.md` — ADRs.
16. `.ai/project-rules.md` — business rules.
17. `.ai/workflow.md` — operator workflows.
18. `.ai/ui-ux.md` — UI/UX guidelines.
19. `docs/CHANGELOG.md` — what's changed.
20. `docs/BACKLOG.md` — what's out of scope (for now).

## Audience Map

| Audience | Start Here |
|----------|-----------|
| Project owner (Kelurahan) | `docs/PRODUCT_SPECIFICATION.md`, `docs/USER_GUIDE.md` |
| Operator | `docs/USER_GUIDE.md` |
| Developer (Laravel / Filament) | `docs/INSTALLATION.md`, `.ai/coding.md`, `.ai/laravel.md`, `.ai/filament.md` |
| Developer (Tauri / Rust) | `.ai/installation.md`, `.ai/deployment.md` |
| AI agent (Hermes / OpenCode) | `.ai/hermes.md` first, then everything in `.ai/` |
| Future maintainer | `docs/README.md`, `docs/CHANGELOG.md`, `.ai/decisions.md` |

## Repository Structure

```
SIPETA/
├── .ai/                 # AI-facing documentation
│   ├── hermes.md
│   ├── architecture.md
│   ├── database.md
│   ├── workflow.md
│   ├── ui-ux.md
│   ├── coding.md
│   ├── testing.md
│   ├── deployment.md
│   ├── roadmap.md
│   ├── decisions.md
│   ├── project-rules.md
│   ├── ocr.md
│   ├── installation.md
│   ├── laravel.md
│   └── filament.md
├── docs/                # Human-facing documentation
│   ├── README.md
│   ├── REQUIREMENTS.md
│   ├── FEATURES.md
│   ├── USER_GUIDE.md
│   ├── PRODUCT_DECISIONS.md
│   ├── CHANGELOG.md
│   ├── BACKLOG.md
│   ├── INSTALLATION.md
│   ├── PRODUCT_SPECIFICATION.md
│   └── CONTRIBUTING.md
└── ...                  # source code (added in Phase 1+)
```

## Status

Currently in Phase 0 (Documentation). Code implementation begins in Phase 1 (Foundation) per `.ai/roadmap.md`.

## License

Internal KKN project. Not yet licensed for public distribution.

## Contact

Kelurahan Tanete — project owner.
KKN developer — implementation.
