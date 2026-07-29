#!/usr/bin/env bash
# File: build/install-composer.sh
#
# Install project-local Composer after validating the official SHA-384
# installer signature. This script returns control to the invoking shell.

main() {
	local project_dir
	local tools_dir
	local installer
	local expected
	local actual

	project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." 2>/dev/null && pwd)"
	tools_dir="${project_dir}/tools"
	installer="${tools_dir}/composer-setup.php"

	mkdir -p "${tools_dir}" || {
		printf 'ERROR: could not create %s\n' "${tools_dir}" >&2
		return 1
	}

	expected="$(
		php -r "copy('https://composer.github.io/installer.sig', 'php://stdout');" 2>/dev/null
	)"

	if [[ -z "${expected}" ]]; then
		printf 'ERROR: could not retrieve the Composer installer signature.\n' >&2
		return 1
	fi

	if ! php -r "copy('https://getcomposer.org/installer', '${installer}');"; then
		printf 'ERROR: could not download the Composer installer.\n' >&2
		rm -f -- "${installer}"
		return 1
	fi

	actual="$(php -r "echo hash_file('sha384', '${installer}');")"

	if [[ "${expected}" != "${actual}" ]]; then
		printf 'ERROR: Composer installer checksum mismatch.\n' >&2
		rm -f -- "${installer}"
		return 1
	fi

	if ! php "${installer}" \
		--quiet \
		--install-dir="${tools_dir}" \
		--filename=composer.phar; then
		printf 'ERROR: Composer installation failed.\n' >&2
		rm -f -- "${installer}"
		return 1
	fi

	rm -f -- "${installer}"

	printf 'Composer installed: %s\n' "${tools_dir}/composer.phar"
	php "${tools_dir}/composer.phar" --version
	return 0
}

main "$@" || true

# EOF: build/install-composer.sh
