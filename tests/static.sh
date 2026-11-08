#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
for script in scripts/entrypoint scripts/wp scripts/block; do
    sh -n "$script"
done
bash -n tests/static.sh tests/integration.sh
for script in scripts/*.php scripts/migrate templates/block/src/render.php tests/*.php; do
    php -l "$script"
done
php tests/domains.php
ACTIVE_THEME=my-theme docker compose -f compose.example.yaml config --quiet
git diff --check
