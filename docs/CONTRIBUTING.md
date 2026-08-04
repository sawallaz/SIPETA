| Field | Value |
|---|---|
| **Title** | SIPETA Contributing Guide |
| **Purpose** | How developers (human or AI) contribute to SIPETA. Covers branch strategy, commits, AI delegation, reviews. |
| **Scope** | Git workflow, code review, documentation updates, AI delegation conventions. |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/coding.md`, `.ai/architecture.md`, `.ai/decisions.md`, `.ai/roadmap.md`, `docs/INSTALLATION.md` |

---

# SIPETA Contributing Guide

## 1. Audience

This guide is for humans contributing to SIPETA and for AI agents (OpenCode, etc.) generating code.

## 2. Workflow

1. Read the relevant `.ai/` documents first.
2. If using Laravel, Filament, or Tauri APIs, consult Context7.
3. Plan the change in small, reviewable commits.
4. Implement.
5. Test per `.ai/testing.md`.
6. Update documentation.
7. Update `docs/CHANGELOG.md`.
8. Update `docs/FEATURES.md` (status).
9. Hand off for review.

## 3. Branch Strategy

- `main` — always deployable.
- `phase/<n>-<name>` — phase work (e.g., `phase/3-core-crud`).
- `feat/<short-name>` — feature work.
- `fix/<short-name>` — bug fixes.
- `docs/<short-name>` — documentation-only.

Phase branches are merged into `main` at the end of each phase.

## 4. Commit Messages

Format:

```
<type>: <subject>

<body>

<footer>
```

Types:

- `feat` — new feature.
- `fix` — bug fix.
- `refactor` — no behavior change.
- `docs` — documentation only.
- `test` — tests only.
- `chore` — tooling, deps.

Subject: imperative mood, ≤ 50 chars.

Body: explain the why, not the what.

Footer: reference issues, ADRs.

Example:

```
feat: add OCR preprocessing pipeline

Adds grayscale, denoise, deskew, and binarize stages
to the OCR pipeline. Enables confidence-based highlighting.

Refs: ADR-017
```

## 5. AI Delegation Rules

When delegating to OpenCode or another AI agent:

1. Provide the relevant `.ai/` documents in the prompt context.
2. Be explicit about file paths.
3. Specify the deliverable format.
4. Require the AI to update `docs/CHANGELOG.md` and `docs/FEATURES.md`.
5. Always review the AI's output before committing.

## 6. Code Review

Before merging:

- Code follows `.ai/coding.md`.
- Tests are added or updated.
- Documentation is updated.
- No `dump`, `dd`, `console.log` left behind.
- No new files outside the agreed folder structure.
- New ADRs are added if decisions changed.

## 7. Documentation Discipline

Every change:

- Updates `docs/CHANGELOG.md` with one entry.
- Updates `docs/FEATURES.md` status if a feature is implemented.
- Updates `docs/REQUIREMENTS.md` if scope changes.
- Adds an ADR in `.ai/decisions.md` if a new architectural decision is made.

Doc-only changes use the `docs:` commit type.

## 8. Testing Discipline

- All PRs must pass `php artisan test`.
- Critical features must include Playwright UI tests.
- After Playwright runs, delete all artifacts.

## 9. Security Discipline

- Never commit `.env` files.
- Never commit real database dumps.
- Never commit test photos containing real NIK.
- Use anonymized fixtures in `tests/Fixtures/`.

## 10. Roles

- **Project Owner**: signs off on scope changes.
- **Developer**: implements features.
- **AI Agent**: drafts code, supports documentation.
- **Reviewer**: enforces standards.

## 11. Communication

- Default to asynchronous text.
- File an issue in GitHub Issues for every significant change.
- Reference issues in commits.

## 12. Definition of Done

A change is complete when:

- Code is merged.
- Tests pass.
- Documentation is updated.
- `docs/CHANGELOG.md` is updated.
- No temporary artifacts remain.

## 13. Implementation Notes

- Tooling: `php artisan test`, `vendor/bin/pint`, `vendor/bin/phpstan`.
- CI: GitHub Actions runs on every push.
- Every PR must reference at least one issue or ADR.

## 14. Future Improvements

- Add a CODEOWNERS file.
- Enable branch protection rules on `main`.
- Add a release drafter.
