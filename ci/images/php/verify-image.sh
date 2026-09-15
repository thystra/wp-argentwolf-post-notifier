#!/usr/bin/env bash
set -euo pipefail

expected="${ARGENTWOLF_CI_PHP_VERSION:?ARGENTWOLF_CI_PHP_VERSION is required}"
actual="$(php -r 'printf("%d.%d", PHP_MAJOR_VERSION, PHP_MINOR_VERSION);')"

if [[ "$actual" != "$expected" ]]; then
    printf 'ERROR: expected PHP %s, found PHP %s\n' "$expected" "$actual" >&2
    exit 1
fi

for command in php composer node git curl svn rsync unzip zip; do
    if ! command -v "$command" >/dev/null 2>&1; then
        printf 'ERROR: required CI command is missing: %s\n' "$command" >&2
        exit 1
    fi
done

required_extensions=(
    curl
    dom
    intl
    mbstring
    mysqli
    pdo_mysql
    simplexml
    xml
    xmlwriter
    zip
)

modules="$(php -m | tr '[:upper:]' '[:lower:]')"
for extension in "${required_extensions[@]}"; do
    if ! grep -Fxq "$extension" <<<"$modules"; then
        printf 'ERROR: required PHP extension is missing: %s\n' "$extension" >&2
        exit 1
    fi
done

php --version
composer --version
node --version
svn --version --quiet
printf 'ArgentWolf shared PHP CI image verification passed for PHP %s.\n' "$actual"
