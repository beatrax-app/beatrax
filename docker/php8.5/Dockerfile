# syntax=docker/dockerfile:1
#
# Dev-only PHP 8.5 toolchain for running the CI gates (Pint, Larastan,
# Pest) without installing PHP on the host. The shipped app runs via
# NativePHP — this image is NOT a production runtime, it
# only exists so the quality gates and the queue-worker behaviour can be
# exercised locally on the same PHP version the bundle targets.
FROM php:8.5-cli

# mlocati's installer resolves each extension's build deps + ini config.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# Extensions the app declares in composer.json. ctype / filter / iconv /
# libxml / mbstring / pdo / simplexml ship with the base image; the rest
# need building. pdo_sqlite backs the SQLite store the tests + the shipped
# app use; pcntl/posix back the queue worker's signal handling (their
# ABSENCE on Windows is the root cause of the worker-timeout bug this
# image helps verify). zip + bcmath cover composer + Money code paths.
RUN install-php-extensions \
        intl \
        pcntl \
        posix \
        pdo_sqlite \
        zip \
        bcmath \
    && install-php-extensions @composer

# git + unzip let composer fetch/extract dist packages; poppler-utils
# provides `pdftotext`, which spatie/pdf-to-text shells out to in the
# Receipts ingestion path (and its tests). sqlite3 is the CLI Laravel's
# schema loader shells out to when replaying database/schema/sqlite-schema.sql,
# so without it `artisan migrate` aborts on a fresh database.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip poppler-utils sqlite3 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
