#!/usr/bin/env bash
#
# scripts/verify.sh — Lightweight verification helper (Phase 1.5)
#
# Runs framework-level sanity checks that are fast and safe to run any time:
#   1. composer validate
#   2. php artisan optimize:clear
#   3. php -l on every PHP file this script can find under the repo
#
# Prints PASS/FAIL and exits non-zero on any failure.
set -euo pipefail

cd "$(dirname "$0")/.."

PASS=1

check() {
  local name="$1"; shift
  echo "==> $name"
  if "$@"; then
    echo "    [PASS] $name"
  else
    echo "    [FAIL] $name"
    PASS=0
  fi
}

check "composer validate" composer validate --no-check-publish

# optimize:clear purges the cache table, so it needs a live database.
# Skip (don't fail) when the DB is unreachable (e.g. operator PC between sessions).
if php artisan db:show >/dev/null 2>&1; then
  check "php artisan optimize:clear" php artisan optimize:clear
else
  echo "==> [SKIP] php artisan optimize:clear (database offline)"
fi

echo "==> php -l on all PHP files"
lint_fail=0
while IFS= read -r -d '' f; do
  if ! php -l "$f" >/dev/null 2>&1; then
    echo "    [FAIL] $f"
    php -l "$f" || true
    lint_fail=1
    PASS=0
  fi
done < <(find app config database public resources routes tests \
  -name '*.php' -print0 2>/dev/null || true)

if [ "$lint_fail" -eq 0 ]; then
  echo "    [PASS] php -l (no syntax errors)"
fi

if [ "$PASS" -eq 1 ]; then
  echo "==> VERIFY: PASS"
  exit 0
else
  echo "==> VERIFY: FAIL"
  exit 1
fi
