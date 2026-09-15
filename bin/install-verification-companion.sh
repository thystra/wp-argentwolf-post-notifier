#!/usr/bin/env bash
# File: bin/install-verification-companion.sh
#
# Materialize an exact released ArgentWolf Email Verification main file for
# integration testing. The minimum API fixture is taken from the canonical tag;
# the current release fixture is the checksummed Forgejo release artifact.

main() {
	local version="${1:-}"
	local destination="${2:-/tmp/argentwolf-email-verification.php}"
	local repository='https://forgejo.argentwolf.org/alan/wp-plugin-argentwolf-email-verification'
	local workdir
	local source_file
	local archive
	local expected_sha256
	local artifact_url

	if [[ -z "${version}" ]]; then
		printf 'ERROR: usage: bash bin/install-verification-companion.sh VERSION [DESTINATION]\n' >&2
		return 1
	fi

	for command in curl git grep install mktemp sha256sum unzip; do
		if ! command -v "${command}" >/dev/null 2>&1; then
			printf 'ERROR: required companion-fixture command is missing: %s\n' "${command}" >&2
			return 1
		fi
	done

	workdir="$(mktemp -d)" || return 1
	trap 'rm -rf "${workdir}"' EXIT

	case "${version}" in
		0.3.4)
			git clone --quiet --depth 1 --branch "v${version}" \
				"${repository}.git" "${workdir}/source" || return 1
			source_file="${workdir}/source/argentwolf-email-verification.php"
			;;
		1.0.2)
			expected_sha256='e5ee91ce1d4514eee8f7a5deb98211ed544c801e40f350ffed7031d83c460ca8'
			artifact_url="${repository}/releases/download/v${version}/argentwolf-email-verification-${version}.zip"
			archive="${workdir}/argentwolf-email-verification-${version}.zip"
			curl --fail --location --silent --show-error \
				"${artifact_url}" --output "${archive}" || return 1
			printf '%s  %s\n' "${expected_sha256}" "${archive}" | sha256sum -c - || return 1
			unzip -q "${archive}" -d "${workdir}/release" || return 1
			source_file="${workdir}/release/argentwolf-email-verification/argentwolf-email-verification.php"
			;;
		*)
			printf 'ERROR: unsupported companion fixture version: %s\n' "${version}" >&2
			return 1
			;;
	esac

	if [[ ! -r "${source_file}" ]]; then
		printf 'ERROR: companion main file is missing for version %s.\n' "${version}" >&2
		return 1
	fi
	if ! grep -Fq " * Version: ${version}" "${source_file}"; then
		printf 'ERROR: companion fixture version does not match %s.\n' "${version}" >&2
		return 1
	fi

	install -m 0644 "${source_file}" "${destination}" || return 1
	printf 'Installed ArgentWolf Email Verification %s fixture: %s\n' \
		"${version}" "${destination}"
	return 0
}

main "$@"

# EOF: bin/install-verification-companion.sh
