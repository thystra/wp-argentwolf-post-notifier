<!-- ~/src/wp-argentwolf-post-notifier/CHANGELOG.md -->
# ArgentWolf Post Notifier Changelog

## 0.1.0-alpha.3 — Unreleased

- Begin the versioned database-migration foundation with schema version 1.
- Add campaigns, campaign recipients, standalone subscribers, named lists, typed
  list memberships, global suppressions, and click-event tables with required
  uniqueness and queue/search indexes.
- Add canonical email normalization and a persistent site-local keyed SHA-256
  email identity service.
- Serialize schema upgrades with a bounded MySQL advisory lock and advance the
  stored schema version only after each migration completes.
- Preserve plugin data on uninstall by default and remove plugin-owned tables and
  the keyed hash secret only when destructive uninstall is explicitly enabled.
- Add canonical UTC datetime persistence helpers and bounded cleanup primitives
  for expired pending subscribers, retained click events, and completed-campaign
  recipient identity.
- Add unique hashed-token lookup constraints needed by future confirmation,
  manage-subscription, click, and unsubscribe flows before schema 1 is frozen.
- Make completed-recipient identity fields redactable while retaining aggregate
  campaign state, with supporting cleanup indexes in provisional schema 1.
- Revalidate and repair recoverable current-schema table/index drift during
  activation and plugin-version upgrades; refuse automatic schema downgrades.
- Centralize destructive uninstall and add isolated qualification that removes
  and then reconstructs all plugin-owned persistence.
- Document the alpha-development/RC release lifecycle; alpha checkpoints no
  longer imply public prerelease publication.

## 0.1.0-alpha.2 — 2026-09-15

- Add a typed registered-user verification-provider contract.
- Integrate with the released ArgentWolf Email Verification 0.3.4 public API.
- Add provider version and health reporting with fail-closed eligibility.
- Add the alternate-provider filter and an administrator health warning.
- Add unit and companion-backed WordPress integration tests.
- Deliberately omit private companion metadata access and mail-success inference.
- Move repository and issue authority to Forgejo while retaining GitHub only as a mirror.
- Declare the now-resolvable WordPress.org dependency on ArgentWolf Email Verification.
- Test the minimum 0.3.4 verification API and current 1.0.2 companion release against
  WordPress 7.0.4 and 7.1.
- Make package version selection derive from the canonical PHP version constant and
  make packaging failures propagate to callers.
- Normalize archive timestamps from the source revision so repeated clean builds are
  reproducible.
- Align the milestone ledger with the alpha.2 verification-contract boundary and
  keep future audience-resolution and pre-send enforcement in their implementation
  milestones.

## 0.1.0-alpha.1 — 2026-07-29

- Add the initial plugin bootstrap and namespaced service container.
- Add activation, deactivation, upgrade, and uninstall skeletons.
- Select WordPress 7.0 and PHP 8.4 as the initial minimum versions.
- Add Composer, PHPUnit, WordPress Coding Standards, and JavaScript tooling.
- Resolve initial PHPCS line-length, docblock, comment, and PSR-4 filename-policy findings.
- Correct JavaScript formatting and SCSS lint targeting, require the supported npm floor,
  and make JavaScript CI use the committed lock file.
- Add continuous integration and deterministic distribution packaging.
- Correct the WordPress integration installer so prerequisite and download
  failures stop CI before PHPUnit runs with a missing test library.
- Leave the WordPress core destination absent until SVN exports core into it.
- Define the WordPress test-site constants required by the core bootstrap.
- Use PHPUnit 9.6, the supported runner for WordPress 7.0 integration tests.
- Do not declare the unresolved WordPress.org companion dependency.
- Do not implement campaign, subscriber, delivery, unsubscribe, or statistics
  behavior in this scaffold.

<!-- EOF: ~/src/wp-argentwolf-post-notifier/CHANGELOG.md -->
