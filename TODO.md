\
<!-- /home/alan/src/wp-argentwolf-post-notifier/TODO.md -->
# ArgentWolf Post Notifier TODO

## How to use this file

- This is the active project milestone and task ledger.
- Mark an item complete only after implementation, tests, and review satisfy its
  acceptance criteria.
- Add newly discovered work under the appropriate milestone.
- Record architectural changes in `ARCHITECTURE.md`.
- Do not use completion marks to imply deployment to a WordPress site.
- Release versions below are planning targets and may be adjusted deliberately.

## Milestone 0 — Repository and design baseline

Target: documentation scaffold

- [x] Define product goals and initial release boundaries.
- [x] Decide that a post notification is an explicit campaign.
- [x] Decide that scheduled posts create campaigns only at actual publication.
- [x] Decide to keep registered-user email verification in the companion plugin.
- [x] Add `AGENTS.md`.
- [x] Add `ARCHITECTURE.md`.
- [x] Add `TODO.md`.
- [x] Expand `README.md`.
- [x] Add GPL-2.0 license text.
- [ ] Review the initial documentation commit on `fafnir`.
- [ ] Commit and push the documentation baseline.

Acceptance criteria:

- [ ] `git diff --check` passes.
- [ ] Repository status contains only the intended documentation files.
- [ ] Documentation consistently distinguishes design from implemented state.
- [ ] Scheduled-post and verification invariants are present in all relevant
      documents.

## Milestone 1 — Development skeleton and quality gates

Target: `0.1.0-alpha.1`

- [ ] Select and document the minimum supported WordPress version.
- [ ] Select and document the minimum supported PHP version.
- [ ] Add the main plugin bootstrap file.
- [ ] Add Composer autoloading.
- [ ] Add namespaced service registration.
- [ ] Add PHPCS with WordPress Coding Standards.
- [ ] Add PHPUnit and the WordPress test environment.
- [ ] Add JavaScript build tooling for editor and blocks.
- [ ] Add lint commands for PHP, JavaScript, CSS, and Markdown as appropriate.
- [ ] Add GitHub Actions for syntax, PHPCS, PHPUnit, and JavaScript tests.
- [ ] Add `.gitattributes`, `.gitignore`, and distribution exclusions.
- [ ] Add `readme.txt` for the WordPress plugin package.
- [ ] Add activation, deactivation, upgrade, and uninstall skeletons.
- [ ] Add centralized version constants and schema versioning.
- [ ] Add a development build command.
- [ ] Add a clean distribution-zip command.
- [ ] Add a package-manifest test.

Acceptance criteria:

- [ ] A clean checkout installs dependencies and runs all empty/skeleton suites.
- [ ] Plugin activates and deactivates without warnings on the selected minimum
      WordPress/PHP combination and WordPress 7.x.
- [ ] Distribution archive contains only expected files.
- [ ] No application feature is falsely described as complete.

## Milestone 2 — Verification-provider contract

Target: companion verification release plus notifier adapter

### Companion plugin work

Repository:
`https://github.com/thystra/wp-argentwolf-email-verification`

- [ ] Add a stable public function:
      `wrav_ev_is_user_verified( int $user_id ): bool`.
- [ ] Consider a status function returning `verified`, `pending`, or `unknown`.
- [ ] Preserve the current safety rule that missing legacy pending metadata does
      not lock out existing users.
- [ ] Document the public API.
- [ ] Add tests for verified, pending, missing-meta, deleted, and administrator
      users.
- [ ] Confirm the existing `wrav_ev_user_verified` action fires for all intended
      successful verification paths, including administrative verification if
      desired.
- [ ] Release and tag the companion API version.

### Notifier work

- [ ] Define `VerificationProvider`.
- [ ] Implement `WolfRavenEmailVerificationProvider`.
- [ ] Add provider detection and version/health reporting.
- [ ] Fail closed for registered-user delivery when no authoritative provider
      is available.
