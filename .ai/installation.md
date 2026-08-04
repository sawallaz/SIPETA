| Field | Value |
|---|---|
| **Title** | SIPETA Tauri Installation and Build |
| **Purpose** | Tauri-specific build, packaging, and runtime configuration for SIPETA. **Integration is deferred until Phase 7 (Desktop Packaging).** |
| **Scope** | Tauri shell, PHP sidecar, MySQL bundling, Inno Setup, Windows installer. |
| **Version** | 1.1.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/architecture.md`, `.ai/deployment.md`, `.ai/coding.md`, `.ai/roadmap.md`, `.ai/decisions.md` (ADR-025, ADR-026), `docs/INSTALLATION.md` |

---

# SIPETA Tauri Installation and Build

## 0. Status — DEFERRED

**Tauri integration is deferred until Phase 7 (Desktop Packaging) per ADR-025.**

This document is the *target* architecture and build guide that will be followed when Phase 7 begins. It is NOT to be acted upon in Phase 1 (Foundation) or Phases 2–6 (CRUD, Dashboard, OCR, Reports, Backup).

**Permitted in earlier phases.** The Tauri CLI binary (`cargo-tauri`) installed via `cargo install tauri-cli` is a developer machine tool. It may already be installed on the developer machine. It is a *developer tool*, not a *project file*. Do not run it inside the project before Phase 7.

**Forbidden in earlier phases.** Running `cargo tauri init`, `cargo tauri dev`, or `cargo tauri build`; creating `src-tauri/`; writing `tauri.conf.json` or `Cargo.toml` for the desktop binary; writing Inno Setup scripts; configuring desktop runtime or WebView.

**Trigger condition.** Phase 7 starts only after explicit user instruction to begin desktop packaging.

When Phase 7 starts, this document becomes the active build guide.

## 1. Audience

Developers working on the Tauri shell, the PHP sidecar, or the Inno Setup installer — *during Phase 7*.

## 2. Tauri Architecture

Tauri 2 wraps a webview and runs commands. For SIPETA:

- Tauri opens a desktop window.
- Tauri spawns a PHP-sidecar process.
- Tauri loads `http://127.0.0.1:<port>/admin` inside the webview.
- The webview is operated by the operator; no terminal interaction is exposed.

## 3. PHP Sidecar

### 3.1 Why a Sidecar

- The operator must not run `php artisan serve`.
- The Tauri app starts PHP automatically on launch.
- The PHP server runs on `127.0.0.1` only.

### 3.2 Sidecar Configuration

In `src-tauri/tauri.conf.json`:

```json
{
  "bundle": {
    "externalBin": [
      "binaries/sipeta-server"
    ]
  }
}
```

The `sipeta-server` binary is a self-contained PHP runtime that hosts Laravel.

### 3.3 PHP Runtime

- PHP 8.3 Windows binaries (from `php-windows` or custom build).
- All required extensions: `pdo_mysql`, `mbstring`, `gd`, `intl`, `zip`.
- The runtime is included in the installer, not the user's machine.

### 3.4 Startup Sequence

1. Tauri window opens.
2. Tauri spawns the `sipeta-server` sidecar.
3. The sidecar runs `php artisan serve --host=127.0.0.1 --port=8765`.
4. Tauri waits for `http://127.0.0.1:8765/admin` to respond.
5. The webview loads the URL.

## 4. MySQL Bundling

### 4.1 Strategy

- The installer ships the MySQL Installer binary.
- Inno Setup runs the MySQL Installer in silent mode.
- A dedicated `sipeta` database and `sipeta_app` user are created.

### 4.2 Inno Setup Silent Install

```iss
[Run]
Filename: "{tmp}\mysql-installer.exe"; \
  Parameters: "/quiet /action=install /type=server"; \
  StatusMsg: "Menginstal MySQL..."; \
  Flags: waituntilterminated
```

### 4.3 Database Initialization

After MySQL install, Inno Setup runs a bootstrap script:

```bash
mysql -u root --password=ROOT_PASSWORD < bootstrap.sql
```

