# syntax=docker/dockerfile:1
FROM node:22.23.4-bookworm-slim AS node
# Reuse WordPress's prebuilt PHP extensions instead of compiling them here
# (especially expensive for ARM64 under QEMU). Our entrypoint still serves /app.
FROM wordpress:php8.3-apache

ARG WP_CLI_VERSION=2.12.0
LABEL org.opencontainers.image.source="https://github.com/madmachinations/wordpress-dev-container" \
      org.opencontainers.image.description="Reusable local WordPress development environment" \
      org.opencontainers.image.licenses="GPL-3.0-only"

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip mariadb-client \
    && a2enmod rewrite headers expires \
    && curl -fsSL "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" -o /usr/local/lib/wp-cli.phar \
    && curl -fsSL "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar.sha512" -o /tmp/wp-cli.sha512 \
    && WP_CLI_SHA="$(awk '{print $1}' /tmp/wp-cli.sha512)" \
    && echo "$WP_CLI_SHA  /usr/local/lib/wp-cli.phar" | sha512sum -c - \
    && rm -rf /var/lib/apt/lists/* /tmp/wp-cli.sha512

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-development.ini
COPY scripts/ /usr/local/lib/wordpress-dev/
COPY templates/ /usr/local/share/wordpress-dev/
RUN chmod +x /usr/local/lib/wordpress-dev/entrypoint /usr/local/lib/wordpress-dev/wp \
        /usr/local/lib/wordpress-dev/migrate /usr/local/lib/wordpress-dev/block \
    && ln -s /usr/local/lib/wordpress-dev/wp /usr/local/bin/wp \
    && for target in local staging production; do ln -s /usr/local/lib/wordpress-dev/migrate "/migrate-to-$target"; done \
    && for action in create build watch; do ln -s /usr/local/lib/wordpress-dev/block "/$action-block"; done
ENV DB_PORT=3306 WP_CLI_ALLOW_ROOT=1
WORKDIR /app
EXPOSE 80
ENTRYPOINT ["/usr/local/lib/wordpress-dev/entrypoint"]
CMD ["apache2-foreground"]
