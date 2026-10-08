<!-- ~/src/wp-argentwolf-post-notifier/TODO.md -->
# ArgentWolf Post Notifier TODO

## How to use this file

- This is the active project milestone and task ledger.
- Mark an item complete only after implementation, tests, and review satisfy its
  acceptance criteria.
- Add newly discovered work under the appropriate milestone.
- Record architectural changes in `ARCHITECTURE.md`.
- Do not use completion marks to imply deployment to a WordPress site.
- Release versions below are planning targets and may be adjusted deliberately.
- Alpha versions are development milestones. Tag major checkpoints when useful,
  but do not automatically create Forgejo/GitHub Release objects for alpha
  completion.
- Begin public prerelease publication with the RC phase after the intended
  feature set is substantially complete and automated plus disposable-VM
  qualification has passed.

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
- [x] Review the initial documentation commit in the local development checkout.
- [x] Commit and push the documentation baseline.

Acceptance criteria:

- [x] `git diff --check` passes.
- [x] Repository status contains only the intended documentation files.
- [x] Documentation consistently distinguishes design from implemented state.
- [x] Scheduled-post and verification invariants are present in all relevant
      documents.

## Milestone 1 — Development skeleton and quality gates

Target: `0.1.0-alpha.1`

Implemented candidate scope: bootstrap, lifecycle handlers, service container,
dependency definitions, tests, CI, JavaScript tooling, and package validation.
Implementation and acceptance gates are complete for the development skeleton.

- [x] Select and document the minimum supported WordPress version: 7.0.
- [x] Select and document the minimum supported PHP version: 8.4.
- [x] Add the main plugin bootstrap file.
- [x] Add Composer autoloading.
- [x] Add namespaced service registration.
- [x] Add PHPCS with WordPress Coding Standards.
- [x] Add PHPUnit and the WordPress test environment.
- [x] Add JavaScript build tooling for editor and blocks.
- [x] Add lint commands for PHP, JavaScript, CSS, and Markdown as appropriate.
- [x] Add Forgejo Actions for syntax, PHPCS, PHPUnit, and JavaScript tests.
- [x] Add `.gitattributes`, `.gitignore`, and distribution exclusions.
- [x] Add `readme.txt` for the WordPress plugin package.
- [x] Add activation, deactivation, upgrade, and uninstall skeletons.
- [x] Add centralized version constants and schema versioning.
- [x] Add a development build command.
- [x] Add a clean distribution-zip command.
- [x] Add a package-manifest test.

Acceptance criteria:

- [x] A clean checkout installs dependencies and runs all empty/skeleton suites.
- [x] Plugin activates and deactivates without warnings on the selected minimum
      WordPress/PHP combination and WordPress 7.x.
- [x] Distribution archive contains only expected files.
- [x] No application feature is falsely described as complete.

## Milestone 2 — Verification-provider contract

Target: `0.1.0-alpha.2`

Companion API `v0.3.4` was established and tagged during development. The
supported WordPress.org companion floor is now 1.0.2; the notifier adapter uses
only the canonical public API and deliberately omits a private-meta adapter.


### Companion plugin work

Repository:
`https://forgejo.argentwolf.org/alan/wp-plugin-argentwolf-email-verification`

- [x] Standardize the companion display name on
      `ArgentWolf Email Verification`.
- [x] Standardize new public APIs on the
      `argentwolf_email_verification_` prefix; do not publish new `wrav_*` or
      `argent_*` APIs.
- [x] Add a stable public function:
      `argentwolf_email_verification_is_user_verified( int $user_id ): bool`.
- [x] Consider a status function returning `verified`, `pending`, or `unknown`.
- [x] Preserve the current safety rule that missing legacy pending metadata does
      not lock out existing users.
- [x] Document the public API.
- [x] Add tests for verified, pending, missing-meta, deleted, and administrator
      users.
- [x] Add and test the canonical
      `argentwolf_email_verification_user_verified` action for all intended
      successful verification paths, including administrative verification if
      desired.
- [x] Release and tag the companion API version.
- [x] Prepare the companion plugin for WordPress.org review.
- [x] Confirm the approved `argentwolf-email-verification` slug.
- [x] Publish the companion through WordPress.org before declaring it as a hard
      dependency of ArgentWolf Post Notifier.

### Notifier work

