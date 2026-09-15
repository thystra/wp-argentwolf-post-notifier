#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: scripts/build-ci-php-images.sh [--push]

Builds and qualifies reusable PHP 8.4 and 8.5 Forgejo CI images. With --push,
publishes the immutable versioned tags, verifies anonymous registry pull access,
and prints the observed RepoDigests.

Environment overrides:
  ARGENTWOLF_CI_PHP_REPOSITORY
      default: forgejo.argentwolf.org/alan/wp-plugin-argentwolf-post-notifier/ci-php
  ARGENTWOLF_CI_PHP_TAG_SUFFIX
      default: bookworm-v1
  ARGENTWOLF_CI_COMPOSER_BASE_IMAGE
      default: composer:2
  DOCKER
      default: docker
USAGE
}

push=false
case "${1:-}" in
    '') ;;
    --push) push=true ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
esac

repo_root="$(git rev-parse --show-toplevel 2>/dev/null)" || {
    printf 'ERROR: run this helper inside the source repository.\n' >&2
    exit 1
}
cd "$repo_root"

if [[ -n "$(git status --porcelain=v1 --untracked-files=all)" ]]; then
    printf 'ERROR: CI images must be built from a clean committed tree.\n' >&2
    git status --short >&2
    exit 1
fi

source_revision="$(git rev-parse HEAD)"
if [[ ! "$source_revision" =~ ^[0-9a-f]{40}$ ]]; then
    printf 'ERROR: could not resolve an exact source revision.\n' >&2
    exit 1
fi

docker_bin="${DOCKER:-docker}"
command -v "$docker_bin" >/dev/null 2>&1 || {
    printf 'ERROR: Docker command is unavailable: %s\n' "$docker_bin" >&2
    exit 1
}

repository="${ARGENTWOLF_CI_PHP_REPOSITORY:-forgejo.argentwolf.org/alan/wp-plugin-argentwolf-post-notifier/ci-php}"
tag_suffix="${ARGENTWOLF_CI_PHP_TAG_SUFFIX:-bookworm-v1}"
composer_base_tag="${ARGENTWOLF_CI_COMPOSER_BASE_IMAGE:-composer:2}"
versions=(8.4 8.5)

resolve_repo_digest() {
    local image="$1"
    local digest

    "$docker_bin" pull "$image" >/dev/null
    digest="$($docker_bin image inspect "$image" --format '{{range .RepoDigests}}{{println .}}{{end}}' | head -n 1)"
    if [[ "$digest" != *@sha256:* ]]; then
        printf 'ERROR: could not resolve immutable registry digest for %s\n' "$image" >&2
        exit 1
    fi
    printf '%s\n' "$digest"
}

printf '===== BUILD AUTHORITY =====\n'
printf 'source_revision=%s\n' "$source_revision"
printf 'repository=%s\n' "$repository"
printf 'tag_suffix=%s\n' "$tag_suffix"

printf '\n===== RESOLVE COMPOSER BASE =====\n'
composer_base_ref="$(resolve_repo_digest "$composer_base_tag")"
printf 'composer_base=%s\n' "$composer_base_ref"

for version in "${versions[@]}"; do
    printf '\n===== PHP %s BASE =====\n' "$version"
    php_base_tag="php:${version}-cli-bookworm"
    php_base_ref="$(resolve_repo_digest "$php_base_tag")"
    image_tag="${repository}:${version}-${tag_suffix}"

    printf 'php_base=%s\n' "$php_base_ref"
    printf 'image_tag=%s\n' "$image_tag"

    printf '\n===== BUILD PHP %s =====\n' "$version"
    "$docker_bin" build \
        --file ci/images/php/Dockerfile \
        --build-arg "PHP_BASE_IMAGE=${php_base_ref}" \
        --build-arg "COMPOSER_BASE_IMAGE=${composer_base_ref}" \
        --build-arg "EXPECTED_PHP_MINOR=${version}" \
        --build-arg "SOURCE_REVISION=${source_revision}" \
        --build-arg "PHP_BASE_IMAGE_REFERENCE=${php_base_ref}" \
        --build-arg "COMPOSER_BASE_IMAGE_REFERENCE=${composer_base_ref}" \
        --tag "$image_tag" \
        .

    printf '\n===== QUALIFY PHP %s =====\n' "$version"
    "$docker_bin" run --rm "$image_tag" argentwolf-verify-php-ci-image

    image_revision="$($docker_bin image inspect "$image_tag" --format '{{index .Config.Labels "org.opencontainers.image.revision"}}')"
    image_php="$($docker_bin image inspect "$image_tag" --format '{{index .Config.Labels "org.argentwolf.ci.php-minor"}}')"
    [[ "$image_revision" == "$source_revision" ]] || {
        printf 'ERROR: image source revision label mismatch for PHP %s.\n' "$version" >&2
        exit 1
    }
    [[ "$image_php" == "$version" ]] || {
        printf 'ERROR: image PHP minor label mismatch for PHP %s.\n' "$version" >&2
        exit 1
    }

    if [[ "$push" == true ]]; then
        printf '\n===== PUBLISH PHP %s =====\n' "$version"
        "$docker_bin" push "$image_tag"

        printf '\n===== VERIFY ANONYMOUS PULL PHP %s =====\n' "$version"
        anon_config="$(mktemp -d)"
        trap 'rm -rf -- "${anon_config:-}"' EXIT
        DOCKER_CONFIG="$anon_config" "$docker_bin" pull "$image_tag" >/dev/null
        rm -rf -- "$anon_config"
        anon_config=''
        trap - EXIT

        printf '\n===== REGISTRY IDENTITY PHP %s =====\n' "$version"
        repo_digests="$($docker_bin image inspect "$image_tag" --format '{{range .RepoDigests}}{{println .}}{{end}}')"
        if ! grep -Fq "${repository}@sha256:" <<<"$repo_digests"; then
            printf 'ERROR: published RepoDigest was not observed for PHP %s.\n' "$version" >&2
            exit 1
        fi
        printf '%s\n' "$repo_digests"
    fi
done

printf '\nRESULT: reusable PHP CI image build/qualification PASS\n'
if [[ "$push" == true ]]; then
    printf 'RESULT: publication and anonymous-pull verification PASS\n'
fi
