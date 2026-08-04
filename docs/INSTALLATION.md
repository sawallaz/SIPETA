| Field | Value |
|---|---|
| **Title** | SIPETA Developer Installation Guide |
| **Purpose** | Step-by-step instructions to set up a developer environment on Parrot OS for SIPETA. |
| **Scope** | Toolchain, repository checkout, Laravel setup, Filament install, Tauri setup, dev server. |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/coding.md`, `.ai/laravel.md`, `.ai/filament.md`, `.ai/installation.md`, `.ai/deployment.md` |

---

# SIPETA Developer Installation Guide

Instructions for setting up a development environment on Parrot OS. Production install is different — see `.ai/deployment.md`.

## 1. Prerequisites

### 1.1 System

- Parrot OS (updated).
- sudo access.
- Internet connection.

### 1.2 Toolchain

| Tool | Version | Purpose |
|------|---------|---------|
| PHP | 8.3+ | Laravel runtime |
| Composer | 2.x | PHP package manager |
| Node.js | 20+ | Frontend tooling |
| npm | 10+ | Frontend package manager |
| MySQL | 8.0+ | Database |
| Git | 2.x | Version control |
| Rust | 1.74+ | Tauri build |
| Tauri CLI | 2.x | Desktop shell |
| Tesseract | 5.x | OCR engine |
| Inno Setup | 6.x | Windows installer (run via Wine on Parrot) |

## 2. Install Parrot OS Packages

```bash
sudo apt update
sudo apt install -y php php-cli php-mysql php-xml php-mbstring php-curl php-zip php-sqlite3 php-bcmath php-gd php-intl unzip git curl mysql-server tesseract-ocr tesseract-ocr-ind libsqlite3-dev libmysqlclient-dev

# Node.js 20 LTS
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Rust
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
source $HOME/.cargo/env

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

## 3. MySQL Setup

```bash
sudo systemctl enable --now mysql
sudo mysql -u root -p
```

Inside MySQL:

```sql
CREATE DATABASE sipeta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sipeta_app'@'localhost' IDENTIFIED BY 'CHANGE_ME';
GRANT SELECT, INSERT, UPDATE, DELETE ON sipeta.* TO 'sipeta_app'@'localhost';
FLUSH PRIVILEGES;
```

Note the password; you'll use it in `.env`.

## 4. Clone and Install

```bash
git clone https://github.com/<org>/sipeta.git
cd sipeta
composer install
npm install
cp .env.example .env
php artisan key:generate
```

## 5. Configure `.env`

```
APP_NAME=SIPETA
APP_ENV=local
APP_KEY=...
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sipeta
DB_USERNAME=sipeta_app
DB_PASSWORD=CHANGE_ME

TESSERACT_PATH=/usr/bin/tesseract
OCR_LANGUAGE=ind
OCR_CONFIDENCE_THRESHOLD=70
```

## 6. Migrate and Seed

```bash
php artisan migrate --seed
```

This creates the four tables and seeds the admin user.

## 7. Run Development Servers

```bash
# Terminal 1: Laravel dev server
php artisan serve

# Terminal 2: Frontend watcher
npm run dev

# Terminal 3: Tauri dev shell
cargo tauri dev
```

Access:

- Laravel: `http://localhost:8000/admin`
- Tauri: opens a desktop window.

## 8. Default Admin User

- Username: `admin`
- Password: `password` (change immediately after first login).
- Password reset: only via the developer (no self-service in KKN).

## 9. Verify Installation

Run:

```bash
php artisan test
```

Expected: all tests pass.

## 10. Troubleshooting

### 10.1 `pdo_mysql` not found

```bash
sudo apt install php-mysql
sudo systemctl restart php8.3-fpm  # or apache2
```

### 10.2 Tesseract not found

```bash
which tesseract
# Should print /usr/bin/tesseract

tesseract --list-langs
# Should include 'ind'
```

### 10.3 Filament admin does not load

```bash
php artisan optimize:clear
php artisan filament:cache-components
```

### 10.4 Tauri build fails

```bash
cargo install tauri-cli --version "^2.0"
rustup update
```

### 10.5 Permission issues on storage

```bash
chmod -R ug+rwx storage bootstrap/cache
```

## 11. Tooling

- **PHP code style**: `vendor/bin/pint`
- **Static analysis**: `vendor/bin/phpstan analyse`
- **Tests**: `php artisan test`
- **Playwright**: `npx playwright test` (only for UI tests)

## 12. Implementation Notes

- Tesseract `ind.traineddata` should be auto-installed via the apt package `tesseract-ocr-ind`.
- The Tauri app will use a local PHP server (`php artisan serve`) during development.
- For production builds, see `.ai/deployment.md`.

## 13. Future Improvements

- Add a `setup.sh` script that automates steps 1–7.
- Add a Docker Compose for the dev environment.
