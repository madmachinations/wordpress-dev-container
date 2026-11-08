# WordPress development container

A reusable local WordPress environment with PHP 8.3, Apache, WP-CLI 2.12.0, Node.js 22, npm, and MariaDB client tools. Mount an existing **single-site** WordPress installation at `/app`; the image supplies the runtime, not WordPress core or a database dump.

The runtime uses the official `wordpress:php8.3-apache` base so its WordPress PHP extensions are already compiled. Our entrypoint uses only the site mounted at `/app`; it does not install or copy the base image's bundled WordPress files. GitHub Actions caches build layers between runs.

Published image: `ghcr.io/madmachinations/wordpress-dev-container:latest` (Linux AMD64 and ARM64, including Apple Silicon).

## Quick start

1. Put the site's complete WordPress files in `./site`, including `wp-load.php`, `wp-admin`, `wp-includes`, and `wp-content`.
2. Make `site/wp-config.php` read the environment variables as described below. The container never generates or modifies this file.
3. Copy this Compose template into the site's project as `compose.yaml`, then set the production/staging domains and theme in `.env`. OrbStack derives the local address automatically.

```yaml
name: ${ACTIVE_THEME:?Set ACTIVE_THEME in .env}
services:
  wordpress:
    image: ghcr.io/madmachinations/wordpress-dev-container:latest
    environment:
      DB_HOST: db
      DB_PORT: "3306"
      DB_USER: wordpress
      DB_PASS: ${DB_PASSWORD:-local-wordpress-password}
      DB_NAME: wordpress
      PRODUCTION_DOMAIN: ${PRODUCTION_DOMAIN:-https://www.example.com}
      STAGING_DOMAIN: ${STAGING_DOMAIN:-https://staging.example.com}
      LOCAL_DOMAIN: https://wordpress.${ACTIVE_THEME}.orb.local
      ACTIVE_THEME: ${ACTIVE_THEME}
    volumes:
      - ./site:/app
    depends_on:
      db:
        condition: service_healthy
  db:
    image: mariadb:11.4
    environment:
      MARIADB_DATABASE: wordpress
      MARIADB_USER: wordpress
      MARIADB_PASSWORD: ${DB_PASSWORD:-local-wordpress-password}
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:-local-root-password}
    volumes:
      - db-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 20
      start_period: 10s
volumes:
  db-data:
```

The same template is in [compose.example.yaml](compose.example.yaml). Example `.env`:

```dotenv
PRODUCTION_DOMAIN=https://www.example.com
STAGING_DOMAIN=https://staging.example.com
ACTIVE_THEME=my-theme
DB_PASSWORD=local-wordpress-password
DB_ROOT_PASSWORD=local-root-password
```

Start with OrbStack running; no hosts-file changes or port configuration are needed:

```sh
docker compose pull
docker compose up -d
docker compose logs -f wordpress
```

