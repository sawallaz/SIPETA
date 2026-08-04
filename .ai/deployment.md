| Field | Value |
|---|---|
| **Title** | SIPETA Deployment Guide |
| **Purpose** | Define how SIPETA is built, installed, updated, backed up, and deployed to the operator's PC. |
| **Scope** | Build pipeline, Windows installer, MySQL configuration, backup, restore, update strategy. |
| **Version** | 1.1.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/database.md`, `.ai/installation.md`, `docs/REQUIREMENTS.md` |

---

# SIPETA Deployment Guide

## 1. Target Environment

### 1.1 Development

- OS: Parrot OS
- PHP 8.3+
- Composer
- Node.js 20+
- MySQL 8
- Tauri 2 / Rust
- Inno Setup 6 (for Windows installer, run via Wine on Parrot)

### 1.2 Production

- OS: Windows 10 / 11
- SIPETA installer (.exe)
- MySQL Server (bundled installer, silent mode)
- Desktop shortcut

## 2. Deployment Philosophy

The operator MUST NEVER:

- Open a terminal.
- Run `artisan` commands.
- Run `composer`.
- Run `npm`.

The operator:

1. Double-clicks the desktop shortcut.
2. Logs in.
3. Works.

## 3. Development Flow

```
Parrot OS
   ↓
Laravel 12 development
   ↓
Filament 4 setup
   ↓
OCR pipeline development
   ↓
Tauri 2 build
   ↓
Inno Setup build
   ↓
Windows installer (.exe)
```

## 4. Production Architecture

```
SIPETA.exe (Tauri)
   ↓
Tauri desktop shell
   ↓
PHP runtime (sidecar) + Laravel 12 + Filament 4
   ↓
MySQL 8 (local)
   ↓
KK Photos + Backups (data folder)
```

## 5. Installation

### 5.1 Steps Performed by the Installer

1. Check for MySQL Server; install if missing (silent mode).
2. Create `sipeta` database.
3. Create `sipeta_app` user with limited privileges.
4. Install SIPETA files to `Program Files\SIPETA\`.
5. Create the data folder `%USERPROFILE%\Documents\SIPETA\`.
6. Run migrations.
7. Create the desktop shortcut.
8. Launch SIPETA.

### 5.2 Steps Performed by the Operator

1. Double-click the installer.
2. Choose the install folder (default: `Program Files\SIPETA`).
3. Wait for installation.
4. Launch from the desktop shortcut.

## 6. Directory Layout

### 6.1 Application Folder

```
Program Files\SIPETA\
├── SIPETA.exe
├── resources\
│   ├── tesseract\
│   └── tessdata\
├── runtime\php\
├── laravel\
└── mysql\
```

### 6.2 Data Folder

```
%USERPROFILE%\Documents\SIPETA\
├── kk\
├── backup\
├── logs\
└── config\
    └── sipeta.ini
```

The data folder is what backups capture. The application folder is what upgrades replace.

## 7. MySQL Configuration

- Production database name: `sipeta`.
- Dedicated user: `sipeta_app`.
- Privileges: `SELECT`, `INSERT`, `UPDATE`, `DELETE` on `sipeta.*` only.
- No `DROP`, no `GRANT`.
- Server configured for `utf8mb4` / `utf8mb4_unicode_ci`.
- Local connection only (no remote root access).

## 8. Backup

See `.ai/architecture.md` §11 and `docs/REQUIREMENTS.md` §2.7.

Backup package:

- SQL dump via `mysqldump`.
- KK photos from `storage/kk/`.
- Settings row.

Output: `backup_YYYY-MM-DD_HHMMSS.zip`.

Stored in: `%USERPROFILE%\Documents\SIPETA\backup\`.

Log: every backup recorded in `backup_logs` table.

## 9. Restore

1. Open Backup menu.
2. Click **RESTORE**.
3. Choose ZIP file.
4. Validate ZIP integrity.
5. Confirm.
6. Restore database, photos, settings.
7. Recommend restart.

## 10. Update Strategy

Updates must:

- Replace application files in `Program Files\SIPETA\`.
- **Never** overwrite data folder.
- **Never** run destructive migrations silently.
- Run forward migrations only.
- Display the new version in Pengaturan.

Update modes:

- **Manual update** — operator downloads a new installer and runs it.
- **Phased migration** — new database columns are nullable first; deprecated columns are removed in a later release.

## 11. Security

- Restrict DB user privileges.
- Validate uploaded files (MIME, size).
- Never expose `.env`.
- Never expose stack traces.
- Tesseract binaries are read-only.

## 12. Logging

Store:

- Application logs (laravel.log, rotated daily).
- Backup logs (DB table).
- OCR errors (ocr.log, rotated daily).

Never expose logs to operators.

## 13. Build Commands

### 13.1 Development

```
cargo tauri dev
```

### 13.2 Production

```
cargo tauri build
```

Produces:

- `target/release/SIPETA.exe`
- `target/release/setup.exe` (Inno Setup output)

## 14. Deployment Checklist

Before delivery:

- Database tested.
- OCR tested.
- Backup tested.
- Restore tested.
- Export tested.
- Dashboard verified.
- Desktop shortcut created.
- Documentation updated.
- `docs/CHANGELOG.md` updated.

## 15. Operator Handover

Explain only:

- Login.
- Search.
- Add Data.
- Upload KK.
- Export.
- Backup.

No technical explanation required.

## 16. Golden Rules

Deployment must be:

- Simple.
- Repeatable.
- Safe.
- Recoverable.

## 17. Implementation Notes

- Installer built with Inno Setup.
- PHP runtime distributed as a sidecar binary.
- MySQL bundled via the official MySQL Installer with `/quiet` flag.
- Inno Setup reads installer config from `installer/sipeta.iss`.

## 18. Future Improvements

Captured in `docs/BACKLOG.md`:

- Automatic in-app update.
- Delta updates (only changed files).
- Cloud backup.
- Offline update via LAN.