- [x] Define `VerificationProvider`.
- [x] Implement `ArgentWolfEmailVerificationProvider`.
- [x] Add provider detection and version/health reporting.
- [x] Fail closed for registered-user delivery when no authoritative provider
      is available.
- [x] Add an administrator warning for missing, obsolete, or failing provider APIs.
- [x] Add a documented extension point for alternate verification providers.
- [x] Ensure `wp_mail()` success is never used as proof of verification.
- [x] Decide whether a temporary 0.2.0 private-meta compatibility adapter is
      necessary; omit it unless needed for migration.
- [x] Add integration tests with the companion plugin.

Acceptance criteria:

- [x] The supported WordPress.org companion floor (1.0.2) is healthy across the
      supported WordPress integration matrix.
- [x] Missing, obsolete, malformed, and failing provider states resolve to
      `unknown` and fail closed.
- [x] Pending and unknown users retain distinct aggregate skip reasons
      (`unverified` and `verification_unknown`).
- [x] Invalid alternate-provider filter results fail closed.
- [x] Administrator health warnings are silent for a healthy provider and do
      not expose provider exception details.

Audience-resolution and pre-send enforcement are acceptance criteria of the
future audience and queue milestones, where those execution paths actually
exist.

## Milestone 3 — Database schema and migrations

Target: `0.1.0-alpha.3`

Implementation is split into reviewable tranches. The first tranche establishes
the migration engine, schema version 1, core table/index layout, keyed email
identity, and safe preserve-by-default uninstall behavior. Cleanup and
concurrency/recovery qualification remain separate acceptance work within this
milestone. Tranche 1 passed the Forgejo WordPress/MySQL matrix in CI 12. Tranche
2 adds UTC persistence helpers, bounded privacy/retention operations, same-schema
repair, explicit destructive-uninstall qualification, and released-schema upgrade
coverage before schema 1 is frozen. Tranche 3 freezes the reviewed schema-1
migration by source digest and makes later structural changes start a new numbered
migration instead of editing upgrade history. The schema-freeze and released
upgrade qualification passed the Forgejo WordPress/MySQL matrix in CI 23.

- [x] Implement versioned schema migrations.
- [x] Create campaigns table.
- [x] Create campaign recipients table.
- [x] Create standalone subscribers table.
- [x] Create named lists table.
- [x] Create typed list-members table.
- [x] Create global suppression table.
- [x] Create click-events table.
- [x] Add unique campaign-key constraint.
- [x] Add unique campaign/email recipient constraint.
- [x] Add normalized email and keyed email-hash service.
- [x] Store timestamps in UTC.
- [x] Add migration locking and idempotency.
- [x] Add rollback/recovery documentation.
- [x] Add bounded cleanup routines.
- [x] Define uninstall choices: preserve data or remove data.
- [x] Freeze schema 1 as immutable upgrade history after qualification.

Acceptance criteria:

- [x] Repeated activation and migration runs are harmless.
- [x] Concurrent migration attempts do not corrupt schema.
- [x] All required indexes exist.
- [x] Upgrade tests pass from every released schema version.

## Milestone 4 — Standalone subscribers and mailing-list block

Target: `0.1.0-alpha.4`

Implementation was split into six reviewable tranches: subscriber lifecycle and
persistence, privacy-preserving signup and confirmation mail, intentional POST
confirmation, the dynamic public block and no-JavaScript form, stale-pending
cleanup, and administrator subscriber management. All six tranches completed
Forgejo qualification, ending with CI 45 for subscriber administration.

- [x] Register dynamic block:
      `argentwolf-post-notifier/subscribe`.
- [x] Add configurable heading, description, consent text, and button label.
- [x] Add required email field and optional name field.
- [x] Add consent checkbox.
- [x] Add honeypot and local rate limiting.
- [x] Normalize and validate submitted email.
- [x] Return generic non-enumerating responses.
- [x] Create or refresh pending subscriber records.
- [x] Generate secure confirmation tokens and store only hashes.
- [x] Add confirmation-token expiry and resend cooldown.
- [x] Send confirmation email through the transport abstraction.
- [x] Make the confirmation link open a page that requires POST confirmation.
- [x] Promote only intentionally confirmed records to `subscribed`.
- [x] Add pending-record cleanup.
- [x] Add a frontend success/error experience that works without JavaScript.
- [x] Add accessible labels, focus handling, and status messages.
- [x] Handle an email that already belongs to a WordPress user without
      revealing account existence.