- [ ] Add editor and settings warnings for missing or obsolete provider APIs.
- [ ] Add a documented extension point for alternate verification providers.
- [ ] Ensure `wp_mail()` success is never used as proof of verification.
- [ ] Decide whether a temporary 0.2.0 private-meta compatibility adapter is
      necessary; omit it unless needed for migration.
- [ ] Add integration tests with the companion plugin.

Acceptance criteria:

- [ ] Every registered recipient is checked during audience resolution.
- [ ] Every registered recipient is rechecked before send.
- [ ] Pending and unknown registered users are skipped with distinct reasons.
- [ ] Campaign statistics do not report intentionally suppressed pending-user
      mail as submitted.

## Milestone 3 — Database schema and migrations

Target: `0.1.0-alpha.2`

- [ ] Implement versioned schema migrations.
- [ ] Create campaigns table.
- [ ] Create campaign recipients table.
- [ ] Create standalone subscribers table.
- [ ] Create named lists table.
- [ ] Create typed list-members table.
- [ ] Create global suppression table.
- [ ] Create click-events table.
- [ ] Add unique campaign-key constraint.
- [ ] Add unique campaign/email recipient constraint.
- [ ] Add normalized email and keyed email-hash service.
- [ ] Store timestamps in UTC.
- [ ] Add migration locking and idempotency.
- [ ] Add rollback/recovery documentation.
- [ ] Add bounded cleanup routines.
- [ ] Define uninstall choices: preserve data or remove data.

Acceptance criteria:

- [ ] Repeated activation and migration runs are harmless.
- [ ] Concurrent migration attempts do not corrupt schema.
- [ ] All required indexes exist.
- [ ] Upgrade tests pass from every released schema version.

## Milestone 4 — Standalone subscribers and mailing-list block

Target: `0.1.0-alpha.3`

- [ ] Register dynamic block:
      `argentwolf-post-notifier/subscribe`.
- [ ] Add configurable heading, description, consent text, and button label.
- [ ] Add required email field and optional name field.
- [ ] Add consent checkbox.
- [ ] Add honeypot and local rate limiting.
- [ ] Normalize and validate submitted email.
- [ ] Return generic non-enumerating responses.
- [ ] Create or refresh pending subscriber records.
- [ ] Generate secure confirmation tokens and store only hashes.
- [ ] Add confirmation-token expiry and resend cooldown.
- [ ] Send confirmation email through the transport abstraction.
- [ ] Make the confirmation link open a page that requires POST confirmation.
- [ ] Promote only intentionally confirmed records to `subscribed`.
- [ ] Add pending-record cleanup.
- [ ] Add a frontend success/error experience that works without JavaScript.
- [ ] Add accessible labels, focus handling, and status messages.
- [ ] Handle an email that already belongs to a WordPress user without
      revealing account existence.
- [ ] Add subscriber administration screen.
- [ ] Add search, filter, manual suppression, and export controls with
      capabilities.
- [ ] Add CSV import only as a later, separately approved double-opt-in flow;
      do not import directly into `subscribed`.

Acceptance criteria:

- [ ] An unconfirmed address never receives a post notification.
- [ ] A link scanner fetching the confirmation URL does not subscribe the
      address.
- [ ] Repeated signup does not reveal whether the address exists.
- [ ] Token expiry, rotation, cooldown, and rate-limit tests pass.
- [ ] Raw IP addresses and user-agent strings are not retained by default.

## Milestone 5 — User preferences, named lists, and suppression

Target: `0.1.0-alpha.4`

- [ ] Add registered-user notification preference:
      `site_default`, `subscribed`, `unsubscribed`.
- [ ] Add preference controls to user profile.
- [ ] Add secure self-service manage-subscription page.
- [ ] Implement named lists.
- [ ] Support typed list members: users and standalone subscribers.
- [ ] Implement explicit include and exclude contacts.
- [ ] Implement global email suppression.
- [ ] Ensure suppression overrides roles, lists, and explicit inclusion.
- [ ] Implement verified resubscribe flow.
- [ ] Implement duplicate-email merge rules.
- [ ] Record list and suppression audit events without logging sensitive tokens.
- [ ] Add capabilities for subscriber and list management.

Acceptance criteria:

