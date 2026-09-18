# zFeeder 2.0 — production image.
# Build context is the repository root:  docker build -f deploy/Dockerfile -t zfeeder .

# ---- dependencies -----------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY src/ ./src/
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---- runtime ----------------------------------------------------------------
FROM php:8.3-apache AS runtime

ARG ZF_VERSION=2.0.0
LABEL org.opencontainers.image.title="zFeeder" \
      org.opencontainers.image.description="The 2004 PHP RSS aggregator, rebuilt for 2026" \
      org.opencontainers.image.version="${ZF_VERSION}" \
      org.opencontainers.image.source="https://github.com/andreibesleaga/zfeeder" \
      org.opencontainers.image.licenses="GPL-2.0-or-later" \
      org.opencontainers.image.authors="Andrei N. Besleaga"

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    ZF_DATA_DIR=/var/www/data \
    ZF_ENV=production

# php:8.3-apache already ships every extension zFeeder needs - pdo_sqlite,
# sqlite3, dom, simplexml, mbstring, ctype, fileinfo, curl, session and OPcache -
# so nothing is compiled here. curl is installed only for the health check.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends curl; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod rewrite headers expires; \
    # autoindex and status are deliberately left enabled. Disabling them needs
    # a2dismod -f, because other modules declare a dependency on autoindex, and
    # forcing it has been observed to leave a second MPM enabled, which Apache
    # refuses to start with. Neither module exposes anything here: directory
    # listing is off via `Options -Indexes` in the vhost fragment, and
    # mod_status serves nothing without a SetHandler that is never configured.
    # The build asserts the resulting module set below.\
    sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf; \
    sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf; \
    printf 'ServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' > /etc/apache2/conf-available/zf-hardening.conf; \
    a2enconf zf-hardening

COPY deploy/php.ini /usr/local/etc/php/conf.d/zfeeder.ini
COPY deploy/apache-vhost.conf /etc/apache2/conf-available/zfeeder.conf
RUN a2enconf zfeeder

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY bin/ ./bin/
COPY src/ ./src/
COPY public/ ./public/
COPY templates/ ./templates/
COPY templates-admin/ ./templates-admin/
COPY data-dist/ ./data-dist/
COPY zfeeder.php composer.json LICENSE README.md ./
COPY docs/ ./docs/
COPY deploy/entrypoint.sh /usr/local/bin/zfeeder-entrypoint

RUN set -eux; \
    # Now that the document root exists, prove the server configuration parses
    # and that exactly one MPM is loaded, rather than finding out at boot.
    apache2ctl -t; \
    test "$(apache2ctl -M 2>/dev/null | grep -c mpm_)" = "1"; \
    chmod +x /usr/local/bin/zfeeder-entrypoint bin/zfeeder; \
    mkdir -p "$ZF_DATA_DIR"; \
    chown -R www-data:www-data "$ZF_DATA_DIR" /var/www/html; \
    find /var/www/html -type d -exec chmod 0755 {} +; \
    find /var/www/html -type f -exec chmod 0644 {} +; \
    chmod 0755 bin/zfeeder

# No VOLUME instruction: some platforms (Railway among them) reject it, and it
# is advisory anyway. Mount persistent storage at /var/www/data when you run
# the image - docker-compose.yml and docs/DEPLOYMENT.md both show how.
# The entrypoint rewrites this to $PORT when the platform sets one.
EXPOSE 80

# Apache keeps the conventional split: the master process starts as root so it
# can bind the port and take ownership of a freshly mounted volume, and every
# worker that actually handles a request runs as www-data (APACHE_RUN_USER in
# the base image). Running the master as www-data as well looks stricter, but it
# cannot initialise an empty volume, which is how most platforms present one.

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT:-80}/healthz" || exit 1

ENTRYPOINT ["zfeeder-entrypoint"]
CMD ["apache2-foreground"]
