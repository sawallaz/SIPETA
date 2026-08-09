#!/usr/bin/env bash
#
# scripts/setup.sh — Project setup helper (Phase 1.5)
#
# Installs PHP + JS dependencies, runs migrations, builds the frontend,
# and links the public storage directory.
#
# Safe to re-run: composer setup is idempotent and storage:link is idempotent.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> Installing PHP dependencies"
composer run setup

echo "==> Linking public storage"
php artisan storage:link

echo "==> Setup complete."
