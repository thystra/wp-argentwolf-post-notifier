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
- Node 24 runtime for Forgejo JavaScript actions such as checkout;
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
forgejo.argentwolf.org/alan/wp-plugin-argentwolf-post-notifier/ci-php:8.4-bookworm-v2
forgejo.argentwolf.org/alan/wp-plugin-argentwolf-post-notifier/ci-php:8.5-bookworm-v2
```

Versioned tags are publish-once discovery names. Never overwrite one. Change the
`vN` suffix when the image definition changes. Routine CI must use the observed
registry `@sha256:...` digest after qualification, not the mutable tag.

The packages must remain anonymously pullable so any Forgejo repository can use
them without embedding registry credentials in normal CI jobs.

The published `bookworm-v1` images passed their PHP/toolchain self-tests but were
not promoted to routine Forgejo workflow authority: they omitted the Node runtime
required by JavaScript actions such as `actions/checkout`. `bookworm-v2` adds that
runner compatibility requirement without baking project dependencies into the
image.

## Qualified v2 identities

The first routine-CI-qualified shared images are recorded in
`ci/images/qualified-images.json`. Routine workflows pin the OCI index digest so
Docker selects the reviewed `linux/amd64` manifest while retaining the published
attestation manifest in the index.

```text
PHP 8.4 OCI index:   sha256:22dd9b45874452a5da42870b41f33b80d9af6c58c2f0aaa37f800619fc275e95
PHP 8.4 linux/amd64: sha256:42546274ac98968ce674838ad7f2009ac2eecd28465c64d34fe282f9bb504e6d
PHP 8.5 OCI index:   sha256:f0b19c7643297e618f50f02853a64305f87988ad0f03bcbb7b2489450c435ace
PHP 8.5 linux/amd64: sha256:207268bfdb8dd00b9eceb316ec714a2a50f981dbf3ac1c9cd24d799216d2ea7f
```

Do not change `qualified-images.json` or routine workflow image references until
a replacement image has been independently built, self-tested, published,
anonymously pulled, and its registry identity captured.

## Build and qualify

Build from a clean committed checkout on a trusted Docker host:

```bash
bash scripts/build-ci-php-images.sh
```

The helper resolves the PHP, Composer, and Node base tags to immutable registry
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