- [x] Add subscriber administration screen.
- [x] Add search, filter, manual suppression, and export controls with
      capabilities.

Acceptance criteria:

- [x] Only the `subscribed` standalone-subscriber state is notification-eligible.
      Milestone 9 must enforce that contract during audience resolution and
      immediately before send.
- [x] A link scanner fetching the confirmation URL does not subscribe the
      address.
- [x] Repeated signup does not reveal whether the address exists.
- [x] Token expiry, rotation, cooldown, and rate-limit tests pass.
- [x] Raw IP addresses and user-agent strings are not retained by default.

Actual audience resolution and send-queue enforcement remain in Milestone 9,
where those execution paths exist.

## Milestone 5 — User preferences, named lists, and suppression

Target: `0.1.0-alpha.5`

The Alpha.5 implementation is complete and was split into reviewable tranches.
Tranche 1 established the registered-user preference contract and self-service
WordPress profile control. Tranche 2 added shared global suppression and secure
standalone management. Tranche 3 added administrator-managed named lists with typed
WordPress-user and standalone-subscriber memberships. Tranche 4 added the reusable
audience-policy layer: role-expanded users, named lists, and explicit typed
include/exclude contacts are normalized, merged deterministically by email, filtered
by source eligibility, and finally subject to global suppression. Tranche 5 added
administrator-only, explicitly approved, bounded CSV intake that can create or refresh
only pending standalone subscribers and sends distinct double-opt-in invitations.
Imported status columns are ignored, and existing subscribed, unsubscribed, or
suppressed contacts are not reactivated. Tranche 6 added structured list and
suppression audit events using the first post-freeze migration, schema 2. Audit rows
retain stable event/entity IDs, actor IDs when authenticated, and keyed suppression
email hashes, but never raw email addresses or bearer tokens. Tranche 7 replaced the
temporary `manage_options` bridge with dedicated subscriber and list management
capabilities. Administrators receive both capabilities on activation/upgrade while
delegated roles may receive either capability independently. Membership remains
organizational only and never changes subscription, resubscription, verification,
preference, or suppression state. Missing or malformed registered-user preference
metadata uses the neutral `site_default` state.

- [x] Add registered-user notification preference:
      `site_default`, `subscribed`, `unsubscribed`.
- [x] Add preference controls to user profile.
- [x] Add secure self-service manage-subscription page.
- [x] Add CSV import only as a separately approved double-opt-in intake flow;
      never import contacts directly into `subscribed`.
- [x] Implement named lists.
- [x] Support typed list members: users and standalone subscribers.
- [x] Implement explicit include and exclude contacts.
- [x] Implement global email suppression.
- [x] Ensure suppression overrides roles, lists, and explicit inclusion.
- [x] Implement verified resubscribe flow.
- [x] Implement duplicate-email merge rules.
- [x] Record list and suppression audit events without logging sensitive tokens.
- [x] Add capabilities for subscriber and list management.

Acceptance criteria:

The Alpha.5 checks below are policy-layer guarantees. Milestone 9 must preserve them
when materializing and rechecking campaign recipients.

- [x] A suppressed email cannot re-enter through another source.
- [x] One normalized email produces at most one campaign recipient.
- [x] Registered and standalone records sharing an email are handled
      deterministically.
- [x] Resubscription cannot occur accidentally through list administration.

## Milestone 6 — Editor workflow and post metadata

Target: `0.1.0-beta.1`

Tranche 1 establishes the server-side editor contract before UI work. It
registers revision-aware post metadata for send intent, audience configuration,
content mode, template selection, and CTA override. REST exposure is edit-context
only, writes require both `edit_post` and `send_post_notifications`, and role/list/
explicit-contact selections are stored as canonical configuration rather than resolved
campaign recipients. The tranche also advances the capability layout so sending can
be delegated independently without granting subscriber or list administration.

Tranche 2 adds the first persistent block-editor sidebar. Authorized senders
can edit send intent, content mode, and the CTA override through the core editor store,
while the current canonical audience configuration is summarized read-only. The runtime
asset is loaded only on the post block editor for users with `send_post_notifications`.

Tranche 3 adds privacy-bounded WordPress-role and named-list selectors. The editor
bootstrap contains only role slug/label pairs and named-list ID/name pairs; list
membership, descriptions, counts, and subscriber records remain outside sender
authorization.