- [ ] A suppressed email cannot re-enter through another source.
- [ ] One normalized email produces at most one campaign recipient.
- [ ] Registered and standalone records sharing an email are handled
      deterministically.
- [ ] Resubscription cannot occur accidentally through list administration.

## Milestone 6 — Editor workflow and post metadata

Target: `0.1.0-beta.1`

- [ ] Register authorized REST-visible post metadata.
- [ ] Add block-editor sidebar controls.
- [ ] Add native pre-publish confirmation panel.
- [ ] Add send, do-not-send, and site-default intent.
- [ ] Add role selector.
- [ ] Add named-list selector.
- [ ] Add individual user/subscriber include and exclude selectors.
- [ ] Add resolved audience estimate.
- [ ] Add content-mode selector.
- [ ] Add template selector.
- [ ] Add call-to-action override.
- [ ] Add preview-email action.
- [ ] Add send-test-email action.
- [ ] Add missing-verification-provider warning.
- [ ] Add empty-audience and invalid-template warnings.
- [ ] Add classic editor fallback or explicitly document that it is unsupported.
- [ ] Ensure autosaves and revisions do not alter campaign state.

Acceptance criteria:

- [ ] Editor state survives save, reload, schedule, and scheduled-post edits.
- [ ] Test and preview actions never create campaigns.
- [ ] Unauthorized users cannot alter notification metadata.
- [ ] Pre-publish summary matches saved configuration.

## Milestone 7 — Scheduled and immediate publication lifecycle

Target: `0.1.0-beta.2`

- [ ] Observe actual completed publication through the selected core hook path.
- [ ] Ignore draft, pending, private, trash, auto-draft, revision, and future
      saves.
- [ ] Create no campaign when a post is scheduled.
- [ ] Create no campaign when a scheduled post is edited.
- [ ] Create one initial campaign on `future -> publish`.
- [ ] Create one initial campaign on immediate non-publish -> publish.
- [ ] Add defensive GMT publication-time check.
- [ ] Add atomic unique initial campaign key.
- [ ] Prevent resend on ordinary published-post updates.
- [ ] Prevent duplicate initial campaign after unpublish/republish.
- [ ] Add explicit future update-campaign action only if included in the target
      release.
- [ ] Add diagnostic logging that does not expose recipient data.
- [ ] Add Site Health checks for overdue future posts and queue wake-ups.

Required tests:

- [ ] Draft save produces no campaign.
- [ ] Draft-to-future produces no campaign.
- [ ] Future edit produces no campaign.
- [ ] Schedule-date change produces no campaign.
- [ ] On-time future-to-publish produces one campaign.
- [ ] Late future-to-publish produces one campaign at actual publish.
- [ ] Manual early publish produces one campaign at actual publish.
- [ ] Immediate publish produces one campaign.
- [ ] Published update produces no campaign.
- [ ] Unpublish/republish produces no second initial campaign.
- [ ] Duplicate hook calls produce one campaign.
- [ ] Concurrent publication observers produce one campaign.
- [ ] WP-CLI/core publication path follows the same behavior.

Acceptance criteria:

- [ ] No scheduled-post email can be sent before actual publication.
- [ ] Campaign idempotency is enforced by the database.
- [ ] A missed WP-Cron run delays notification rather than sending early.

## Milestone 8 — Content cutoff, templates, and preview

Target: `0.1.0-beta.3`

- [ ] Register Email Cutoff block:
      `argentwolf-post-notifier/email-cutoff`.
- [ ] Render the cutoff block as no public output.
- [ ] Implement cutoff precedence:
      full, Email Cutoff, More block, manual excerpt, generated excerpt.
- [ ] Add configurable generated-excerpt length.
- [ ] Add safe block parsing and rendering.
- [ ] Define behavior for dynamic blocks, embeds, shortcodes, and unsupported
      blocks.
- [ ] Build responsive default HTML template.
- [ ] Build plain-text template.
- [ ] Add allow-listed template tokens.
- [ ] Require unsubscribe/manage token placement in templates or append a safe
      mandatory footer.
