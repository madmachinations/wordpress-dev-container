#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
image="${1:-wordpress-dev:test}"
name="wp-dev-test-$$"
directory="$(mktemp -d)"
cleanup() {
    docker logs "$name-web" 2>/dev/null || true
    docker rm -f "$name-web" "$name-db" >/dev/null 2>&1 || true
    docker network rm "$name" >/dev/null 2>&1 || true
    # Files can belong to container root on Linux.
    docker run --rm -v "$directory:/fixture" --entrypoint sh "$image" -c 'rm -rf /fixture/* /fixture/.[!.]* /fixture/..?*' || true
    rm -rf "$directory"
}
trap cleanup EXIT
mkdir -p "$directory/site/wp-content/themes/my-theme"
printf '<?php // Existing theme functions.\n' > "$directory/site/wp-content/themes/my-theme/functions.php"
cp tests/wp-config.php "$directory/site/wp-config.php"
cp tests/seed.php tests/assert-migration.php "$directory/site/"
printf '<?php echo "php-" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;\n' > "$directory/site/runtime-check.php"
printf '<?php echo !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off" ? "https" : "http";\n' > "$directory/site/runtime-proxy.php"
printf '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>\n' > "$directory/site/runtime-check.svg"
printf '<?php include __DIR__ . "/runtime-check.svg";\n' > "$directory/site/runtime-svg.php"
cat > "$directory/environment" <<'ENV'
DB_HOST=db
DB_PORT=3306
DB_USER=wordpress
DB_PASS=integration-password
DB_NAME=wordpress
PRODUCTION_DOMAIN=https://www.example.com
STAGING_DOMAIN=https://staging.example.com
LOCAL_DOMAIN=http://wordpress.test:8080
ACTIVE_THEME=my-theme
ENV
docker network create "$name" >/dev/null
docker run -d --name "$name-db" --network "$name" --network-alias db \
    -e MARIADB_DATABASE=wordpress -e MARIADB_USER=wordpress \
    -e MARIADB_PASSWORD=integration-password -e MARIADB_ROOT_PASSWORD=integration-root \
    mariadb:11.4 >/dev/null
run() {
    docker run --rm --network "$name" --env-file "$directory/environment" \
        -v "$directory/site:/app" "$image" "$@"
}
run wp core download --version=6.8.3 --force
start_web() {
    docker run -d --name "$name-web" --network "$name" --env-file "$directory/environment" \
        -v "$directory/site:/app" "$image" >/dev/null
    for attempt in $(seq 1 90); do
        if docker exec "$name-web" curl -fsS -o /dev/null http://localhost/wp-includes/css/dashicons.min.css 2>/dev/null; then
            test "$(docker exec "$name-web" curl -fsS http://localhost/runtime-check.php)" = php-8.3
            test "$(docker exec "$name-web" curl -fsS -H 'X-Forwarded-Proto: https' http://localhost/runtime-proxy.php)" = https
            test "$(docker exec "$name-web" curl -fsS http://localhost/runtime-svg.php)" = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>'
            test "$(docker exec "$name-web" curl -s -o /dev/null -w '%{http_code}' http://localhost/wp-config.php)" = 403
            return
        fi
        if [ "$(docker inspect -f '{{.State.Running}}' "$name-web")" != true ]; then
            docker logs "$name-web"
            return 1
        fi
        sleep 1
    done
    echo 'Web server did not become ready' >&2
    return 1
}
stop_web() {
    docker stop -t 10 "$name-web" >/dev/null
    test "$(docker inspect -f '{{.State.ExitCode}}' "$name-web")" = 0
    docker rm "$name-web" >/dev/null
}
start_web
docker logs "$name-web" 2>&1 | grep -q 'Database is empty; skipping migration'
stop_web
run wp core install --url=https://www.example.com --title=Test --admin_user=testadmin \
    --admin_password=integration-only --admin_email=test@example.com --skip-email
run wp eval-file /app/seed.php
original_theme="$(run wp option get stylesheet)"
start_web
run wp eval-file /app/assert-migration.php
test "$(docker exec "$name-web" curl -s -o /dev/null -w '%{http_code}' -H 'Host: wordpress.test:8080' http://localhost/migration-fixture/)" = 200
test "$(run wp option get stylesheet)" = "$original_theme"
stop_web
start_web
run wp eval-file /app/assert-migration.php
stop_web
run /migrate-to-production --dry-run
run wp eval-file /app/assert-migration.php
run /migrate-to-staging
run env EXPECTED_ORIGIN=https://staging.example.com wp eval-file /app/assert-migration.php
run /migrate-to-local
run /migrate-to-production
run env EXPECTED_ORIGIN=https://www.example.com wp eval-file /app/assert-migration.php
run /migrate-to-local
run wp eval-file /app/assert-migration.php

functions_before="$(cksum < "$directory/site/wp-content/themes/my-theme/functions.php")"
run /create-block hero-banner
run /build-block hero-banner
test -f "$directory/site/wp-content/themes/my-theme/blocks/hero-banner/package-lock.json"
test -f "$directory/site/wp-content/themes/my-theme/blocks/hero-banner/build/render.php"
test -f "$directory/site/wp-content/themes/my-theme/blocks/hero-banner/build/index.asset.php"
test "$functions_before" = "$(cksum < "$directory/site/wp-content/themes/my-theme/functions.php")"
for slug in hero-banner ../escape; do
    if run /create-block "$slug"; then
        echo "Invalid or duplicate block was accepted: $slug" >&2
        exit 1
    fi
done
if run env ACTIVE_THEME=missing /create-block example; then
    echo 'Missing theme was accepted' >&2
    exit 1
fi
run wp eval 'register_block_type("/app/wp-content/themes/my-theme/blocks/hero-banner/build"); if (!WP_Block_Type_Registry::get_instance()->is_registered("my-theme/hero-banner")) { WP_CLI::error("Block registration failed"); }'

started="$(date +%s)"
if docker run --rm --network "$name" --env-file "$directory/environment" \
    -e DB_HOST=127.0.0.1 -v "$directory/site:/app" "$image" > "$directory/timeout.log" 2>&1; then
    echo 'Expected connection timeout failure' >&2
    exit 1
fi
elapsed=$(( $(date +%s) - started ))
test "$elapsed" -ge 59
test "$elapsed" -le 70
grep -q 'Database connection timed out' "$directory/timeout.log"
echo 'Integration tests passed.'