Tranche 4 adds individual WordPress-user and subscribed standalone-subscriber
include/exclude controls through an exact-email lookup contract. Lookup requires both
post-edit and send authorization, sends lookup inputs in authenticated POST bodies,
and returns only typed IDs, display labels, and masked addresses. Saved-ID hydration
is limited to IDs already stored on that post, so the endpoint is not a browsable or
sequential-ID contact directory.

Tranche 5 adds an aggregate-only resolved audience estimate for the current editor
configuration. An authorized sender requests the estimate explicitly; AWPN expands
selected WordPress roles through the reusable audience request builder and applies the
existing audience resolver for named lists, explicit contacts, exclusions, verification,
preferences, standalone subscription state, suppression, and deduplication. The endpoint
returns only eligible and aggregate skip counts, and neutral `site_default` registered-user
preferences remain fail-closed until a later site policy explicitly defines that default.

Tranche 6 adds sender-facing warnings for unavailable or unhealthy registered-user
verification and for a resolved audience with zero eligible recipients. The editor receives
only a Boolean provider-health flag; detailed diagnostics remain in the administrator
notice. Audience-resolver construction is deferred until estimate execution so provider
filters registered by later-loading plugins are available before verification policy is
resolved.

Tranche 7 adds a bounded editor-visible template catalog and selector without introducing
template persistence or rendering ahead of Milestone 8. Template ID `0` remains the
site-default sentinel; custom choices use positive integer IDs supplied through the
`argentwolf_post_notifier_editor_template_choices` filter. A saved nonzero template ID that
is no longer available remains intact in post metadata and produces an invalid-template
warning rather than being silently replaced.

Tranche 8 adds a native block-editor pre-publish confirmation panel. The panel summarizes
the current notification intent, privacy-safe audience selections, content mode, template,
and CTA override directly from the same edited post metadata used by the persistent sidebar.
It repeats provider-health and invalid-template warnings where publication is confirmed,
but does not resolve recipients automatically, render email, submit mail, or create campaign
state.

Tranche 9 qualifies the editor metadata lifecycle through core WordPress REST and revision
paths. Complete notification configuration survives save/reload, scheduling, and edits while
a post remains scheduled; ordinary revisions and autosaves preserve authorized metadata
without mutating campaign state. The qualification explicitly asserts that these editor
operations leave both campaign tables empty. Beta.1 intentionally supports the block editor
only; the Classic Editor has no AWPN notification-configuration UI.

Milestone 6 closes without a temporary preview or test-send implementation. Both actions are
explicitly deferred to Milestone 8 so they use the canonical content renderer, HTML/plain-text
templates, token handling, and message composition path rather than introducing a disposable
Beta.1 rendering path.

- [x] Register authorized REST-visible post metadata.
- [x] Add block-editor sidebar controls.
- [x] Add native pre-publish confirmation panel.
- [x] Add send, do-not-send, and site-default intent.
- [x] Add role selector.
- [x] Add named-list selector.
- [x] Add individual user/subscriber include and exclude selectors.
- [x] Add resolved audience estimate.
- [x] Add content-mode selector.
- [x] Add template selector.
- [x] Add call-to-action override.
- [x] Defer preview-email and send-test-email actions to Milestone 8.
- [x] Add missing-verification-provider warning.
- [x] Add empty-audience warning.
- [x] Add invalid-template warning.
- [x] Add classic editor fallback or explicitly document that it is unsupported.
- [x] Ensure autosaves and revisions do not alter campaign state.

Acceptance criteria:

- [x] Editor state survives save, reload, schedule, and scheduled-post edits.
- [x] Unauthorized users cannot alter notification metadata.
- [x] Pre-publish summary matches saved configuration.

## Milestone 7 — Scheduled and immediate publication lifecycle

Target: `0.1.0-beta.2`

Tranche 1 establishes the actual-publication observer and atomic initial-campaign reservation
primitive. AWPN observes `wp_after_insert_post` after the completed core save, fails closed
unless the saved post is an actual supported `publish` transition with explicit `send` intent
and a due GMT publication time, and atomically reserves a schema-1 `building` campaign using
`initial:{site_id}:{post_id}`. The building row intentionally contains no recipient rows and
only placeholder message bodies; rendering, audience freezing, queueing, and delivery remain
later milestones. Neutral `site_default` send intent remains fail-closed until an explicit
site-wide send policy exists.

