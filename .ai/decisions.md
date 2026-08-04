| Field | Value |
|---|---|
| **Title** | SIPETA Architectural Decisions (ADR) |
| **Purpose** | Record every major architectural decision. Each decision is binding unless explicitly superseded by a new ADR. |
| **Scope** | Tech stack, data model, security, deployment, scope boundaries. |
| **Version** | 1.3.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/database.md`, `.ai/deployment.md`, `.ai/roadmap.md`, `docs/REQUIREMENTS.md`, `docs/BACKLOG.md` |

---

# SIPETA Architectural Decisions (ADR)

This document records every major architectural decision. Decisions are binding unless explicitly superseded by a new ADR.

## ADR-001 — Laravel 12

**Decision.** Use Laravel 12.

**Status.** Accepted.

**Reason.** Mature ecosystem, excellent documentation, stable, large community.

**Consequences.** Composer required for development. PHP 8.3+ required.

---

## ADR-002 — Filament 4

**Decision.** Use Filament 4.

**Status.** Accepted.

**Reason.** Fast CRUD development, built-in tables, forms, filters, and export. Suitable for KKN timeline. Easy maintenance.

**Consequences.** All admin UI flows through Filament.

---

## ADR-003 — Tauri 2

**Decision.** Use Tauri 2 (not Electron).

**Status.** Accepted.

**Reason.** Lightweight. Small installer. Low RAM. Native desktop experience.

**Rejected.** Electron — too large, high memory consumption.

**Consequences.** Rust is required for any Tauri-side code. PHP runtime is bundled as a sidecar. **Integration is deferred until Phase 7 per ADR-025.**

---

## ADR-004 — MySQL 8

**Decision.** Use MySQL 8 with InnoDB.

**Status.** Accepted.

**Reason.** Required for FK constraints, robust indexes, stable for the dataset size (tens of thousands of residents).

**Rejected.** SQLite — single file, no concurrent access from queued jobs; not aligned with KKN's long-term storage needs.

**Consequences.** Dedicated DB user `sipeta_app` with limited privileges.

---

## ADR-005 — Single Admin Login

**Decision.** Single admin user. No role-based access.

**Status.** Accepted.

**Reason.** One primary operator, simpler support, less complexity.

**Future.** Role-based access may be added after KKN (`docs/BACKLOG.md`).

---

## ADR-006 — Separate KK and Penduduk Tables

**Decision.** `kartu_keluarga` and `penduduk` are separate entities.

**Status.** Accepted.

**Reason.** Avoid duplicated household data, normalize to 1NF.

**Consequences.** KK photo is stored once per KK. Residents reference the KK.

---

## ADR-007 — Never Store Age

**Decision.** Never store age. Always store `birth_date` and compute `age`.

**Status.** Accepted.

**Reason.** Age changes automatically every year.

**Consequences.** All reports and the dashboard compute age dynamically.

---

## ADR-008 — Never Delete Valid Historical Records

**Decision.** Use `resident_status` instead of physical deletion.

**Status.** Accepted.

**Values.** `ACTIVE`, `MOVED`, `DECEASED`.

**Reason.** Preserve historical data; protect data integrity.

**Consequences.** Physical deletion is only allowed for invalid records created in error.

---

## ADR-009 — OCR Is an Assistant

**Decision.** OCR is an assistant. The operator must review every field before saving.

**Status.** Accepted.

**Consequences.** OCR never writes directly to the database. Confidence highlighting is required.

---

## ADR-010 — Photo Belongs to KK

**Decision.** One KK photo per KK. Residents reference the same household.

**Status.** Accepted.

**Consequences.** KK photo is stored on `kartu_keluarga.kk_photo_path`, not on `penduduk`.

---

## ADR-011 — One Main Working Page

**Decision.** Data Penduduk is the single workspace.

**Status.** Accepted.

**Pages included.** Search, Filter, Add, OCR, Edit, Export.

**Consequences.** No separate KK / Penduduk / OCR menus.

---

## ADR-012 — Backup Before Convenience

**Decision.** Backup and restore must be implemented before additional features.

**Status.** Accepted.

**Consequences.** Any feature that touches data must be paired with a backup consideration.

---

## ADR-013 — Keep KKN Scope Small

**Decision.** Do NOT implement, unless explicitly requested later:

- Mobile app
- WhatsApp
- API
- Multi-user
- Cloud sync
- LAN sync

**Status.** Accepted.

**Reason.** KKN timeline is short; focus on the core.

**Future.** Items live in `docs/BACKLOG.md`.

---

## ADR-014 — Application/Data Folder Separation

**Decision.** Application files in `Program Files\SIPETA\`. Data files in `%USERPROFILE%\Documents\SIPETA\`.

**Status.** Accepted.

**Reason.** Updates must not destroy user data.

**Consequences.** Backup captures the data folder only. Installer configures both paths.

---

## ADR-015 — Operator Experience Is the Priority

**Decision.** Operator experience is the priority.

**Status.** Accepted.

**Targets.**

- Learn in under 15 minutes.
- Daily tasks in minimal clicks.
- No terminal usage.

**Consequences.** Any feature that increases operator complexity must be justified.

---

## ADR-016 — Service Layer for Domain Logic

**Decision.** All business logic lives in `App\Services\*`. Controllers and Filament Resources are thin.

**Status.** Accepted.

**Reason.** Maintainability, testability, separation of concerns.

**Consequences.** Repositories are only used when queries become complex; justified in code review.

---

## ADR-017 — OCR Pipeline Strategy: Rule-Based

**Decision.** Tesseract OCR with rule-based regex extraction. No mandatory LLM dependency.

**Status.** Accepted.

**Reason.** Deterministic, auditable, offline-capable, predictable performance.

**Future.** LLM-based fallback captured in `docs/BACKLOG.md` (B-DATA-02).

---

## ADR-018 — Installer Technology: Inno Setup

**Decision.** Use Inno Setup for the Windows installer.

**Status.** Accepted.

**Reason.** Stable, well-supported, works with Tauri 2, supports silent MySQL install.

**Consequences.** Installer authored in `installer/sipeta.iss`. Build runs via Wine on Parrot or natively on Windows. **Integration is deferred until Phase 7 per ADR-025.**

---

## ADR-019 — Backups Are ZIPs

**Decision.** Backup is a single ZIP archive containing `mysqldump` output, photos, and settings.

**Status.** Accepted.

**Reason.** Easy to inspect, portable, simple restore.

**Consequences.** ZIP integrity is checked before restore.

---

## ADR-020 — Settings Singleton

**Decision.** `settings` table has one row. Singleton enforced by the Service layer.

**Status.** Accepted.

**Reason.** MySQL has no partial unique index. Application-level enforcement is acceptable.

---

## ADR-021 — AI Execution Environment Is Authoritative

**Decision.** `.ai/hermes.md` §21 is the single source of truth for the AI execution environment — every available Skill, Tool, and MCP server, and the rules for using them.

**Status.** Accepted.

**Reason.** Without a canonical reference, AI agents reimplement functionality that already exists as a Skill or MCP, drift between invocations, and make inconsistent tooling choices. Centralizing the policy in one section eliminates this class of error.

**Consequences.** All AI agents (Hermes, OpenCode, Codex, Claude Code) must read `.ai/hermes.md` §21 before any non-trivial task. New Skills, Tools, or MCP servers must be appended to §21 before use. The policy is versioned with `.ai/hermes.md`.

---

## ADR-022 — MCP Accessed Only Through mcporter

**Decision.** All MCP server invocations must go through `mcporter`. Direct MCP calls are forbidden.

**Status.** Accepted.

**Reason.** Routing MCP through a single client ensures consistent auth, retries, logging, and schema validation. Bypassing mcporter hides errors and breaks the audit trail.

**Consequences.** When a task requires an MCP server — `github`, `filesystem`, `context7`, `playwright`, `sequential-thinking`, `agentrouter` — the agent calls it via `mcporter`, never directly.

---

## ADR-023 — AI Capability Priority

**Decision.** When solving a task, the AI must follow this priority and never ignore a higher-priority source:

1. Existing project documentation (`docs/`, `.ai/`).
2. Context7 MCP (authoritative library docs).
3. Project Skills (problem-shaped, e.g. `plan`, `test-driven-development`).
4. MCP Servers (filesystem, github, playwright, sequential-thinking, agentrouter).
5. Built-in Tools (terminal, file, browser, web, code_execution, vision, image_gen, computer_use, memory, todo, context_engine, session_search, clarify, delegation, cronjob, skills).
6. Manual implementation (only when no suitable Skill or MCP exists).

**Status.** Accepted.

**Reason.** Higher-priority sources eliminate work, reduce error, and keep the codebase consistent. Manual implementation is the most expensive and least consistent option.

**Consequences.** Before writing any code or inventing any workflow, the agent must check whether a Skill or MCP already covers it. Manual implementation is the documented exception, not the default.

---

## ADR-024 — Context7 Before Any External Library

**Decision.** Before using any Laravel, Filament, Tauri, PHP package, Rust crate, or third-party library, the AI must consult Context7 (via mcporter).

**Status.** Accepted.

**Reason.** Library APIs change between versions. Training-data memory is frequently outdated and silently produces wrong code. Context7 is the authoritative source.

**Consequences.** Any task that touches an external library API must begin with a Context7 lookup. Code generated without Context7 is treated as unverified and subject to review.

---

## ADR-025 — Defer Tauri Integration Until Phase 7

**Decision.** Tauri integration is deferred until the web application is stable. Phase 1 (Foundation) does not include Tauri configuration. Phase 7 (Desktop Packaging) is the only phase that introduces `cargo tauri init`, creates `src-tauri/`, and writes Tauri / Inno Setup configuration.

**Status.** Accepted.

**Reason.** Tauri is a wrapper around a working web application. Configuring it before the application exists wastes 5–20 minutes of Rust compilation and creates configuration drift. The web application must be stable and operator-tested before the desktop wrapper is added.

**Permitted before Phase 7.** The Tauri CLI binary may already be installed on the developer machine (it is a CLI tool, not a project file). Running `cargo install tauri-cli` is acceptable.

**Forbidden until Phase 7.** Running `cargo tauri init` in the project directory; creating `src-tauri/`; writing `tauri.conf.json`, `Cargo.toml` for the desktop binary, or Inno Setup scripts; configuring the desktop runtime or WebView; any line of Rust that references the SIPETA project.

**Trigger condition.** Phase 7 starts only after explicit user instruction to begin desktop packaging.

**Consequences.** Phase 1 must succeed without any Tauri file in the repository. Each phase commit must be reviewable on its own without Tauri context. When Phase 7 starts, the developer will run `cargo tauri init` against an already-stable Laravel application.

**Supersession.** This ADR augments ADR-003 (Tauri 2 was chosen) and ADR-018 (Inno Setup was chosen) by freezing their integration timeline. The technology choices stand; the *timing* is bound to Phase 7.

---

## ADR-026 — Phase-Scoped Installation Policy

**Decision.** Only install software required for the current phase. Never install dependencies belonging to future phases. Always follow `.ai/roadmap.md` strictly. If a dependency belongs to a future milestone, ask for confirmation before installing it.

**Status.** Accepted.

**Reason.** Phase 1 (web foundation) does not need Tauri compilation, OCR binary, or desktop runtime. Installing them early wastes time, creates path conflicts, and obscures the actual phase deliverable. Each phase should have a clean, complete, and reviewable commit set.

**Consequences.** When a developer or AI agent reads `.ai/roadmap.md` and notices a dependency is implied by a future phase, they MUST ask for confirmation before installing it. The exception is the Tauri CLI binary itself, which is a developer machine tool and may already be installed (per ADR-025).

**Worked example (Phase 1).** Install: PHP, Composer, MariaDB, Node.js, npm, git. System-level prerequisite exception: Tesseract OCR and `tesseract-ocr-ind` may be installed in Phase 1 per ADR-027, but no OCR application code may be written until the OCR phase. Do NOT install: Tauri desktop runtime, WebView2, Inno Setup, or any Rust toolchain rebuild. If the developer already has these from earlier work, that is acceptable; they are not re-installed as part of Phase 1.

---

## ADR-027 — Tesseract Is a Phase 1 System Prerequisite

**Decision.** The Tesseract OCR binary and the Indonesian language package (`tesseract-ocr-ind`) may be installed as system-level prerequisites during Phase 1. No OCR application code, configuration, workflow, storage, tests, or documentation that describes OCR behavior may be written until the OCR phase.

**Status.** Accepted.

**Reason.** The approved Phase 1 apt script installs `tesseract-ocr` and `tesseract-ocr-ind`. This is a narrow exception to ADR-026 (Phase-Scoped Installation Policy). The binary alone is harmless; embedding it in application logic before the OCR phase would violate the phase boundary.

**Consequences.** Phase 1 must verify Tesseract is installed and report its version, but must not invoke it from application code, create OCR-related directories, or add OCR tests.

---

## ADR-028 — Database Configuration Policy

**Decision.** Do not assume database names, usernames, or passwords. Use `DB_*` environment variables only. Never hardcode credentials in source code, executable documentation examples, commits, or scripts. Never commit `.env`. If the selected database or application user does not exist, create them during Phase 1 only after the configuration values are known. The application database password is generated securely and stored only in the untracked `.env` file. `.env.example` may provide safe non-secret defaults and placeholders, but it must not silently override the approved MySQL requirement. Phase 1 must explicitly use `DB_CONNECTION=mysql`.

**Status.** Accepted.

**Reason.** Hardcoded credentials in documentation or code create security debt and make the project non-portable. Environment variables are the Laravel-standard mechanism and align with the deployment model.

**Consequences.** If `DB_DATABASE` or `DB_USERNAME` are not explicitly approved in the current documentation, the AI must ask one concise question for both values before provisioning. The password is generated and stored only in untracked `.env`.

---

## ADR-029 — Commit Safety Gate

**Decision.** Before every commit, the developer must run a fixed pre-commit gate. Never commit secrets, generated dependencies, or runtime artifacts.

**Status.** Accepted.

**Reason.** A single accidental secret commit to a public repository requires immediate rotation and trust recovery. A single committed `vendor/` or `storage/logs/*` pollutes history and bloats the repository.

**Pre-commit gate.**

1. Run `php artisan test` when the test suite exists and dependencies are installed. If it fails, document the cause before committing.
2. Verify Laravel boots successfully.
3. Verify no secrets or generated dependencies are staged.
4. Review `git diff --check`.
5. Review the staged file list.

**Never commit:**

- `.env`
- `vendor/`
- `node_modules/`
- `storage/logs/*`
- `bootstrap/cache/*`
- credentials, private keys, tokens, dumps, or local database files

**Exception.** Retain Laravel-required `.gitignore` placeholder files, such as `bootstrap/cache/.gitignore` and `storage/logs/.gitignore`. Do not blindly replace the framework-generated ignore file; preserve its required negated `.gitignore` entries.

**Consequences.** The first commit after `git init` must include a verified `.gitignore` that covers the patterns above. Every subsequent commit must pass the same gate.

---

## Process

To add a new ADR:

1. Append a new section with the next number.
2. State decision, status, reason, and consequences.
3. Reference the docs that change as a result.

If a future decision conflicts with a previous one, the new ADR must explicitly supersede the old one.

---

## Conflict Resolution

If a future decision contradicts an existing ADR, this document wins until explicitly updated.

---

## Implementation Notes

- Each ADR is immutable once written. Supersession is by a new ADR, never by editing the old one.
- The latest ADR supersedes related older ones unless explicitly noted otherwise.
- Timeline-only changes (such as ADR-025 deferring Tauri) do not supersede the original technology decision — they constrain the *when*, not the *what*.
