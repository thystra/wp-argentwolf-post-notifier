\
<!-- /home/alan/src/wp-argentwolf-post-notifier/AGENTS.md -->
# AGENTS.md

This file contains project-specific instructions for agents and maintainers
working on ArgentWolf Post Notifier. General cross-project preferences are
summarized here where they affect this repository; project-specific decisions
in this file take precedence for this project.

## Project identity

- Project: ArgentWolf Post Notifier
- Repository: `https://github.com/thystra/wp-argentwolf-post-notifier`
- Development host: `fafnir`
- Development checkout: `/home/alan/src/wp-argentwolf-post-notifier`
- Normal development user: `alan`
- Plugin slug: `argentwolf-post-notifier`
- Text domain: `argentwolf-post-notifier`
- PHP namespace: `ArgentWolf\PostNotifier`
- License: GPL-2.0-or-later
- Initial supported post type: `post`
- Intended WordPress baseline: WordPress 7.x, while maintaining the documented
  minimum version selected before the first release
- Companion project:
  `https://github.com/thystra/wp-argentwolf-email-verification`

The repository is currently a design and development scaffold. Do not describe
a feature as implemented, released, installed, or deployed until repository,
package, and production evidence support that statement.

## Communication and operator workflow

- Be helpful and conversational when discussing architecture, alternatives,
  risks, privacy, deliverability, and operational consequences.
- Clearly distinguish verified behavior, proposed design, assumptions, test
  results, package state, and production state.
- Before every command block, state the exact computer where it runs.
- Commands for this repository normally run on `fafnir`.
- Give complete, copy-pasteable commands.
- Always use the full path
  `/home/alan/src/wp-argentwolf-post-notifier` when identifying the checkout.
- Browser downloads belong in `/home/alan/Downloads/`.
- Never confuse a ChatGPT sandbox path such as `/mnt/data/...` with a path on
  `fafnir` or `nidhoggur`.
- Put backups outside the repository working tree, normally below
  `/home/alan/src/wp-argentwolf-post-notifier-backups/`.
- Preserve local work. Stop on an unexpected file, manifest, anchor, dirty
  worktree, or repository state rather than guessing.
- Prefer versioned applicator scripts for multi-file changes.
- Do not commit, push, tag, package, or deploy unless the requested workflow
  includes that operation and its output can be reviewed.

## Product invariants

These are correctness requirements, not optional implementation suggestions.

### Publication and campaign creation

- A notification is an explicit campaign associated with a post publication.
- Saving or updating a draft must not create a campaign.
- Scheduling a post changes it to `future`; that action must not create a
  campaign or send email.
- Editing a scheduled post while it remains `future` must not create a campaign.
- A scheduled post becomes eligible only when WordPress actually transitions
  it to `publish`.
- Campaign creation must occur after the post and its notification metadata are
  available.
- Ordinary edits to an already published post must not resend notifications.
- A post that is unpublished and republished must not silently create a second
  initial-publication campaign.
- Resending or announcing an update must require a separate explicit action.
- Campaign creation must be idempotent and protected by a database uniqueness
  constraint, not only by an in-memory flag or post-meta check.
- Preview and test-email actions must never create a campaign.

### Recipient eligibility

- Every registered WordPress user selected for a campaign must be checked for
  verified-email status before entering the send queue.
- Verification must be checked again immediately before sending in case the
  account becomes unverified, deleted, or otherwise ineligible after the
  audience snapshot.
- Registered-user delivery fails closed when no supported verification provider
  is available. Do not assume that an unknown status means verified.
- Standalone subscribers must complete double opt-in before becoming eligible.
- A pending standalone subscriber is not subscribed and must receive no post
  notification.
- Unsubscription and suppression override roles, named lists, explicit
  inclusion, and site defaults.
- Deduplicate the resolved audience by normalized email address.
- Send one message per recipient. Never reveal recipients through shared To,
  CC, or BCC fields.
- Do not allow unverified registered users to receive post notifications merely
  because another plugin causes `wp_mail()` to return success.

### Subscriber safety

- The public subscription block must use a generic response that does not reveal
  whether an email address belongs to a WordPress user or existing subscriber.
- Verification tokens, manage-subscription tokens, click tokens, and
  unsubscribe tokens must be generated with a cryptographically secure random
  source.
- Store token hashes rather than reusable plaintext tokens.
- Subscription confirmation must require an intentional confirmation action.
  A security scanner fetching a link must not silently subscribe an address.
- Public resend and signup requests require bounded rate limiting.
- Do not store raw IP addresses or user-agent strings by default.
- No arbitrary redirect destination may be accepted from a public click or
  unsubscribe request.

### Delivery and statistics

- Bulk email must not run synchronously inside the editor's publish request.
- Campaign creation freezes the message content, settings, and resolved
  recipient set used by that campaign.
- Queue claims and retries must be safe under concurrent workers.
- A successful `wp_mail()` call means only that WordPress accepted the message
  for processing. Label that state `submitted`, not `delivered`.
- Click counts are tracked or approximate because mail-security systems may
  inspect links.
- Do not add open-tracking pixels in the initial release.
- Bounce processing and confirmed inbox delivery are outside the transport-
  neutral initial release unless a mail-server or provider integration is
  explicitly added.

## Verification-plugin integration

Keep account verification and post notification as separate plugins.