Tranche 2 qualifies the remaining ordinary lifecycle paths and closes a core compatibility
edge case: direct `wp_publish_post()` can publish a draft while leaving WordPress's zero GMT
date intact. Publish status remains authoritative; the defensive GMT guard now applies only
when WordPress has a real stored GMT publication timestamp. Tests cover non-published saves,
scheduled edits and date changes, on-time publication, manual early publication, and the
direct core publish path. True concurrent-process qualification and WP-CLI-specific coverage
remain separate work.

Tranche 3 qualifies real multi-process MySQL contention against the installed
distribution in Forgejo CI (PR #37). Tranche 4 adds a separate WP-CLI lifecycle
gate against the same disposable installed package: draft configuration, immediate
and manual-early publish, published edit, unpublish/republish, scheduled edits, and due publication
through `wp cron event run`. Tranche 4 remains pending CI qualification.

- [x] Observe actual completed publication through the selected core hook path.
- [x] Ignore draft, pending, private, trash, auto-draft, revision, and future
      saves.
- [x] Create no campaign when a post is scheduled.
- [x] Create no campaign when a scheduled post is edited.
- [x] Create one initial campaign on `future -> publish`.
- [x] Create one initial campaign on immediate non-publish -> publish.
- [x] Add defensive GMT publication-time check.
- [x] Add atomic unique initial campaign key.
- [x] Prevent resend on ordinary published-post updates.
- [x] Prevent duplicate initial campaign after unpublish/republish.
- [ ] Add explicit future update-campaign action only if included in the target
      release.
- [ ] Add diagnostic logging that does not expose recipient data.
- [ ] Add Site Health checks for overdue future posts and queue wake-ups.

Required tests:

- [x] Draft save produces no campaign.
- [x] Draft-to-future produces no campaign.
- [x] Future edit produces no campaign.
- [x] Schedule-date change produces no campaign.
- [x] On-time future-to-publish produces one campaign.
- [x] Late future-to-publish produces one campaign at actual publish.
- [x] Manual early publish produces one campaign at actual publish.
- [x] Immediate publish produces one campaign.
- [x] Published update produces no campaign.
- [x] Unpublish/republish produces no second initial campaign.
- [x] Duplicate hook calls produce one campaign.
- [x] Concurrent publication observers produce one campaign.
      The disposable-site, cross-process InnoDB contention qualification passed
      Forgejo CI on PR #37 (reported 2026-10-08).
- [ ] WP-CLI/core publication path follows the same behavior.
      Direct core `wp_publish_post()` is qualified. An installed-package WP-CLI
      test now exercises separate `wp post` commands and `wp cron event run`;
      leave this open until the new CI gate passes.

Acceptance criteria:

- [ ] No scheduled-post email can be sent before actual publication.
- [x] Campaign idempotency is enforced by the database.
- [x] A missed WP-Cron run delays notification rather than sending early.

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
- [ ] Add preview-email action backed by the canonical rendered message.
- [ ] Add send-test-email action backed by the canonical renderer and mail transport.
- [ ] Add restore-default-template action.
- [ ] Add custom subject, heading, body, footer, and CTA settings.
- [ ] Sanitize and validate templates.
- [ ] Snapshot rendered campaign content.

Acceptance criteria:

- [ ] Every content mode has deterministic unit tests.
- [ ] Email output does not include editor-only cutoff markers.
- [ ] HTML and plain-text messages contain working local URLs.
- [ ] Templates cannot execute arbitrary PHP.
- [ ] Preview and test-send actions never create campaigns or campaign recipients.

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
- [ ] Every registered recipient is checked during audience resolution.
- [ ] Pending and unknown registered users are skipped with distinct reasons.
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
- [ ] Every registered recipient is rechecked immediately before send.
- [ ] Pending or unknown registered-user suppression cannot be counted as
      submitted.

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
- [ ] Publish the Forgejo release archive and checksum.
- [ ] Separately validate any production deployment.

Acceptance criteria:

- [ ] All automated tests pass.
- [ ] `git diff --check` passes.
- [ ] Package manifest is clean.
- [ ] Staging validates immediate and scheduled publication.
- [ ] Staging validates registered-user verification and standalone double
      opt-in.
- [ ] Staging validates unsubscribe and click tracking.
- [ ] Release publication is not confused with production installation or
      WordPress.org publication.

## Milestone 15 — WordPress.org submission and directory release

Target: after operational `0.1.0` validation

### Dependency readiness

- [x] Complete WordPress.org review and publication of ArgentWolf Email
      Verification.
- [x] Confirm its approved slug is exactly
      `argentwolf-email-verification`.
- [x] Add `Requires Plugins: argentwolf-email-verification` now that the
      dependency is resolvable through WordPress.org.
- [ ] Treat the notifier's own WordPress.org submission as a separate later
      release gate after operational validation.

### Naming and metadata

- [ ] Confirm the requested `argentwolf-post-notifier` slug is available.
- [ ] Use `ArgentWolf Post Notifier` in the main plugin header and
      `readme.txt`.
- [ ] Add a unique Plugin URI.
- [ ] Set accurate `Requires at least`, `Tested up to`, and `Requires PHP`
      values from the tested matrix.
- [ ] Keep the main plugin Version and `readme.txt` Stable Tag identical.
- [ ] Declare GPL-2.0-or-later consistently in headers, `readme.txt`, and
      `LICENSE`.
- [ ] Confirm the author and contributor WordPress.org accounts and contact
      information are current.

### Directory package review

- [ ] Create a concise WordPress.org-format `readme.txt`.
- [ ] Keep `readme.txt` below the practical 10 KB limit.
- [ ] Document subscriber data, click statistics, retention, uninstall behavior,
      and any external mail transport assumptions.
- [ ] Add directory icon, banner, and screenshot assets after UI stabilization.
- [ ] Ensure each screenshot entry matches an uploaded screenshot asset.
- [ ] Remove any Forgejo/GitHub custom update checker from the directory build.
- [ ] Verify no remote executable code, undisclosed telemetry, or unnecessary
      external requests exist.
- [ ] Use WordPress-provided libraries where available.
- [ ] Audit every bundled dependency, image, and asset for GPL compatibility.
- [ ] Include or link to human-readable source and reproducible build
      instructions for generated assets.
- [ ] Exclude tests, caches, backups, local environment files, full development
      dependency trees, and unrelated documentation from the installable ZIP.
- [ ] Retain source manifests such as `composer.json` when needed for
      open-source review.
- [ ] Verify the exact submission ZIP installs through **Plugins → Add New →
      Upload Plugin**.
- [ ] Verify the submission ZIP is below 10 MB.

### Automated and manual review

- [ ] Test with `WP_DEBUG` enabled.
- [ ] Run WordPress Plugin Check using the current WordPress.org review profile.
- [ ] Resolve every error and review each warning.
- [ ] Run PHPCS, PHPUnit, JavaScript tests, editor end-to-end tests,
      accessibility checks, and `git diff --check`.
- [ ] Review all capability checks, nonces, REST permission callbacks,
      sanitization, validation, escaping, SQL preparation, and direct-file
      access guards.
- [ ] Produce a WordPress.org submission-readiness report tied to the exact ZIP
      checksum.
- [ ] Do not describe the plugin as directory-compliant until this gate passes.

### Submission and release

- [ ] Whitelist `plugins@wordpress.org` for review correspondence.
- [ ] Upload the complete production-ready ZIP for review.
- [ ] Track and answer review feedback in the original review thread.
- [ ] After approval, initialize the WordPress.org SVN repository.
- [ ] Publish reviewed code to `trunk` and a matching stable version tag.
- [ ] Upload directory assets to the SVN `assets` directory.
- [ ] Confirm the WordPress.org release through the release-management
      workflow.
- [ ] Verify installation and updates from WordPress.org on a clean staging
      site.
- [ ] Use SVN only for reviewed releases, not day-to-day development commits.

Acceptance criteria:

- [ ] The dependency is resolvable or no longer required.
- [ ] Plugin Check and the full test matrix pass for the submitted ZIP.
- [ ] Plugin headers, Stable Tag, Git tag, package version, and SVN tag agree.
- [ ] WordPress.org approves the plugin.
- [ ] The directory package installs, activates, sends test notifications, and
      processes a scheduled publication on staging.

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

<!-- EOF: ~/src/wp-argentwolf-post-notifier/TODO.md -->
