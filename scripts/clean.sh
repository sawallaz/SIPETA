#!/usr/bin/env bash
#
# scripts/clean.sh — SAFE cleanup helper (Phase 1.5)
#
# Clears framework caches and regenerates the Composer autoloader. This is
# intentionally NON-destructive: it does NOT run `git clean -fdx` and will
# never delete untracked files or uncommitted work.
#
# If you genuinely need a hard git clean, do it manually and intentionally:
#   git clean -fdx     # DANGEROUS: deletes untracked files including .env
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> php artisan optimize:clear"
php artisan optimize:clear

echo "==> composer dump-autoload"
composer dump-autoload

echo "==> npm cache verify"
npm cache verify

echo "==> Clean complete (no files were deleted)."
