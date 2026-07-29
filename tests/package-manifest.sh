#!/usr/bin/env bash
# File: tests/package-manifest.sh

main() {
	local archive="${1:-}"
	local expected_root='argentwolf-post-notifier/'
	local listing
	local prohibited

	if [[ -z "${archive}" || ! -f "${archive}" ]]; then
		printf 'ERROR: package archive is missing.\n' >&2
		return 1
	fi

	if ! unzip -t "${archive}" >/dev/null; then
		printf 'ERROR: package archive integrity check failed.\n' >&2
		return 1
	fi

	listing="$(unzip -Z1 "${archive}")"

	if [[ -z "${listing}" ]]; then
		printf 'ERROR: package archive is empty.\n' >&2
		return 1
	fi

	if printf '%s\n' "${listing}" | grep -Ev "^${expected_root}" >/dev/null; then
		printf 'ERROR: package contains a path outside %s\n' "${expected_root}" >&2
		return 1
	fi

	for required in \
		'argentwolf-post-notifier/argentwolf-post-notifier.php' \
		'argentwolf-post-notifier/autoload.php' \
		'argentwolf-post-notifier/LICENSE' \
		'argentwolf-post-notifier/readme.txt' \
		'argentwolf-post-notifier/uninstall.php' \
		'argentwolf-post-notifier/src/Plugin.php' \
		'argentwolf-post-notifier/src/Version.php'
	do
		if ! printf '%s\n' "${listing}" | grep -Fxq "${required}"; then
			printf 'ERROR: required package path is missing: %s\n' "${required}" >&2
			return 1
		fi
	done

	prohibited="$(
		printf '%s\n' "${listing}" |
			grep -E '/(\.git|\.github|tests|build|bin|node_modules|vendor)/|/(AGENTS|ARCHITECTURE|CHANGELOG|TODO)\.md$|/(composer|package|phpcs|phpunit)' ||
			true
	)"

	if [[ -n "${prohibited}" ]]; then
		printf 'ERROR: development-only package paths found:\n%s\n' "${prohibited}" >&2
		return 1
	fi

	printf 'Package manifest validation passed.\n'
	return 0
}

main "$@" || true

# EOF: tests/package-manifest.sh
