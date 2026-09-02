# Gäld Community Edition — production-ish container image for the GlaStar Flyers
# evaluation instance (Coolify / Traefik on vps.lanz.aero).
#
# Multi-stage:
#   src     — download a pinned Gäld CE release tarball
#   assets  — pnpm install + vite build  (Node 24)
#   vendor  — composer install --no-dev   (PHP 8.4 + Composer)
#   runtime — php-fpm + nginx + supervisor, OCR via tesseract
#
# The same image runs the web container (php-fpm + nginx) and the queue
# container (php artisan horizon) — see docker-compose.yml.

# renovate: datasource=github-releases depName=Scanix/Gaeld
ARG GAELD_VERSION=v3.6.6

########################  stage: src  ########################
FROM alpine:3.20 AS src
ARG GAELD_VERSION
RUN apk add --no-cache curl tar
WORKDIR /src
RUN curl -fsSL "https://github.com/Scanix/Gaeld/archive/refs/tags/${GAELD_VERSION}.tar.gz" -o /tmp/g.tgz \
 && tar -xzf /tmp/g.tgz --strip-components=1 -C /src \
 && rm /tmp/g.tgz \
 && test -f /src/artisan

########################  stage: assets  ########################
FROM node:24-alpine AS assets
RUN corepack enable
WORKDIR /app
COPY --from=src /src/ /app/
# CE-only asset bundle (no private EE plugin frontend)
ENV VITE_PLUGINS_ENABLED=false
RUN pnpm install --frozen-lockfile \
 && pnpm run build \
 && test -f /app/public/build/manifest.json

########################  stage: vendor  ########################
FROM php:8.4-cli-alpine AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY --from=src /src/ /app/
# Runtime image provides the real extensions; skip the platform check here and
# skip post-install scripts (artisan needs a booted app + DB, run at deploy time).
# Full install (incl. dev deps): the evaluation needs `artisan tinker` on the
# instance to bootstrap the org/token and to independently verify GL postings.
# APP_ENV=production keeps dev-only providers (ignition, scribe) dormant.
RUN composer install --no-interaction --prefer-dist \
      --optimize-autoloader --no-scripts --ignore-platform-reqs

########################  stage: runtime  ########################
FROM php:8.4-fpm-alpine AS runtime

# --- system packages ---------------------------------------------------------
RUN apk add --no-cache \
      nginx supervisor bash curl tzdata \
      icu-libs libzip libpng libjpeg-turbo freetype libgomp \
      postgresql16-client \
      tesseract-ocr tesseract-ocr-data-deu tesseract-ocr-data-fra tesseract-ocr-data-eng

# --- PHP extensions --------------------------------------------------------
RUN apk add --no-cache --virtual .build-deps \
      $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
      postgresql-dev oniguruma-dev linux-headers \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
      pdo pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip intl opcache sockets \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps \
 && rm -rf /tmp/pear

COPY docker/php.ini      /usr/local/etc/php/conf.d/zz-gaeld.ini
COPY docker/www-pool.conf /usr/local/etc/php-fpm.d/zz-gaeld.conf

# --- application -----------------------------------------------------------
WORKDIR /var/www/html
COPY --from=src    /src/            /var/www/html/
COPY --from=vendor /app/vendor/     /var/www/html/vendor/
COPY --from=assets /app/public/build/ /var/www/html/public/build/

RUN php artisan package:discover --ansi \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
             storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R ug+rwX storage bootstrap/cache

COPY docker/nginx.conf     /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh  /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV TESSERACT_BINARY=/usr/bin/tesseract \
    TESSERACT_LANG=deu+fra+eng

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
