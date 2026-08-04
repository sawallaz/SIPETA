| Field | Value |
|---|---|
| **Title** | SIPETA Coding Standards |
| **Purpose** | Single consistent coding style for the SIPETA backend. Authoritative for all AI agents. |
| **Scope** | Laravel, Filament, PHP, and Tauri code conventions. |
| **Version** | 1.1.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/database.md`, `.ai/testing.md`, `.ai/filament.md`, `.ai/laravel.md` |

---

# SIPETA Coding Standards

## 1. Tech Stack

- Laravel 12
- Filament 4
- PHP 8.3+
- MySQL 8
- Tauri 2 (Rust)

## 2. Architecture

Layered:

```
Controller  →  Action / Service  →  Eloquent Model
   (thin)       (logic)             (data)
```

Repositories are used **only when** queries become complex. Do not create repositories for the sake of it.

## 3. Folder Structure

```
app/
├── Actions/
├── Enums/
├── Filament/
│   ├── Resources/
│   ├── Pages/
│   └── Widgets/
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   └── Requests/
├── Models/
├── Policies/
├── Providers/
├── Services/
├── Support/
├── Traits/
└── View/
```

### 3.1 Folder Usage Rules

- `Actions/` — single-purpose classes invoked by Controllers or Filament. Each Action does one thing.
- `Enums/` — domain enums (e.g., `ResidentStatus`).
- `Services/` — business logic only. No HTTP concerns.
- `Repositories/` — only when justified, documented in `.ai/decisions.md`.
- `Support/` — pure helpers (no state, no side effects).
- `Traits/` — small reusable behaviors on Models.
- `Helpers/` — **avoid**; use `Support/` instead.

## 4. Naming

| Object | Convention | Example |
|--------|------------|---------|
| Models | Singular PascalCase | `KartuKeluarga`, `Penduduk`, `Settings`, `BackupLog` |
| Tables | `snake_case` plural where natural | `kartu_keluarga`, `penduduk`, `backup_logs` |
| Services | `<Domain>Service` | `ResidentService`, `KKService`, `OCRService` |
| Actions | `<Verb><Noun>Action` | `CreateResidentAction`, `UpdateResidentStatusAction` |
| Repositories | `<Domain>Repository` | `ResidentRepository` |
| Controllers | `<Resource>Controller` | `PendudukController` |
| Form Requests | `<Verb><Resource>Request` | `StorePendudukRequest`, `UpdateKKRequest` |
| Enums | `<Domain>Status` | `ResidentStatus`, `Gender`, `Religion` |
| Enum cases | UPPER_SNAKE | `ResidentStatus::ACTIVE` |
| Filament Resources | `<Resource>Resource` | `PendudukResource` |
| Filament Pages | `<Verb><Resource>` | `CreatePenduduk`, `EditKK` |

## 5. Controllers

Controllers MUST:

- Receive the request.
- Authorize (via Policy or `authorize()`).
- Call a Service or Action.
- Return a response.

Controllers MUST NOT:

- Validate manually (use Form Request).
- Build large Eloquent queries.
- Contain OCR logic, export logic, or backup logic.
- Use `DB::raw` without a comment.

## 6. Validation

- Always use Form Request classes.
- Never use `$request->validate()` inside Controllers.
- Validation messages must be in Bahasa Indonesia.

## 7. Models

Models should:

- Use relationships.
- Use query scopes.
- Use casts.
- Define accessors for computed fields (e.g., `age`).

Models should NOT:

- Contain business logic.
- Make HTTP calls.
- Send notifications directly (use Events).

## 8. Services

Services contain business logic. Services:

- Accept dependencies via constructor.
- Return DTOs or Models, not arrays.
- Throw `DomainException` for business errors.
- Are unit-testable.

Examples:

- `ResidentService`
- `KKService`
- `DashboardService`
- `OCRService`
- `ExportService`
- `BackupService`
- `SettingsService`

## 9. Actions

One Action = one job. Used for:

- `CreateResidentAction`
- `UpdateResidentStatusAction`
- `ImportKKAction`
- `GenerateBackupAction`
- `RunOCRAction`

Actions are stateless. They can be invoked from Controllers, Filament Resources, or scheduled tasks.

## 10. Repositories

Use only when:

- Queries span multiple tables with complex joins.
- The query would obscure the Service.
- The query is reused across multiple Services.

Each Repository use must be justified in `.ai/decisions.md`.

## 11. Enums

Never use magic strings. Use PHP enums:

```php
enum ResidentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case MOVED = 'MOVED';
    case DECEASED = 'DECEASED';
}
```

Filament selects consume enums directly.

## 12. Filament

- Use Resources for CRUD.
- Keep Pages simple.
- Avoid unnecessary plugins.
- Use Tables and Forms consistently.
- Define `getPages()` to control which pages are exposed.
- Use `Section` for grouping fields.
- Use `Tabs` only when content is genuinely long.

Details: `.ai/filament.md`.

## 13. Database

- Always use Eloquent relationships.
- Never use raw SQL unless necessary.
- Always eager-load relationships when appropriate.
- Use `select()` only when needed.
- Use `firstOrCreate` for singleton settings.

## 14. Logging

- Use `Log::info`, `Log::warning`, `Log::error`.
- Never use `dump()` or `dd()` in committed code.
- Stack traces are logged, not displayed.

## 15. Errors

- Handle exceptions gracefully.
- Display friendly messages in Bahasa Indonesia.
- Never expose stack traces in the UI.
- `try/catch` only when the Service can recover.

## 16. Comments

- Prefer self-documenting code.
- Write comments only when explaining business rules.
- Use PHPDoc on public methods only.

## 17. Formatting

- Follow PSR-12.
- Use typed properties.
- Use return types.
- Prefer constructor dependency injection.
- Use `readonly` for value objects.

## 18. Documentation

After major changes:

- Update `docs/CHANGELOG.md`.
- Update `docs/FEATURES.md`.
- Update `docs/REQUIREMENTS.md` (if scope changes).
- Update `.ai/decisions.md` (if a new ADR is needed).

## 19. Git

Commit messages:

- `feat:` — new feature.
- `fix:` — bug fix.
- `refactor:` — no behavior change.
- `docs:` — docs only.
- `test:` — tests only.
- `chore:` — tooling, deps.

Commit rules:

- Small commits.
- Meaningful commits.
- No `WIP` on the main branch.

## 20. AI Rules

Before creating new classes:

- Check if one already exists.
- Do not duplicate: Service, Repository, Action, Enum.
- Consult `.ai/decisions.md` for established patterns.

## 21. Definition of Good Code

- Readable.
- Small methods.
- Single responsibility.
- Testable.
- Maintainable.

## 22. Implementation Notes

- Strict types declared in `phpunit.xml` and `composer.json`.
- Static analysis: PHPStan at level 5.
- Code style: Laravel Pint (PSR-12 profile).

## 23. Future Improvements

- Move from PHPStan to Larastan.
- Add PHP CS Fixer on pre-commit.
- Migrate Actions to invokable services if the codebase grows.