The notifier owns double opt-in for its standalone subscriber records. The
companion verification plugin owns registered WordPress account activation and
verified status.

The preferred integration contract is a stable public function supplied by the
verification plugin, for example:

```php
wrav_ev_is_user_verified( int $user_id ): bool
```

The notifier should wrap external verification in its own adapter interface so
another provider can be added later. A filter may supplement the adapter, but
the default registered-user result must remain fail-closed when no provider can
authoritatively answer.

Do not rely on the verification plugin's `wp_mail()` suppression as the
eligibility check. Its current design intentionally treats pending-only mail as
handled successfully to prevent retry loops. Do not directly couple production
logic to private class methods. Any temporary compatibility read of private
user-meta keys must be isolated, documented, tested, and removed after the
public API is released.

Because the companion plugin is distributed from GitHub rather than currently
being a WordPress.org dependency, do not assume the `Requires Plugins` header
can install or resolve it correctly. Provide runtime health checks, clear admin
notices, and pre-publish eligibility warnings.

## Intended architecture

Read `/home/alan/src/wp-argentwolf-post-notifier/ARCHITECTURE.md` before making
structural changes. Update that document when changing:

- campaign lifecycle;
- scheduled-post behavior;
- subscriber state transitions;
- verification integration;
- database schema;
- queue claiming or retry behavior;
- unsubscribe or suppression semantics;
- privacy retention;
- public endpoint behavior; or
- supported post types.

Use `/home/alan/src/wp-argentwolf-post-notifier/TODO.md` as the active milestone
and task ledger. Mark work complete only after its acceptance criteria and tests
pass.

## Coding standards

- Follow WordPress Coding Standards for PHP, JavaScript, CSS, HTML, and
  documentation.
- Use strict, cohesive classes under `ArgentWolf\PostNotifier`; avoid a
  monolithic plugin file.
- Keep the main plugin bootstrap small.
- Sanitize input, validate domain objects, authorize with capabilities, and
  escape output at the point of rendering.
- REST routes require explicit permission callbacks. Public routes must expose
  only the minimum information needed.
- Use `$wpdb->prepare()` for dynamic SQL.
- Define schema changes through versioned migrations.
- Use UTC for stored timestamps and convert only for display.
- Normalize email addresses consistently in one service.
- Use an HMAC or keyed hash where deterministic email hashes are required.
- Avoid logging full recipient addresses or tokens.
- No external telemetry, tracking service, CAPTCHA service, or email API is
  enabled by default.
- Keep transport behind an interface even when the first implementation uses
  `wp_mail()`.
- Make public hooks and filters deliberate, documented, stable, and prefixed
  `awpn_`.

## Required tests

At minimum, maintain automated coverage for:

- activation, upgrade, and idempotent database migrations;
- draft-to-future scheduling without campaign creation;
- edits while a post remains future;
- future-to-publish campaign creation at actual publication;
- immediate draft-to-publish campaign creation;
- late WP-Cron publication;
- manual early publication of a scheduled post;
- published-post edits without resend;
- unpublish and republish without duplicate initial campaign;
- duplicate hook execution and concurrent campaign creation;
- verified, pending, unknown, deleted, and changed-email WordPress users;
- pending, confirmed, unsubscribed, and resubscribed standalone subscribers;
- duplicate email resolution across user, subscriber, role, and list sources;
- global suppression precedence;
- subscription and unsubscribe scanner-safe confirmation;
- token expiry, rotation, hashing, and rate limiting;
- queue leasing, retries, crash recovery, and concurrent workers;
- full, excerpt, More block, and Email Cutoff block rendering;
- HTML and plain-text templates;
- click redirect allowlisting and unique/total click counting;
- privacy export, erasure, retention, and uninstall behavior;
- multisite behavior if multisite support is declared; and
- classic editor fallback if that support is declared.

Run focused tests while developing and the full suite before a release.

## Validation and review

Before presenting a change as ready:

1. Inspect `git status`.
2. Run PHP syntax checks for all PHP files.
3. Run PHPCS.
4. Run PHPUnit.
5. Run JavaScript lint and tests when JavaScript exists.
6. Run block/editor end-to-end tests when editor behavior changes.
7. Run `git diff --check`.
8. Review schema migrations and uninstall behavior.
9. Build from a clean checkout or clean package staging directory.
10. Inspect the generated archive manifest and verify no development-only,
    secret, backup, or generated cache files are included.

Do not claim success until command output confirms it.

## Commit and release workflow

A normal review sequence on `fafnir` should include complete commands for:

```bash
cd /home/alan/src/wp-argentwolf-post-notifier
git status --short --branch
git diff --check
git diff
```

When Alan approves the change, include explicit commands for:

```bash
cd /home/alan/src/wp-argentwolf-post-notifier
git add <explicit-paths>
git commit -m "<descriptive message>"
git push origin main
```

For releases, also include packaging validation, checksums, and an annotated tag
only after the package version and tests are verified:

```bash
git tag -a vX.Y.Z -m "ArgentWolf Post Notifier vX.Y.Z"
git push origin vX.Y.Z
```

Do not use broad `git add .` in release instructions when explicit files can be
listed. Git publication does not establish that a package was installed or
activated on a WordPress site.

<!-- EOF: /home/alan/src/wp-argentwolf-post-notifier/AGENTS.md -->
