<!-- ~/src/wp-argentwolf-post-notifier/CHANGELOG.md -->
# ArgentWolf Post Notifier Changelog

## 0.1.0-alpha.5 — Unreleased

- Begin the user-preference, named-list, and suppression milestone without
  changing frozen schema 1.
- Add typed registered-user notification preferences for `site_default`,
  `subscribed`, and `unsubscribed` using the canonical WordPress user-meta key.
- Treat missing or malformed stored preference metadata as `site_default` rather
  than as an implicit opt-in.
- Add a self-service WordPress profile control that accepts only defined
  preference states and cannot be used by an administrator to silently change
  another user's explicit preference.
- Keep registered-user verification and global suppression authoritative
  regardless of a stored preference.
- Add canonical global email suppression on the frozen schema-1 suppression table.
- Add secure standalone management bearers stored only by SHA-256 hash.
- Keep management GET requests display-only and require nonce-protected POST for
  unsubscribe and resubscribe actions.
- Allow verified self-service resubscription to remove only suppressions created
  by the same source; administrator suppression remains authoritative.
- Block public standalone signup and pending confirmation while an address is
  globally suppressed.

## 0.1.0-alpha.4 — 2026-09-26 development checkpoint

- Begin the standalone-subscriber domain foundation on frozen schema 1.
- Add typed pending, subscribed, unsubscribed, and suppressed subscriber states.
- Normalize public-signup email identity through the existing keyed identity service.
- Create or refresh pending subscriber records without reactivating subscribed,
  unsubscribed, or suppressed addresses.
- Generate 256-bit confirmation secrets, persist only SHA-256 token hashes, expire
  tokens after 24 hours, and enforce a 15-minute resend cooldown.
- Add explicit unexpired-token confirmation that promotes only pending records and
  clears the reusable confirmation hash after successful promotion.
- Add a generic public-signup coordinator that does not expose existing subscriber,
  cooldown, honeypot, rate-limit, or mail-submission state.
- Add transient local signup limits keyed by canonical email identity and HMACed
  network indicators without retaining raw IP addresses or user-agent strings.
- Add a transport-neutral mail message/result contract, WordPress `wp_mail()`
  transport, and standalone confirmation-message composition.
- Add a display-only confirmation-link page and a separate nonce-protected POST
  action so ordinary GET requests cannot promote pending subscribers.
- Add the dynamic `argentwolf-post-notifier/subscribe` block with configurable
  heading, description, consent text, and button label.
- Add a required email field, optional name, required consent checkbox, honeypot,
  signed consent/source context, and same-site post-redirect-get handling.
- Keep public signup responses non-enumerating when an address also belongs to a
  WordPress user, while still requiring explicit standalone double opt-in.
- Add daily cleanup for pending subscriber records whose confirmation tokens have
  been expired for seven days, limited to 250 rows per scheduled run.
- Add an administrator-only subscriber screen with status filtering, email/name
  search, one-way manual suppression, and nonce-protected CSV export.
- Keep CSV exports limited to 500-row database batches, omit bearer-token hashes,
  and neutralize spreadsheet-formula-looking cells.

## 0.1.0-alpha.3 — 2026-09-25 development checkpoint

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
- Freeze the reviewed schema-1 migration by source digest and exercise both
  tagged schema-zero releases as explicit upgrade origins before later schema
  changes move to a new numbered migration.
- Document the alpha-development/RC release lifecycle; alpha checkpoints no
  longer imply public prerelease publication.
- Normalize Forgejo CI around the qualified PHP 8.4/8.5 images, maintained
  WordPress patch releases, exact-package installation, pinned Plugin Check
  static/runtime gates, package byte-identity verification, and a notifier-
  specific `WP_DEBUG_LOG` gate.

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
