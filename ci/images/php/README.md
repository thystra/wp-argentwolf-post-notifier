# ArgentWolf shared PHP CI images

These images move repeated PHP/toolchain setup out of routine Forgejo Actions
runs. They are owned and published from this repository, but intentionally
contain no Post Notifier application code or dependencies and may be consumed
by other ArgentWolf Forgejo repositories.

This follows the same publication model as the AWVP FFmpeg CI image: a project
repository owns the reviewed image definition and registry namespace, while
other repositories may pull the qualified image by immutable digest.

## Contents

The PHP 8.4 and 8.5 variants use the official `php:<minor>-cli-bookworm` base
and contain:

- PHP CLI for the selected minor release;
- Composer 2;
- PHP extensions commonly required by WordPress/PHPUnit/plugin projects:
  `curl`, `dom`, `intl`, `mbstring`, `mysqli`, `pdo_mysql`, `simplexml`, `xml`,
  `xmlwriter`, and `zip`;
- `git`, `curl`, Subversion, `rsync`, `unzip`, and `zip`.

Application dependencies remain repository/lockfile owned. Do not bake a
project's `vendor/`, WordPress tree, npm dependencies, or test fixtures into
these shared images.

## Registry coordinates

The first immutable discovery tags are:

```text
forgejo.argentwolf.org/alan/wp-plugin-argentwolf-post-notifier/ci-php:8.4-bookworm-v1
forgejo.argentwolf.org/alan/wp-plugin-argentwolf-post-notifier/ci-php:8.5-bookworm-v1
```

Versioned tags are publish-once discovery names. Never overwrite one. Change the
`vN` suffix when the image definition changes. Routine CI must use the observed
registry `@sha256:...` digest after qualification, not the mutable tag.

The packages must remain anonymously pullable so any Forgejo repository can use
them without embedding registry credentials in normal CI jobs.

## Build and qualify

Build from a clean committed checkout on a trusted Docker host:

```bash
bash scripts/build-ci-php-images.sh
```

The helper resolves the PHP and Composer base tags to immutable registry
digests, records those references plus the exact source revision in OCI labels,
builds both PHP versions, and runs `argentwolf-verify-php-ci-image` inside each
finished image.

## Publish

Authenticate interactively to the Forgejo registry, then publish once:

```bash
docker login forgejo.argentwolf.org
bash scripts/build-ci-php-images.sh --push
```

The publish path also performs an anonymous pull check using an empty temporary
Docker client configuration and prints the resulting `RepoDigests`. Preserve
that output as qualification evidence. A separate small pinning patch should
then update consuming workflows to the reviewed digests.

## Updating

1. review the required PHP minors/extensions/tools;
2. update the Dockerfile or base tags;
3. increment the immutable `vN` suffix;
4. build and pass the in-image verifier;
5. publish once and verify anonymous pull access;
6. record the registry digest;
7. qualify representative consuming CI jobs against that exact digest; and
8. pin routine workflows to the digest.