`bootstrap.sql` creates the database and the limited-privilege user.

## 5. Tesseract Bundling

- Include `tesseract.exe` and `tessdata\ind.traineddata` in the installer.
- The installer places them under `Program Files\SIPETA\resources\tesseract\`.
- The Laravel app reads `TESSERACT_PATH` from `sipeta.ini` in the data folder.

## 6. Folder Structure on Windows

```
Program Files\SIPETA\
├── SIPETA.exe
├── resources\
│   ├── tesseract\
│   │   ├── tesseract.exe
│   │   └── tessdata\
│   │       └── ind.traineddata
├── runtime\php\
│   ├── php.exe
│   └── ...
├── laravel\
│   ├── app\
│   ├── public\
│   ├── artisan
│   └── ...
└── bin\
    └── sipeta-server.exe
```

```
%USERPROFILE%\Documents\SIPETA\
├── kk\
├── backup\
├── logs\
└── config\
    └── sipeta.ini
```

## 7. Inno Setup Script

`installer/sipeta.iss` is the Inno Setup script.

Key sections:

- `[Files]` — copy application files.
- `[Dirs]` — create data folder.
- `[Run]` — install MySQL, run migrations.
- `[Icons]` — create desktop shortcut.
- `[UninstallRun]` — never delete the data folder.

## 8. Build Process

### 8.1 On Parrot OS (Cross-Compilation)

```bash
# Build the Tauri app
cargo tauri build

# Generate the Inno Setup installer (via Wine)
wine ISCC.exe installer/sipeta.iss
```

### 8.2 On Windows

```bash
cargo tauri build
ISCC.exe installer\sipeta.iss
```

## 9. Migrations on First Install

The Inno Setup script invokes:

```bash
"C:\Program Files\SIPETA\runtime\php\php.exe" artisan migrate --force
```

```bash
"C:\Program Files\SIPETA\runtime\php\php.exe" artisan db:seed --class=AdminSeeder
```

This ensures the database is initialized before the operator clicks the shortcut.

## 10. Updating SIPETA

- Installer is run again.
- It overwrites `Program Files\SIPETA\` only.
- Data folder is preserved.
- Migrations run forward (no destructive drops).
- The application version is shown in the `Pengaturan` page.

## 11. Logging

- Tauri logs to `%USERPROFILE%\Documents\SIPETA\logs\tauri.log`.
- Laravel logs to `%USERPROFILE%\Documents\SIPETA\logs\laravel.log`.
- OCR logs to `%USERPROFILE%\Documents\SIPETA\logs\ocr.log`.

Operators never see logs.

## 12. Security

- Tauri config disables remote URL loading.
- The webview is locked to `127.0.0.1`.
- File system access is restricted to the data folder.
- Tesseract binary is read-only.

## 13. Troubleshooting

### 13.1 Port 8765 in use

Tauri sidecar picks another port. Update `tauri.conf.json` if the schema conflicts.

### 13.2 MySQL install fails

Check Inno Setup log. Rollback is manual.

### 13.3 Tesseract missing

Verify `tesseract.exe` is in `resources\tesseract\`.

## 14. Implementation Notes

- `tauri.conf.json` defines the bundle, the window, and the sidecar.
- `Cargo.toml` lists the Tauri plugins (notably `tauri-plugin-shell` and `tauri-plugin-fs`).
- The `sipeta-server` sidecar is built with a custom PHP runtime or `phpdesktop`-style wrapper.

## 15. Pre-Phase-7 Checklist

Before starting Phase 7, confirm:

- Laravel application is stable and operator-tested.
- All migrations run cleanly from scratch.
- Backup and restore work end-to-end.
- Filament admin panel is the only entry point.
- Export (PDF, Excel, CSV) works on filtered data.
- OCR pipeline is verified on real KK photos.

If any of these is broken, Phase 7 should not start. Return to Phase 2–6 first.

## 16. Future Improvements

- Pin WebView2 in the installer.
- Support portable mode (run from USB).
- Add a checksum-based auto-update channel.
