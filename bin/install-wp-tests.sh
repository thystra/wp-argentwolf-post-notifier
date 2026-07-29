#!/usr/bin/env bash
# File: bin/install-wp-tests.sh
#
# Install the WordPress core PHPUnit test library for plugin integration tests.

main() {
	local db_name="${1:-wordpress_test}"
	local db_user="${2:-root}"
	local db_pass="${3:-}"
	local db_host="${4:-127.0.0.1}"
	local wp_version="${5:-7.0.2}"
	local tests_dir="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
	local core_dir="${WP_CORE_DIR:-/tmp/wordpress}"

	if ! command -v svn >/dev/null 2>&1; then
		printf 'ERROR: svn is required to install the WordPress test library.\n' >&2
		return 1
	fi

	rm -rf -- "${tests_dir}" "${core_dir}"
	mkdir -p "${tests_dir}" "${core_dir}" || return 1

	if ! svn export --quiet \
		"https://develop.svn.wordpress.org/tags/${wp_version}/tests/phpunit/includes/" \
		"${tests_dir}/includes"; then
		printf 'ERROR: could not download WordPress PHPUnit includes.\n' >&2
		return 1
	fi

	if ! svn export --quiet \
		"https://develop.svn.wordpress.org/tags/${wp_version}/tests/phpunit/data/" \
		"${tests_dir}/data"; then
		printf 'ERROR: could not download WordPress PHPUnit data.\n' >&2
		return 1
	fi

	if ! svn export --quiet \
		"https://core.svn.wordpress.org/tags/${wp_version}/" \
		"${core_dir}"; then
		printf 'ERROR: could not download WordPress core.\n' >&2
		return 1
	fi

	cat > "${tests_dir}/wp-tests-config.php" <<EOF
<?php
define( 'ABSPATH', '${core_dir}/' );
define( 'DB_NAME', '${db_name}' );
define( 'DB_USER', '${db_user}' );
define( 'DB_PASSWORD', '${db_pass}' );
define( 'DB_HOST', '${db_host}' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_DEBUG', true );
EOF

	printf 'WordPress %s test environment installed.\n' "${wp_version}"
	printf 'WP_TESTS_DIR=%s\n' "${tests_dir}"
	return 0
}

main "$@" || true

# EOF: bin/install-wp-tests.sh
