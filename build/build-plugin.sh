#!/usr/bin/env bash
# File: build/build-plugin.sh
#
# Build a distribution archive from an explicit runtime allowlist.

main() {
	local version="${1:-}"
	local project_dir
	local dist_dir
	local stage_root
	local plugin_dir
	local zip_name
	local current_version
	local stable_tag
	local required=(
		'argentwolf-post-notifier.php'
		'autoload.php'
		'LICENSE'
		'readme.txt'
		'uninstall.php'
		'src'
	)

	project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." 2>/dev/null && pwd)"
	dist_dir="${project_dir}/dist"
	stage_root="${dist_dir}/stage"
	plugin_dir="${stage_root}/argentwolf-post-notifier"

	if [[ -z "${version}" ]]; then
		printf 'ERROR: usage: bash build/build-plugin.sh VERSION\n' >&2
		return 1
	fi

	current_version="$(
		sed -nE 's/^[[:space:]]*\*[[:space:]]+Version:[[:space:]]+([^[:space:]]+).*/\1/p' \
			"${project_dir}/argentwolf-post-notifier.php" |
			head -n 1
	)"
	stable_tag="$(
		sed -nE 's/^Stable tag:[[:space:]]+([^[:space:]]+).*/\1/p' \
			"${project_dir}/readme.txt" |
			head -n 1
	)"

	if [[ "${version}" != "${current_version}" || "${version}" != "${stable_tag}" ]]; then
		printf 'ERROR: requested version, plugin header, and Stable Tag must match.\n' >&2
		printf 'Requested=%s Header=%s Stable=%s\n' \
			"${version}" "${current_version}" "${stable_tag}" >&2
		return 1
	fi

	for relative in "${required[@]}"; do
		if [[ ! -e "${project_dir}/${relative}" ]]; then
			printf 'ERROR: required package input is missing: %s\n' "${relative}" >&2
			return 1
		fi
	done

	rm -rf -- "${stage_root}"
	mkdir -p "${plugin_dir}" "${dist_dir}" || return 1

	install -m 0644 \
		"${project_dir}/argentwolf-post-notifier.php" \
		"${project_dir}/autoload.php" \
		"${project_dir}/LICENSE" \
		"${project_dir}/readme.txt" \
		"${project_dir}/uninstall.php" \
		"${plugin_dir}/" || return 1

	cp -a "${project_dir}/src" "${plugin_dir}/src" || return 1

	find "${plugin_dir}" -type d -exec chmod 0755 {} +
	find "${plugin_dir}" -type f -exec chmod 0644 {} +

	zip_name="argentwolf-post-notifier-${version}.zip"
	rm -f -- "${dist_dir}/${zip_name}" "${dist_dir}/SHA256SUMS"

	(
		cd "${stage_root}" || return 1
		find argentwolf-post-notifier -print |
			LC_ALL=C sort |
			zip -X -q -@ "${dist_dir}/${zip_name}"
	) || return 1

	(
		cd "${dist_dir}" || return 1
		sha256sum "${zip_name}" > SHA256SUMS
	) || return 1

	if ! bash "${project_dir}/tests/package-manifest.sh" \
		"${dist_dir}/${zip_name}"; then
		return 1
	fi

	printf 'Built: %s\n' "${dist_dir}/${zip_name}"
	printf 'Checksum: %s\n' "${dist_dir}/SHA256SUMS"
	return 0
}

main "$@" || true

# EOF: build/build-plugin.sh
