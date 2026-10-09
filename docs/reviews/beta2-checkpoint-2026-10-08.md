# Beta.2 development checkpoint — release-readiness audit

Date: 2026-10-08
Target: `0.1.0-beta.2` (development checkpoint; **not** an RC or public release)

## Evidence and scope

This review uses the available AWPN working-tree snapshot, the merged-feature
patches and corrections provided in the project conversation, and the reported
Forgejo PR outcomes. The authoritative live Forgejo `main` branch could not be
read from the review environment. **Do not interpret this report as an audit of
an identified remote commit until the post-merge CI revision is recorded.**

Confirmed project reports: PR #37 concurrent reservation CI passed; PR #38
installed-package WP-CLI lifecycle CI passed and was merged; privacy-safe
reservation diagnostics and Site Health diagnostics were subsequently merged.
The Site Health Plugin Check `suppress_filters` finding was corrected before
that merge. Exact post-merge CI logs for the final `main` SHA were not provided
in this review.

## Code and safety boundary

- `PublicationObserver` watches `wp_after_insert_post`, requires an actual
  `publish` status and explicit `send` intent, rejects scheduled/nonpublished
  saves, and ignores ordinary published edits.
- `CampaignRepository` reserves `initial:{site_id}:{post_id}` with a database
  uniqueness guarantee, creating a `building` row with empty subject/HTML/text
  and no initial campaign recipients.
- Duplicate and concurrent observers resolve the same campaign rather than
  preparing or sending multiple messages.
- No post-notification queue/worker or recipient materialization path is
  implemented in Beta.2. Subscriber confirmation and CSV invitation mail
  **already exist** and are not publication campaign delivery.
- Diagnostics use closed event/reason codes and numeric IDs, not exception or
  recipient data. Site Health is read-only, identifies overdue opted-in future
  posts, and reports queue worker checks as not yet applicable.
- `Version::PLUGIN`, the plugin header, and `readme.txt` Stable Tag all identify
  `0.1.0-beta.2`; schema version remains `2` and schema 1 stays frozen.

## CI gates defined by source

- PHP syntax, PHPCS, PHPUnit, and dependency-free contracts on PHP 8.4 and 8.5.
- WordPress integration matrix for WordPress 7.0.6 and 7.1.2, with the supported
  Email Verification 1.0.2 companion and MySQL 8.4.
- JavaScript lint/test/build, CSS lint, Markdown lint, engine checks.
- Reproducible build and package checksum/manifest verification; installation
  of the exact ZIP and byte-for-byte installed package verification.
- Real multi-process initial-reservation contention; installed-package WP-CLI
  immediate/scheduled/cron/republish qualification.
- Pinned Plugin Check 2.1.0, static/new, runtime/new and runtime/update modes,
  plus notifier-scoped WordPress debug-log gate.

**CI matrix configuration is not the same as a verified green run against the
final `main` commit.** Capture the final run URL, commit SHA, and checksum
before tagging.

## Local review checks (reconstructed source)

- PHP syntax: pass.
- Dependency-free contracts: pass.
- Deterministic package construction: pass using an explicit
  `SOURCE_DATE_EPOCH`; package manifest and SHA256SUMS checks: pass.
- Composer PHPCS/PHPUnit and actual WordPress/MySQL runtime: **not run** in the
  review container; Forgejo is authoritative for those gates.

The audit identified and corrected stale Beta.1 status text in
`ARCHITECTURE.md`, inaccurate statements in package `readme.txt` that said no
campaign could be created, and the Site Health completion ledger/changelog
which still marked qualification pending.

## Decision and remaining gates

**Development milestone:** code and scoped qualification are sufficient to
close Beta.2 **conditional on** confirming the exact final `main` CI run is green.
No new schema migration or production publication behavior change is called for
by this audit. An annotated **source** tag is reasonable after verifying the
post-merge revision, working-tree cleanliness, and source/package identity.

**Do not publish** a Forgejo/GitHub Release object, WordPress.org package, or
production install on this evidence. The project explicitly reserves public
prereleases for RCs after the planned beta features and disposable-VM
acceptance work. Beta.3 should next cover content cutoff, templates, and preview
before recipient freezing and queue/delivery milestones.

## Operator closeout checklist

- [ ] Confirm final `main` commit SHA on Fafnir and no local changes.
- [ ] Record the green *post-merge `main`* CI run URL and all job statuses.
- [ ] Compare that run's exact package SHA256SUMS to the archived artifact.
- [ ] Verify post-merge plugin package manifest, version, and installed identity.
- [ ] Review/document any deviations from the source snapshot used here.
- [ ] If desired, create and push an **annotated source tag** for
      `v0.1.0-beta.2`; do not create a public Release object.

Completing the checklist does not substitute for future RC integration,
upgrade/recovery, accessibility, and operational acceptance testing.

Note for the later WordPress.org submission gate: the development `readme.txt`
is over the planned practical 10 KB limit and needs a focused directory-readme
edit before submission; this is not a Beta.2 source-checkpoint blocker.
