#!/usr/bin/env bash
# File: build/build-plugin.sh
#
# Build a deterministic distribution archive from the runtime allowlist.

main() {
	local requested_version="${1:-}"
	local project_dir
	local dist_dir
	local stage_root
	local plugin_dir
	local zip_name
	local version
	local header_version
	local stable_tag
	local source_date_epoch
	local required=(
		'argentwolf-post-notifier.php'
		'autoload.php'
		'LICENSE'
		'readme.txt'
		'uninstall.php'
		'src'
		'assets/runtime'
		'blocks/subscribe/block.json'
	)

	project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." 2>/dev/null && pwd)"
	dist_dir="${project_dir}/dist"
	stage_root="${dist_dir}/stage"
	plugin_dir="${stage_root}/argentwolf-post-notifier"

	for command in php zip sha256sum sed find touch git; do
		if ! command -v "${command}" >/dev/null 2>&1; then
			printf 'ERROR: required build command is missing: %s\n' "${command}" >&2
			return 1
		fi
	done

	version="$(
		php -r \
			'require $argv[1]; echo \ArgentWolf\PostNotifier\Version::PLUGIN;' \
			"${project_dir}/src/Version.php"
	)" || return 1

	if [[ -z "${version}" ]]; then
		printf 'ERROR: canonical plugin version could not be resolved.\n' >&2
		return 1
	fi

	if [[ -n "${requested_version}" && "${requested_version}" != "${version}" ]]; then
		printf 'ERROR: requested version does not match Version::PLUGIN.\n' >&2
		printf 'Requested=%s Canonical=%s\n' "${requested_version}" "${version}" >&2
		return 1
	fi

	header_version="$(
		sed -nE 's/^[[:space:]]*\*[[:space:]]+Version:[[:space:]]+([^[:space:]]+).*/\1/p' \
			"${project_dir}/argentwolf-post-notifier.php" |
			head -n 1
	)"
	stable_tag="$(
		sed -nE 's/^Stable tag:[[:space:]]+([^[:space:]]+).*/\1/p' \
			"${project_dir}/readme.txt" |
			head -n 1
	)"

	if [[ "${version}" != "${header_version}" || "${version}" != "${stable_tag}" ]]; then
		printf 'ERROR: Version::PLUGIN, plugin header, and Stable Tag must match.\n' >&2
		printf 'Canonical=%s Header=%s Stable=%s\n' \
			"${version}" "${header_version}" "${stable_tag}" >&2
		return 1
	fi

	for relative in "${required[@]}"; do
		if [[ ! -e "${project_dir}/${relative}" ]]; then
			printf 'ERROR: required package input is missing: %s\n' "${relative}" >&2
			return 1
		fi
	done

	source_date_epoch="${SOURCE_DATE_EPOCH:-}"
	if [[ -z "${source_date_epoch}" ]]; then
		source_date_epoch="$(git -C "${project_dir}" log -1 --format=%ct HEAD 2>/dev/null)" || return 1
	fi
	if [[ ! "${source_date_epoch}" =~ ^[0-9]+$ ]]; then
		printf 'ERROR: SOURCE_DATE_EPOCH must be an integer Unix timestamp.\n' >&2
		return 1
	fi

	rm -rf -- "${stage_root}"
	mkdir -p "${plugin_dir}/assets" "${plugin_dir}/blocks/subscribe" "${dist_dir}" || return 1

	install -m 0644 \
		"${project_dir}/argentwolf-post-notifier.php" \
		"${project_dir}/autoload.php" \
		"${project_dir}/LICENSE" \
		"${project_dir}/readme.txt" \
		"${project_dir}/uninstall.php" \
		"${plugin_dir}/" || return 1

	cp -a "${project_dir}/src" "${plugin_dir}/src" || return 1
	cp -a "${project_dir}/assets/runtime" "${plugin_dir}/assets/runtime" || return 1
	install -m 0644 \
		"${project_dir}/blocks/subscribe/block.json" \
		"${plugin_dir}/blocks/subscribe/block.json" || return 1

	find "${plugin_dir}" -type d -exec chmod 0755 {} +
	find "${plugin_dir}" -type f -exec chmod 0644 {} +
	find "${plugin_dir}" -exec touch -h -d "@${source_date_epoch}" {} + || return 1

	zip_name="argentwolf-post-notifier-${version}.zip"
	rm -f -- "${dist_dir}/${zip_name}" "${dist_dir}/SHA256SUMS"

	(
		cd "${stage_root}" || return 1
		TZ=UTC find argentwolf-post-notifier -print |
			LC_ALL=C sort |
			TZ=UTC zip -X -q -@ "${dist_dir}/${zip_name}"
	) || return 1

	(
		cd "${dist_dir}" || return 1
		sha256sum "${zip_name}" > SHA256SUMS
	) || return 1

	bash "${project_dir}/tests/package-manifest.sh" \
		"${dist_dir}/${zip_name}" || return 1

	printf 'Version: %s\n' "${version}"
	printf 'SOURCE_DATE_EPOCH: %s\n' "${source_date_epoch}"
	printf 'Built: %s\n' "${dist_dir}/${zip_name}"
	printf 'Checksum: %s\n' "${dist_dir}/SHA256SUMS"
	return 0
}

main "$@"

# EOF: build/build-plugin.sh
