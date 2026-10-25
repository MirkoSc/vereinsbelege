# syntax=docker/dockerfile:1
#
# The optional processing worker (docs/spec/07-worker.md section 7, issue
# #55, E-53): bin/worker.php + worker/src + the shared app/src, with the
# tools it starts through App\Worker\Prozess (OCRmyPDF, Tesseract,
# Ghostscript, Poppler, unpaper). Never runs on the web host.
#
# Build context is the repository root:
#   docker build -f worker/Dockerfile -t vereinsbelege-worker .
#
# Base image: the official PHP image on Debian trixie, pinned to one PHP
# patch release. Debian's own php8.x-cli package is PHP 8.4, the project
# targets PHP 8.5 (CLAUDE.md section 1), so the PHP comes from the official
# image and everything else from Debian trixie.

ARG PHP_IMAGE=php:8.5.12-cli-trixie
ARG COMPOSER_IMAGE=composer:2.10.3

# --- Composer autoloader (no runtime dependencies; dev ones stay out) -------
FROM ${COMPOSER_IMAGE} AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY app/src app/src
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

# --- Worker image ------------------------------------------------------------
FROM ${PHP_IMAGE}

# The tools, from Debian. tesseract-ocr-osd: page orientation (--rotate-pages).
# tini as PID 1: forwards SIGTERM to PHP and reaps the grandchildren of
# OCRmyPDF (Tesseract, Ghostscript) when a job is cut off.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ocrmypdf tesseract-ocr tesseract-ocr-deu tesseract-ocr-eng tesseract-ocr-osd \
        ghostscript poppler-utils unpaper tini; \
    rm -rf /var/lib/apt/lists/*

# PHP extensions: pcntl (SIGTERM gives the running job back - bin/worker.php
# refuses to run without it), gd (PNG -> JPEG of the shared code) and the
# rest of what composer.json requires. Build headers are removed again, the
# runtime libraries stay (same pattern as the official image).
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pcntl gd zip pdo_mysql; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

COPY worker/php.ini /usr/local/etc/php/conf.d/zz-worker.ini

WORKDIR /app
COPY --from=vendor /app/vendor vendor
COPY VERSION VERSION
COPY app/src app/src
COPY app/prompts app/prompts
COPY worker/autoload.php worker/autoload.php
COPY worker/src worker/src
COPY bin/worker.php bin/worker.php

# The release workflow passes the tag (without "v"); a local build keeps
# the repository's VERSION file.
ARG WORKER_VERSION=""
RUN set -eux; \
    if [ -n "$WORKER_VERSION" ]; then printf '%s\n' "$WORKER_VERSION" > VERSION; fi; \
    useradd --system --uid 10001 --home-dir /nonexistent --shell /usr/sbin/nologin worker; \
    mkdir -p /daten /arbeit; \
    chown worker:worker /daten /arbeit; \
    chmod 0700 /daten /arbeit

# Fail the build, not the first job, when a piece is missing.
RUN set -eux; \
    php -r 'foreach (["pcntl", "sodium", "curl", "mbstring", "gd", "dom", "xmlreader", "zlib"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension $e\n"); exit(1); } }'; \
    ocrmypdf --version; \
    tesseract --list-langs 2>&1 | grep -qx deu; \
    tesseract --list-langs 2>&1 | grep -qx eng; \
    tesseract --list-langs 2>&1 | grep -qx osd; \
    pdftoppm -v; pdfinfo -v; gs --version; unpaper --version; \
    php bin/worker.php help | grep -q 'worker run'

USER worker

# WORKER_ARBEIT must be a tmpfs (the worker refuses anything else) - the
# compose template mounts one. WORKER_DATEN holds the identity file only.
ENV WORKER_DATEN=/daten \
    WORKER_ARBEIT=/arbeit \
    TMPDIR=/arbeit

VOLUME ["/daten"]

ENTRYPOINT ["/usr/bin/tini", "--", "php", "/app/bin/worker.php"]
CMD ["run"]

LABEL org.opencontainers.image.title="vereinsbelege-worker" \
      org.opencontainers.image.description="Optionaler Verarbeitungs-Worker für Vereinsbelege (OCR, PDF/A, PDF-Rasterung, KI-Auslesen)" \
      org.opencontainers.image.source="https://github.com/MirkoSc/vereinsbelege" \
      org.opencontainers.image.licenses="GPL-3.0-or-later"
