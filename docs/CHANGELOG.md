| Field | Value |
|---|---|
| **Title** | SIPETA Changelog |
| **Purpose** | Record every meaningful change to the project, following the Keep a Changelog format. |
| **Scope** | All phases of SIPETA development, including documentation, architecture, and code. |
| **Version** | 1.3.0 |
| **Status** | Active |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `docs/REQUIREMENTS.md`, `docs/FEATURES.md`, `.ai/roadmap.md`, `.ai/decisions.md`, `.ai/hermes.md` |

---

# SIPETA Changelog

All notable changes to SIPETA are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Documentation
- Initial documentation scaffolding created.
- `docs/REQUIREMENTS.md` defined.
- `docs/FEATURES.md` defined.
- `docs/USER_GUIDE.md` defined.
- `docs/BACKLOG.md` defined.
- `.ai/ocr.md` defined.
- Metadata block (title, purpose, scope, version, status, last updated, related) standardized across all `.ai/` documents.

### Architecture
- Tauri + PHP embedded runtime strategy decided.
- MySQL bundled installer (silent mode) strategy decided.
- Application and data folders separated to support upgrade safety.

## [1.3.0] - 2026-08-03

### Policy
- **Identity clarified.** `.ai/hermes.md` states that "Hermes" is the project AI constitution/policy name, while the active execution model is the **SIPETA development agent operating through the default Mixture of Agents (MoA)**.
- **Database Configuration Policy (ADR-028).** Do not assume database names, usernames, or passwords. Use `DB_*` environment variables only. Never hardcode credentials. `.env` is never committed. If the database or application user does not exist, create them during Phase 1 only after the configuration values are known.
- **Commit Safety Gate (ADR-029).** Before every commit: run tests when available, verify Laravel boots, verify no secrets are staged, review `git diff --check`, review the staged file list. Never commit `.env`, `vendor/`, `node_modules/`, `storage/logs/*`, `bootstrap/cache/*`, credentials, private keys, tokens, dumps, or local database files. Retain Laravel-required `.gitignore` placeholder files.
- **Tesseract Phase 1 exception (ADR-027).** `tesseract-ocr` and `tesseract-ocr-ind` may be installed as system-level prerequisites during Phase 1, but no OCR application code, configuration, workflow, storage, tests, or behavior documentation may be written until the OCR phase.

### Architecture
- ADR-025 — Tauri integration deferred until Phase 7 (Desktop Packaging). Phase 1 does not include Tauri configuration. Tauri CLI binary may already be installed on the developer machine, but `cargo tauri init`, `src-tauri/`, `tauri.conf.json`, and Inno Setup scripts are forbidden until Phase 7 is explicitly started.
- ADR-026 — Phase-Scoped Installation Policy. Only install software required for the current phase. Future-phase dependencies require confirmation before installation.

### Changed
- `.ai/roadmap.md` — versioned to 1.2.0. Phase 1 trimmed (no Tauri config). Phase 7 (Desktop Packaging) explicitly marked as deferred; Tauri is configured only after the web application is stable.
- `.ai/decisions.md` — versioned to 1.3.0; appended ADRs 025–029. ADR-003 and ADR-018 augmented with "integration deferred" notes.
- `.ai/hermes.md` — versioned to 1.4.0. Identity note added. §21 renamed to §22 and extended with §21 Database Configuration Policy and §22.15 Commit Safety Gate. Tauri references in §4 and §16 annotated with deferral note. Golden Rules expanded.
- `.ai/installation.md` — versioned to 1.1.0. Added §0 Status — DEFERRED banner and §15 Pre-Phase-7 Checklist.
- `.ai/architecture.md` — versioned to 1.2.0. Added §0 Two-Layer Architecture and Phase 7 notes.

### Notes
Phase 1 work in progress. Tauri CLI binary is installed on the developer machine at `~/.cargo/bin/cargo-tauri` (acceptable per ADR-025 — it is a developer tool, not a project file). No Tauri project files exist in the repository.

