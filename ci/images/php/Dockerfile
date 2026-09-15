# syntax=docker/dockerfile:1

ARG PHP_BASE_IMAGE=php:8.4-cli-bookworm
ARG COMPOSER_BASE_IMAGE=composer:2
ARG NODE_BASE_IMAGE=node:24-bookworm

FROM ${COMPOSER_BASE_IMAGE} AS composer
FROM ${NODE_BASE_IMAGE} AS node_toolchain

FROM ${PHP_BASE_IMAGE}

ARG EXPECTED_PHP_MINOR=8.4
ARG SOURCE_REVISION=unknown
ARG PHP_BASE_IMAGE_REFERENCE=unknown
ARG COMPOSER_BASE_IMAGE_REFERENCE=unknown
ARG NODE_BASE_IMAGE_REFERENCE=unknown

LABEL org.opencontainers.image.source="https://forgejo.argentwolf.org/alan/wp-plugin-argentwolf-post-notifier" \
      org.opencontainers.image.title="ArgentWolf shared PHP CI" \
      org.opencontainers.image.description="Reusable PHP CLI toolchain for ArgentWolf Forgejo Actions" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" \
      org.opencontainers.image.base.name="${PHP_BASE_IMAGE_REFERENCE}" \
      org.argentwolf.ci.composer-base="${COMPOSER_BASE_IMAGE_REFERENCE}" \
      org.argentwolf.ci.node-base="${NODE_BASE_IMAGE_REFERENCE}" \
      org.argentwolf.ci.php-minor="${EXPECTED_PHP_MINOR}"

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    ARGENTWOLF_CI_PHP_VERSION=${EXPECTED_PHP_MINOR}

# The official PHP CLI images already provide curl, DOM, mbstring, SimpleXML,
# XML, and XMLWriter. Rebuilding those bundled extensions is unnecessary and,
# on PHP 8.5, breaks DOM because its bundled Lexbor sources are not present in
# the extension-only rebuild tree. Compile only the additional CI requirements.
RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        ca-certificates \
        curl \
        git \
        libicu-dev \
        libzip-dev \
        rsync \
        subversion \
        unzip \
        zip \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        mysqli \
        pdo_mysql \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Forgejo JavaScript actions such as checkout execute with Node inside the job
# container. Copy only the Node runtime; routine PHP jobs do not need npm.
COPY --from=node_toolchain /usr/local/bin/node /usr/local/bin/node
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY ci/images/php/verify-image.sh /usr/local/bin/argentwolf-verify-php-ci-image

RUN chmod 0755 /usr/local/bin/argentwolf-verify-php-ci-image \
    && argentwolf-verify-php-ci-image