With `ACTIVE_THEME=my-theme`, visit [https://wordpress.my-theme.orb.local](https://wordpress.my-theme.orb.local). For an empty database, complete the WordPress installer or import the site's SQL dump:

```sh
docker compose exec -T wordpress wp db import - < site-backup.sql
docker compose exec wordpress /migrate-to-local
```

Importing does not trigger migration; run the helper afterward. No database is downloaded from production or staging. All helpers operate only on the configured database.

```sh
docker compose stop             # Stop; keep the containers and database
docker compose start            # Restart and normalize to local
docker compose down             # Remove containers/network; preserve database volume
docker compose down -v          # Also DELETE the database volume
```

The Compose project name is the theme name, and OrbStack exposes the `wordpress` service at `https://wordpress.<theme-name>.orb.local`. Each site uses a unique theme name, so multiple sites can run together without port conflicts. Leave the Compose project name unchanged (do not override it with `-p` or `COMPOSE_PROJECT_NAME`) so it matches the derived domain. Neither service publishes host ports. This template targets local development with OrbStack.

## Configuration

| Variable | Meaning |
| --- | --- |
| `DB_HOST` | Database hostname, without a port; `db` in the example |
| `DB_PORT` | Database TCP port; defaults to `3306` |
| `DB_USER` | WordPress database user |
| `DB_PASS` | Non-empty database password |
| `DB_NAME` | Database name |
| `PRODUCTION_DOMAIN` | Production origin, e.g. `https://www.example.com` |
| `STAGING_DOMAIN` | Staging/sandbox origin, e.g. `https://staging.example.com` |
| `LOCAL_DOMAIN` | Automatically supplied by Compose as `https://wordpress.${ACTIVE_THEME}.orb.local`; no `.env` entry needed |
| `ACTIVE_THEME` | Theme directory slug, e.g. `my-theme`; also sets the Compose project name and OrbStack domain |

All variables except `DB_PORT` are required by the container at Apache startup; Compose supplies `LOCAL_DOMAIN` automatically. Set `ACTIVE_THEME` to a lowercase theme directory slug suitable for a Compose project name and hostname, such as `my-theme`. Domains accept HTTP(S) origins with optional ports and a trailing slash, but no path, credentials, query, or fragment. Bare production/staging hostnames default to HTTPS; bare local hostnames default to HTTP. Each environment must have a distinct host/port combination. OrbStack provides HTTPS; Apache recognizes its forwarded HTTPS header so WordPress generates secure asset URLs.

Adapt the site's existing `wp-config.php`, preserving its custom settings. A minimal example:

```php
<?php
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASSWORD', getenv('DB_PASS'));
define('DB_HOST', getenv('DB_HOST') . ':' . (getenv('DB_PORT') ?: '3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Match the prefix in your imported database.
$table_prefix = 'wp_';

// Replace each placeholder with a different random value.
define('AUTH_KEY',         'replace-with-a-unique-random-value');
define('SECURE_AUTH_KEY',  'replace-with-a-unique-random-value');
define('LOGGED_IN_KEY',    'replace-with-a-unique-random-value');
define('NONCE_KEY',        'replace-with-a-unique-random-value');
define('AUTH_SALT',        'replace-with-a-unique-random-value');
define('SECURE_AUTH_SALT', 'replace-with-a-unique-random-value');
define('LOGGED_IN_SALT',   'replace-with-a-unique-random-value');
define('NONCE_SALT',       'replace-with-a-unique-random-value');

define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
```

Generate fresh local salts after preparing that file:

```sh
docker compose run --rm wordpress wp config shuffle-salts
```

Leave `WP_HOME` and `WP_SITEURL` unset so they do not override migrated values. Remove production-specific redirects or HTTPS rules from the local configuration and `.htaccess`. Apache supports WordPress `.htaccess` rewrites. The container does not disable mail, scheduled jobs, payments, or external services; configure those in each site's local settings.

## Startup behavior

Apache startup validates the configuration and mounted installation, then waits up to 60 seconds for the database:

- An empty database is served without migration, allowing installation or import.
- An installed single-site database is migrated from production and staging to local before Apache starts. Repeating this is safe when URLs are already local.
- A non-empty database that cannot load as installed WordPress, a multisite installation, or a failed migration stops startup with an error.

`ACTIVE_THEME` never changes the selected theme. Switch explicitly with `docker compose exec wordpress wp theme activate my-theme` if needed.

One-off commands bypass startup migration. For example, `docker compose run --rm wordpress wp db export -` exports the current database. The next normal Apache startup still normalizes to local.

## Database helpers

| Script | Conversion |
| --- | --- |
| `/migrate-to-local` | Production and staging → local |
| `/migrate-to-staging` | Local → staging |
| `/migrate-to-production` | Local → production |

Each accepts `--dry-run`. Helpers use WP-CLI's serialized-data-aware search/replace across all tables matching `$table_prefix`, including plugin tables, and preserve `guid` columns. HTTP and HTTPS source variants are converted to the target scheme. Complete URL authorities are matched so email addresses, longer hostnames, and other ports are preserved. Protocol-relative URLs, bare hostnames, escaped JSON URLs, additional aliases, and multisite mappings are outside these helpers' scope.

Back up before manual conversions: scripts mutate the connected database without automatic backups or transactions. Failure can leave partial conversions. Object cache is flushed after success; clear plugin page caches separately. WP-CLI skips plugins and themes during migration, but must-use plugins still load.

```sh
docker compose exec -T wordpress wp db export - > before-migration.sql
docker compose exec wordpress /migrate-to-local --dry-run
docker compose exec wordpress /migrate-to-local
```

For staging/production exports, stop the web service, convert using one-off containers, export, then restore local URLs:

```sh
docker compose stop wordpress
docker compose run --rm wordpress /migrate-to-staging --dry-run
docker compose run --rm wordpress /migrate-to-staging
docker compose run --rm -T wordpress wp db export - > staging.sql
docker compose run --rm wordpress /migrate-to-local
docker compose start wordpress
```

Use `/migrate-to-production` for a production export. These commands do not upload SQL or connect to another database. Never point this development container at a live production database: normal startup rewrites the configured database to local.

## Theme block development

The helpers create native, server-rendered Gutenberg blocks for WordPress 6.3 or newer, without ACF. `ACTIVE_THEME` selects `/app/wp-content/themes/<slug>`; for a child theme, use its directory slug.

```sh
docker compose exec wordpress /create-block hero-banner
docker compose exec wordpress /build-block hero-banner
docker compose exec wordpress /watch-block hero-banner
```

Creation writes `blocks/hero-banner` in that theme, installs dependencies, retains `package-lock.json`, and runs an initial build. It supplies `block.json`, a RichText editor, editor/frontend SCSS, and a PHP render template. Tooling and direct dependencies have exact versions; commit the lockfile to pin transitive dependencies. Use `npm ci` for subsequent clean installs inside the block directory. Installation needs internet access.

Add the printed registration snippet to the active theme's `functions.php` yourself:

```php
add_action('init', function () {
    register_block_type(get_stylesheet_directory() . '/blocks/hero-banner/build');
});
```

`functions.php` is never modified automatically. Slugs must look like `hero-banner`; existing destinations, missing themes, and path traversal are rejected. If npm fails, keep the generated files, fix the dependency/network problem, and rerun `npm install` and `npm run build` in that block directory. Repeating `/create-block` refuses to overwrite it.

Run `/watch-block` in another terminal while editing `src/`. Commit `build/` files if deployment does not run npm. Registration uses the active/child theme directory; adjust it if intentionally loading blocks from another theme.

On Linux, commands run as root by default. To create host-owned files:

```sh
docker compose exec --user "$(id -u):$(id -g)" \
  -e HOME=/tmp -e npm_config_cache=/tmp/wordpress-npm-cache \
  wordpress /create-block hero-banner
```

The image does not recursively change `/app` ownership. Ensure files are readable by Apache's `www-data` user and make specific upload/cache directories writable when needed.

## Builds and publishing

```sh
docker build -t wordpress-dev:test .
bash tests/static.sh
bash tests/integration.sh wordpress-dev:test
```

The integration suite creates disposable WordPress and MariaDB fixtures and checks startup, URL conversions, serialized values, dry-run, GUID/table isolation, repeat startup, theme preservation, block compilation/registration, invalid block inputs, database timeout, and shutdown. It needs Docker and network access for WordPress, MariaDB, and npm downloads.

Two independent GitHub Actions workflows:

- **Pull request validation:** PRs targeting `main`; builds AMD64 and ARM64 without login or push.
- **Publish container:** pushes to `main` only; builds and publishes both architectures as `latest`. Only this job has `packages: write`; authentication uses `GITHUB_TOKEN`.

After the first publish, check package settings and make the GHCR package public for anonymous pulls. Private packages require `docker login ghcr.io` with an authorized token. If an existing package rejects publishing, check its linked repository and Actions access. Source metadata links the image to this repository.

`latest` advances on successful `main` builds. Use an image digest for a fixed downstream version. PHP and MariaDB minor-series tags receive upstream fixes on rebuild/pull; Node, WP-CLI, and block dependencies are pinned here and should be updated deliberately. The test scripts above are available to run manually; the workflows only build and publish.

## Troubleshooting

- **Startup exits:** inspect `docker compose logs wordpress`; check the mount, `wp-config.php`, required variables, and table prefix. Repair or replace a partial/non-WordPress database before startup.
- **Database timeout:** inspect `docker compose logs db`; existing MariaDB volumes retain original users/passwords even when `.env` changes. Update database credentials or deliberately recreate the disposable volume.
- **Wrong redirects:** rerun `/migrate-to-local`, check `WP_HOME`/`WP_SITEURL`, `.htaccess`, caches, and plugin redirects. Use `https://wordpress.<theme-name>.orb.local` with OrbStack running, and keep the Compose project name equal to `ACTIVE_THEME`.
- **Write failures:** grant the relevant user access to the specific theme/uploads/cache directory. No global ownership rewrite happens on startup.
- **No image yet:** the GHCR tag exists after publishing succeeds on `main`; use a local build meanwhile.