## [1.2.0] - 2026-08-03

### Documentation
- **AI Execution Environment** added to `.ai/hermes.md` §21 as the single source of truth for all available Skills, Tools, and MCP servers.
- Listed 20 Hermes Skills across 8 categories (Autonomous AI, Planning, Debugging, Documentation, OCR, Architecture, GitHub, Research).
- Listed 16 built-in tools (terminal, file, browser, web, code_execution, vision, image_gen, computer_use, memory, todo, context_engine, session_search, clarify, delegation, cronjob, skills).
- Listed 6 MCP servers (github, filesystem, context7, playwright, sequential-thinking, agentrouter).
- Defined AI Capability Priority: project docs → Context7 → Skills → MCP → built-in tools → manual implementation.
- Defined Skill Selection Policy: analyze → check existing Skills → check MCP → use highest-level → manual only if no fit.
- Defined per-MCP usage rules (Context7, Filesystem, GitHub, Playwright, Sequential Thinking, AgentRouter).
- Documentation rule: new Skills/Tools/MCPs must be appended to `.ai/hermes.md` §21 before use.

### Architecture
- ADR-021 — `.ai/hermes.md` §21 is the authoritative AI execution environment reference.
- ADR-022 — All MCP calls route through `mcporter`; no direct MCP access.
- ADR-023 — AI Capability Priority formalised.
- ADR-024 — Context7 consulted before using any external library (Laravel, Filament, Tauri, PHP packages, Rust crates).

### Changed
- `.ai/hermes.md` — replaced legacy AI Workflow / Context7 / Playwright Policy sections with consolidated §21 AI Execution Environment. Versioned to 1.2.0.
- `.ai/decisions.md` — versioned to 1.2.0; appended ADRs 021–024.

## [1.1.0] - 2026-08-03

### Documentation
- `docs/REQUIREMENTS.md` defined.
- `docs/FEATURES.md` defined.
- `docs/USER_GUIDE.md` defined.
- `docs/BACKLOG.md` defined.
- `.ai/ocr.md` defined.
- Metadata block standardized across all `.ai/` documents.

### Architecture
- Tauri + PHP embedded runtime strategy decided.
- MySQL bundled installer (silent mode) strategy decided.
- Application and data folders separated to support upgrade safety.

## [1.0.0] - 2026-08-03

### Added
- Project bootstrapped.
- Documentation baseline created under `.ai/` and `docs/`.
- Hermes constitution (`hermes.md`) established.
- Architecture baseline (`architecture.md`) established.
- Database baseline (`database.md`) established.
- Workflow baseline (`workflow.md`) established.
- UI/UX baseline (`ui-ux.md`) established.
- Coding standards (`coding.md`) established.
- Testing standards (`testing.md`) established.
- Deployment guide (`deployment.md`) established.
- Roadmap (`roadmap.md`) established.
- Architectural Decisions (`decisions.md`) — 20 ADRs recorded.
- Business rules (`project-rules.md`) established.

### Notes
This is a documentation-only release. The first code release will be tagged `1.4.0` once Phase 1 (Foundation) is complete.

---

## Types of Changes

This changelog uses the following categories:

- **Added** — new features.
- **Changed** — changes in existing functionality.
- **Deprecated** — soon-to-be removed features.
- **Removed** — now-removed features.
- **Fixed** — bug fixes.
- **Security** — vulnerability fixes.
- **Documentation** — documentation-only changes.
- **Architecture** — non-code architectural changes.
- **Policy** — binding process or governance changes.

## Versioning Policy

- **MAJOR** version — incompatible schema changes, breaking data migrations.
- **MINOR** version — new features, schema additions that are backward-compatible.
- **PATCH** version — bug fixes, non-functional changes.

## Operational Notes

- Every phase completion adds an entry.
- Every release is tagged in Git.
- The current version is reflected in the application's `Settings` page.