- [ ] Add live preview with a selected post.
- [ ] Add restore-default-template action.
- [ ] Add custom subject, heading, body, footer, and CTA settings.
- [ ] Sanitize and validate templates.
- [ ] Snapshot rendered campaign content.

Acceptance criteria:

- [ ] Every content mode has deterministic unit tests.
- [ ] Email output does not include editor-only cutoff markers.
- [ ] HTML and plain-text messages contain working local URLs.
- [ ] Templates cannot execute arbitrary PHP.

## Milestone 9 — Campaign audience resolver

Target: `0.1.0-beta.4`

- [ ] Expand selected roles.
- [ ] Expand named lists.
- [ ] Add explicit contacts.
- [ ] Apply exclusions.
- [ ] Normalize and deduplicate email.
- [ ] Check registered-user verification.
- [ ] Check standalone subscriber confirmation state.
- [ ] Apply user preference.
- [ ] Apply global suppression.
- [ ] Freeze recipient snapshots.
- [ ] Record aggregate skip reasons.
- [ ] Recheck hard eligibility immediately before send.
- [ ] Add filterable recipient eligibility extension point.
- [ ] Prevent any recipient enumeration through editor endpoints beyond the
      requesting user's capabilities.

Acceptance criteria:

- [ ] Audience counts and recipient rows agree.
- [ ] All skip reasons are testable and visible in aggregate statistics.
- [ ] No unverified or pending recipient enters the active send queue.
- [ ] No shared To/CC/BCC delivery path exists.

## Milestone 10 — Queue, worker, and mail transport

Target: `0.1.0-rc.1`

- [ ] Implement `MailTransport`.
- [ ] Implement `WpMailTransport`.
- [ ] Create bounded queue worker.
- [ ] Implement atomic claims and expiring leases.
- [ ] Implement bounded retries and exponential backoff.
- [ ] Implement crash recovery.
- [ ] Implement terminal failure state.
- [ ] Add WP-Cron wake-up.
- [ ] Add WP-CLI worker command for system cron.
- [ ] Add queue status and manual-run administration.
- [ ] Add test transport.
- [ ] Send one message per recipient.
- [ ] Add HTML and plain-text content types safely.
- [ ] Add visible unsubscribe and manage links.
- [ ] Add one-click unsubscribe headers.
- [ ] Label successful `wp_mail()` calls as `submitted`.
- [ ] Add hooks for transport results without exposing sensitive content.

Acceptance criteria:

- [ ] Publishing request does not synchronously send the campaign.
- [ ] Two workers cannot own the same unexpired recipient lease.
- [ ] Expired leases recover.
- [ ] Retry limits are enforced.
- [ ] Pending-user suppression cannot be counted as submitted.

## Milestone 11 — Unsubscribe and resubscribe

Target: `0.1.0-rc.2`

- [ ] Add visible unsubscribe management page.
- [ ] Require POST confirmation for visible-link unsubscribe.
- [ ] Add opaque token lookup.
- [ ] Add standardized one-click POST endpoint.
- [ ] Add `List-Unsubscribe` header.
- [ ] Add `List-Unsubscribe-Post` header.
- [ ] Apply global suppression.
- [ ] Add user-profile and standalone-subscriber manage links.
- [ ] Add verified resubscribe.
- [ ] Rotate management tokens after sensitive state changes.
- [ ] Add expiry where appropriate.
- [ ] Add scanner and replay tests.
- [ ] Add generic public responses.

Acceptance criteria:

- [ ] GET link inspection cannot unsubscribe an address.
- [ ] Valid standardized one-click POST unsubscribes.
- [ ] Unsubscribe suppresses all recipient-source paths.
- [ ] Replay and invalid-token requests do not leak state.

## Milestone 12 — Click tracking and campaign statistics

Target: `0.1.0-rc.3`

- [ ] Generate opaque recipient click tokens.
- [ ] Map tokens only to server-generated allowed destinations.
- [ ] Record total and unique clicks.
- [ ] Redirect safely to canonical post permalink.
- [ ] Add campaign dashboard.
- [ ] Show queued, claimed, submitted, failed, and skipped counts.
- [ ] Show skip reasons.
- [ ] Show unique clickers and total clicks.
- [ ] Label click-through as tracked/approximate.
- [ ] Add tracking-disabled direct-link mode.
- [ ] Add configurable click-event retention.
- [ ] Do not implement open tracking in the initial release.

Acceptance criteria:

- [ ] Public requests cannot create open redirects.
- [ ] Unique and total click calculations are correct.
- [ ] Security scanner clicks are documented as a limitation.
- [ ] No raw IP or user-agent retention occurs by default.

## Milestone 13 — Privacy, security, and administration

Target: `0.1.0-rc.4`

- [ ] Add privacy-policy helper text.
- [ ] Add personal-data exporter.
- [ ] Add personal-data eraser.
- [ ] Add campaign-recipient retention.
- [ ] Add click-event retention.
- [ ] Add expired pending-subscriber cleanup.
- [ ] Add deleted-user handling.
- [ ] Add deleted-post handling.
- [ ] Add uninstall settings and uninstall routine.
- [ ] Add administrator capability mapping.
- [ ] Add editor send capability without automatically exposing subscriber
      management.
- [ ] Add security review of every REST/admin/public endpoint.
- [ ] Add CSRF, authorization, enumeration, token, rate-limit, and redirect
      tests.
- [ ] Add Site Health diagnostics.
- [ ] Add structured, privacy-safe logs.

Acceptance criteria:

- [ ] Export and erasure tests cover users and standalone subscribers.
- [ ] Retention jobs are bounded and resumable.
- [ ] Plugin removal behavior is explicit and tested.
- [ ] No external telemetry or external verification API is active by default.

## Milestone 14 — Documentation, packaging, and first stable release

Target: `0.1.0`

- [ ] Complete installation and upgrade documentation.
- [ ] Document companion verification requirements.
- [ ] Document real system cron/WP-CLI worker option.
- [ ] Document SMTP and `wp_mail()` delivery limitations.
- [ ] Document scheduled-post behavior.
- [ ] Document subscription block and double opt-in.
- [ ] Document unsubscribe and privacy behavior.
- [ ] Document template tokens and cutoff precedence.
- [ ] Document capabilities and hooks.
- [ ] Add screenshots after the UI stabilizes.
- [ ] Complete changelog.
- [ ] Run full supported-version test matrix.
- [ ] Run editor end-to-end tests.
- [ ] Run accessibility review.
- [ ] Run security review.
- [ ] Build from a clean checkout.
- [ ] Inspect archive manifest.
- [ ] Verify package version.
- [ ] Generate SHA256 checksum.
- [ ] Install and test package on a staging WordPress site.
- [ ] Commit release.
- [ ] Push `main`.
- [ ] Create annotated `v0.1.0` tag.
- [ ] Push tag.
- [ ] Publish release archive and checksum.
- [ ] Separately validate any production deployment.

Acceptance criteria:

- [ ] All automated tests pass.
- [ ] `git diff --check` passes.
- [ ] Package manifest is clean.
- [ ] Staging validates immediate and scheduled publication.
- [ ] Staging validates registered-user verification and standalone double
      opt-in.
- [ ] Staging validates unsubscribe and click tracking.
- [ ] Release publication is not confused with production installation.

## Later candidates

These are not part of the first stable release unless reprioritized.

- [ ] Category or topic subscriptions.
- [ ] Daily or weekly digest campaigns.
- [ ] Explicit post-update campaigns.
- [ ] Custom post-type support.
- [ ] Subscriber CSV double-opt-in import.
- [ ] Bounce and complaint processing.
- [ ] Provider-specific mail transports.
- [ ] Mail-server delivery-status integration.
- [ ] Multisite support.
- [ ] WooCommerce audience adapters.
- [ ] Template library and reusable brand themes.
- [ ] Optional privacy-preserving aggregate analytics.
- [ ] Administrative campaign cancellation and restart policies.
- [ ] Webhook/API integrations only after authentication and privacy design.

<!-- EOF: /home/alan/src/wp-argentwolf-post-notifier/TODO.md -->
