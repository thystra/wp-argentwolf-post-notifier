<!-- ~/src/wp-argentwolf-post-notifier/ARCHITECTURE.md -->
# ArgentWolf Post Notifier Architecture

## 1. Purpose

ArgentWolf Post Notifier creates explicit email campaigns when WordPress posts
are published. Campaign audiences can include registered users selected by
role, named lists, individually included users, and verified standalone
subscribers who do not have WordPress accounts.

The plugin provides:

- editorial confirmation before publishing or scheduling;
- correct handling of immediate and scheduled publication;
- excerpt, More block, custom Email Cutoff block, and full-post email modes;
- customizable HTML and plain-text templates;
- asynchronous per-recipient delivery;
- verified-email enforcement;
- double-opt-in public subscriptions;
- unsubscribe and suppression handling;
- tracked click-through statistics; and
- privacy export, erasure, retention, and uninstall controls.

The plugin is not intended to be a general-purpose marketing automation,
customer-relationship-management, or bulk email-delivery platform.

## 2. Status

This document defines the agreed design. It does not claim that the described
components are implemented.

The initial repository contains only project scaffolding. Implementation is
tracked in `TODO.md`.

## 2.1 Canonical naming

The public product and vendor name is **ArgentWolf Post Notifier**. The project
must not be described as “Argent Post Notifier.”

Canonical identifiers are:

```text
Display name: ArgentWolf Post Notifier
Plugin slug: argentwolf-post-notifier
Text domain: argentwolf-post-notifier
PHP namespace: ArgentWolf\PostNotifier
Public API prefix: argentwolf_post_notifier_
Constant prefix: ARGENTWOLF_POST_NOTIFIER_
Block namespace: argentwolf-post-notifier
Custom-table prefix: argentwolf_pn_
```

The shorter custom-table prefix exists to keep SQL identifiers practical. It
does not authorize shortened public branding. Legacy `awpn_*`, `argent_*`, and
`wrav_*` identifiers must not be introduced into the new plugin APIs.

## 3. Core design decisions

### 3.1 A notification is a campaign

A post notification is represented by an immutable campaign created at actual
publication time. A campaign freezes:

- the originating post and publication revision;
- the campaign kind;
- the subject and message template;
- rendered HTML and plain-text content, or a reproducible content snapshot;
- content cutoff mode;
- call-to-action text;
- audience rules;
- the resolved and deduplicated recipient set; and
- creation and scheduling timestamps.

This model allows reliable retries, statistics, privacy operations, and future
explicit update campaigns without treating an editor save as an email-send
operation.

### 3.2 Verification remains a separate concern

Registered WordPress account verification remains in the companion project:

`https://github.com/thystra/wp-argentwolf-email-verification`

The notifier does not absorb account activation, login blocking, Application
Password blocking, or pending-account cleanup. Those responsibilities remain
with the verification plugin.

The notifier does own double opt-in for standalone subscriber records because
those records are not WordPress users.

This separation is preferred because:

- account verification protects more than post notifications;
- other plugins and WordPress core benefit from pending-user mail suppression;
- the notifier can evolve its mailing-list features without controlling login;
- account lifecycle and campaign lifecycle can be tested and released
  independently; and
- the integration boundary can later support another verification provider.

### 3.3 Registered-user delivery fails closed

Every registered user is checked through a verification adapter during audience
resolution and again before send.

The preferred companion API is:

```php
argentwolf_email_verification_is_user_verified( int $user_id ): bool
```

The companion plugin should also expose a status API when practical:

```php
argentwolf_email_verification_get_user_verification_status( int $user_id ): string
```

Expected status values are `verified`, `pending`, and `unknown`.

The notifier must not infer successful eligibility from `wp_mail()`. The
current companion plugin can intentionally suppress pending-only mail while
returning a handled-success result to prevent retry loops. That behavior is
appropriate for generic mail suppression but cannot produce accurate notifier
campaign statistics.

Until a supported verification API is available:

- role-based and explicit registered-user delivery is disabled or blocked;
- the editor and settings pages show a clear health warning;
- standalone verified subscribers may still be eligible; and
- production code does not silently treat unknown registered users as verified.

A temporary compatibility adapter that reads the companion plugin's private
user-meta marker may be used only if it is isolated, prominently documented,
covered by compatibility tests, and scheduled for removal.

### 3.4 Dependency and WordPress.org sequencing

During development, integration is discovered at runtime through the
verification adapter so either plugin can be developed and tested independently.

For WordPress.org distribution, the preferred final relationship is a formal
dependency on the separately published **ArgentWolf Email Verification**
plugin:

```text
Requires Plugins: argentwolf-email-verification
```

WordPress resolves `Requires Plugins` through WordPress.org-formatted slugs.
Therefore:

1. the companion plugin must use the canonical display name and slug;
2. its public API must use the
   `argentwolf_email_verification_...` prefix;
3. it must be accepted into the WordPress.org Plugin Directory first; and
4. the notifier must not be submitted with a hard dependency that WordPress.org
   cannot resolve.

If the companion plugin is not available from WordPress.org, the notifier may
continue development with runtime health checks, but WordPress.org submission is
blocked unless registered-user verification is made self-contained.

### 3.5 WordPress.org distribution architecture

GitHub is the development and issue-tracking repository. WordPress.org SVN is a
release repository and receives only reviewed release artifacts.

The release pipeline must produce a deterministic directory package that:

- is complete and operational;
- contains GPL-compatible code, data, images, and dependencies;
- uses WordPress core libraries rather than bundled replacements;
- contains no custom update checker or remote executable code;
- contains no undisclosed telemetry or external requests;
- excludes tests, caches, backups, local environment files, and unnecessary
  development dependencies;
- includes or links to human-readable source and build instructions for
  generated JavaScript and CSS;
- contains a valid `readme.txt` kept under the practical WordPress.org size
  limit;
- keeps the main plugin Version and readme Stable Tag synchronized;
- includes accurate privacy and third-party-service disclosures; and
- passes the current WordPress Plugin Check review profile.

The subscription and click-statistics features are local to the WordPress site.
They do not contact an ArgentWolf-operated service. Optional SMTP plugins remain
separate site-owner choices.

A WordPress.org submission-readiness report is a release artifact. Architecture
alignment alone is not proof that the finished plugin or submitted ZIP complies
with every directory guideline.

## 4. Major components

### 4.1 Bootstrap and service container

The main plugin file defines headers and loads a small bootstrap. The bootstrap
constructs services and registers hooks. Business logic remains in namespaced
classes.

Suggested top-level structure:

```text
argentwolf-post-notifier.php
src/
  Admin/
  Blocks/
  Campaign/
  Content/
  Database/
  Delivery/
  Editor/
  Privacy/
  Queue/
  Recipient/
  Rest/
  Subscriber/
  Unsubscribe/
  Verification/
assets/
blocks/
templates/
tests/
```

This structure may change before implementation, but responsibilities should
remain separated.

### 4.2 Editor integration

The block editor provides:

- a persistent post-notification settings sidebar;
- a native pre-publish confirmation panel;
- send, do-not-send, and site-default intent;
- audience summary and resolved-count preview;
- content mode;
- test email;
- message preview; and
- warnings for unavailable verification, empty audiences, or invalid templates.

A classic editor meta box may be provided as a compatibility feature.

Post meta stores editorial intent and configuration, not delivery state.
Suggested keys:

```text
_argentwolf_post_notifier_send_intent
_argentwolf_post_notifier_audience_config
_argentwolf_post_notifier_content_mode
_argentwolf_post_notifier_template_id
_argentwolf_post_notifier_cta_text
```

Registered post meta must have REST schemas, sanitization, authorization, and
appropriate defaults.

### 4.3 Public subscription block

Dynamic block:

```text
argentwolf-post-notifier/subscribe
```

The block may collect:

- required email address;
- optional display or first name;
- required consent checkbox and configurable consent text; and
- hidden anti-bot field.

Block attributes control presentation, not trusted subscription state.

The submission endpoint:

1. normalizes and validates the email;
2. returns a generic response regardless of existing state;
3. applies local rate limits by keyed email and ephemeral keyed network
   indicators;
4. creates or refreshes a pending subscriber;
5. rotates the confirmation token;
6. sends a confirmation message through the configured transport; and
7. records only the minimum consent and source metadata.

A confirmation-email link opens a local confirmation page. The page requires an
intentional POST confirmation before the subscriber becomes active. This
prevents link scanners from silently subscribing an address.

A verified standalone subscriber has completed double opt-in. There is no
separate state in which the email is verified but the subscription remains
unconfirmed.

Suggested states:

```text
pending
subscribed
unsubscribed
suppressed
```

`pending` records expire and are cleaned in bounded batches.

### 4.4 Registered users and standalone subscribers

Registered users remain in WordPress user tables. Their notification preference
is stored in user meta:

```text
_argentwolf_post_notifier_subscription_preference
```

Suggested values:

```text
site_default
subscribed
unsubscribed
```

Standalone subscribers are stored in a plugin table.

An email address may appear through multiple sources. Audience resolution
deduplicates by normalized email. A global suppression record prevents an
unsubscribed address from re-entering a campaign through a role, list, or
alternate source.

When an email belongs to both a WordPress user and a standalone subscriber:

- the resolved campaign has only one recipient;
- registered-user verification still applies to the user source;
- a global suppression overrides both records;
- explicit resubscription requires a verified management workflow; and
- merge/link behavior is logged without exposing account existence publicly.

### 4.5 Named lists

Named lists can contain:

- WordPress user references;
- standalone subscriber references; and
- future supported contact types through a typed membership interface.

Lists do not bypass verification or suppression. Removing a contact from a
named list does not erase the contact or revoke a global subscription.

### 4.6 Verification adapters

Internal interface:

```php
interface VerificationProvider {
    public function is_available(): bool;
    public function status_for_user( int $user_id ): VerificationStatus;
    public function description(): string;
}
```

Initial provider:

```text
ArgentWolfEmailVerificationProvider
```

Optional extension filter:

```text
argentwolf_post_notifier_verification_provider
```

The provider result is authoritative for registered-user eligibility. Unknown
is ineligible by default.

### 4.7 Content extraction and rendering

Content cutoff precedence:

1. explicit per-post full-content mode;
2. custom Email Cutoff block;
3. core More block when enabled;
4. manually entered WordPress excerpt;
5. generated excerpt using the configured length.

Custom block:

```text
argentwolf-post-notifier/email-cutoff
```

The block renders nothing on the public post and appears in the editor as a
divider indicating that the email ends at that location.

Content extraction uses the block parser rather than regular-expression
matching. Rendering must account for dynamic blocks, shortcodes, unsafe markup,
embedded media, and plain-text conversion.

The rendered email appends a configurable call to action that points to a local
tracking redirect or directly to the canonical post URL when tracking is
disabled.

Template tokens are allow-listed. Initial tokens:

```text
{site_name}
{site_url}
{post_title}
{post_url}
{post_excerpt}
{post_content}
{author_name}
{recipient_name}
{recipient_first_name}
{read_more_url}
{unsubscribe_url}
{manage_subscription_url}
```

Arbitrary PHP and unbounded shortcode execution are not supported in templates.

## 5. Publication lifecycle

### 5.1 Immediate publication

For a new or existing non-published post:

```text
draft/pending/private -> publish
```

The plugin observes the completed post save, verifies that:

- current status is `publish`;
- the previous status was not `publish`;
- notification intent resolves to send;
- the post type is supported;
- the publication time is not in the future;
- the post is not an autosave or revision;
- no initial campaign already exists; and
- configuration is valid.

It then atomically creates the initial campaign.

### 5.2 Scheduled publication

Scheduling produces:

```text
draft/pending -> future
```

At that time the plugin stores notification intent and configuration only.
It does not create a campaign, resolve recipients, queue email, or increment
statistics.

At the scheduled time, WordPress core publishes the post:

```text
future -> publish
```

WordPress's publication path invokes post-status transition hooks and then
`wp_after_insert_post`. The notifier creates the campaign only after that
actual `publish` state is observed.

Consequences:

- no email is sent when the editor merely schedules the post;
- edits while the post remains scheduled do not send;
- if WP-Cron runs late, the campaign is created when publication actually
  occurs, not at the missed scheduled timestamp;
- if an editor manually publishes early, the campaign is created at that real
  early publication;
- changing a scheduled time does not create a campaign; and
- a preview does not create a campaign.

An additional defensive check compares the stored GMT publication time against
current UTC with a small clock-skew allowance. A `publish` status remains the
primary authority.

### 5.3 Duplicate prevention

Initial campaign key:

```text
initial:{site_id}:{post_id}
```

The database enforces uniqueness. Hook re-entry, retries, two web requests, or
concurrent workers cannot create duplicate initial campaigns.

Republishing an old post does not create another initial campaign. A future
feature may create an explicit campaign kind such as:

```text
update:{site_id}:{post_id}:{campaign_uuid}
```

only through a deliberate editor or administrative action.

## 6. Campaign lifecycle

Suggested states:

```text
building
queued
sending
completed
completed_with_errors
cancelled
failed
```

Flow:

1. Insert `building` campaign using unique campaign key.
2. Freeze content and template configuration.
3. Resolve audience sources.
4. Apply verification, preferences, deduplication, and suppression.
5. Insert immutable recipient rows.
6. Transition campaign to `queued`.
7. Schedule a queue wake-up.
8. Workers claim bounded recipient batches.
9. Each recipient is rechecked for hard ineligibility.
10. Submit one message through the transport.
11. Record submitted, skipped, or failed result.
12. Complete the campaign when no actionable rows remain.

A campaign with zero eligible recipients completes as an empty campaign with
diagnostic counts rather than disappearing.

## 7. Audience resolution

Inputs:

```text
included roles
included named lists
included individual users
included individual subscribers
excluded individual users
excluded individual subscribers
site-wide defaults
```

Resolution order:

1. Expand roles and lists to typed contacts.
2. Add explicit contacts.
3. Apply explicit exclusions.
4. Normalize email addresses.
5. Deduplicate by normalized email.
6. Apply registered-user verification.
7. Require standalone subscriber status `subscribed`.
8. Apply user preference.
9. Apply global suppression.
10. Snapshot eligible recipients.
11. Record aggregate skip reasons.

Suggested skip reasons:

```text
unverified
verification_unknown
pending_subscription
unsubscribed
suppressed
invalid_email
duplicate
deleted
excluded
no_email
```

## 8. Database design

Table prefixes use `$wpdb->prefix`.

### 8.1 `argentwolf_pn_campaigns`

Representative fields:

```text
id
uuid
campaign_key              UNIQUE
campaign_kind
post_id
post_modified_gmt_snapshot
status
subject
html_body
text_body
template_snapshot_json
audience_snapshot_json
content_mode
created_at_gmt
queued_at_gmt
started_at_gmt
completed_at_gmt
cancelled_at_gmt
recipient_count
submitted_count
failed_count
skipped_count
unique_click_count
total_click_count
last_error_code
last_error_message
```

Large bodies may be split into a campaign-content table if measurement shows a
need.

### 8.2 `argentwolf_pn_campaign_recipients`

Representative fields:

```text
id
campaign_id
recipient_uuid
recipient_type            user|subscriber
user_id                   nullable
subscriber_id             nullable
email_snapshot
email_hash
display_name_snapshot
status                    queued|claimed|submitted|failed|skipped
skip_reason
attempt_count
next_attempt_at_gmt
claimed_at_gmt
lease_expires_at_gmt
submitted_at_gmt
failed_at_gmt
first_clicked_at_gmt
last_clicked_at_gmt
click_count
last_error_code
last_error_message
UNIQUE campaign_id,email_hash
```

The email snapshot is needed so a frozen campaign does not silently change
destination when a profile is edited after campaign creation. Privacy erasure
and retention rules control its lifetime.

### 8.3 `argentwolf_pn_subscribers`

Representative fields:

```text
id
uuid
email
email_hash                UNIQUE
display_name
status
confirmation_token_hash
confirmation_expires_at_gmt
manage_token_hash
created_at_gmt
confirmed_at_gmt
unsubscribed_at_gmt
last_confirmation_sent_at_gmt
consent_text_snapshot
signup_source
source_post_id
updated_at_gmt
```

Do not store raw confirmation or management tokens.

### 8.4 `argentwolf_pn_lists`

Representative fields:

```text
id
uuid
name
description
created_by
created_at_gmt
updated_at_gmt
```

### 8.5 `argentwolf_pn_list_members`

Representative fields:

```text
id
list_id
member_type               user|subscriber
user_id                   nullable
subscriber_id             nullable
created_at_gmt
UNIQUE typed membership
```

### 8.6 `argentwolf_pn_suppressions`

Representative fields:

```text
id
email_hash                UNIQUE
email_snapshot_or_redacted
reason
source
created_at_gmt
updated_at_gmt
```

Whether the suppression table retains a recoverable email is a settings and
privacy decision. At minimum, a deterministic keyed hash must prevent
re-importing an unsubscribed address without an explicit verified resubscribe
workflow.

### 8.7 `argentwolf_pn_clicks`

Representative fields:

```text
id
campaign_recipient_id
clicked_at_gmt
destination_kind
```

Do not store raw IP addresses or user-agent strings by default. Per-recipient
summary columns support fast statistics; event rows support total and timeline
counts.

## 9. Queue and worker design

The initial implementation uses plugin-owned queue tables and WP-Cron, with
WP-CLI support for reliable system-cron invocation.

WP-Cron is a wake-up mechanism, not the queue itself.

Workers:

- claim a bounded batch atomically;
- assign a lease expiration;
- do not hold a database transaction while sending mail;
- recheck eligibility and suppression;
- submit one message per recipient;
- update status and counters;
- retry transient failures with bounded exponential backoff;
- stop after a configurable maximum attempt count; and
- recover rows whose worker lease expired.

Suggested default batch size is conservative and filterable.

Production installations may call a WP-CLI worker from system cron. No queue
feature may depend solely on site traffic.

## 10. Delivery transport

Transport interface:

```php
interface MailTransport {
    public function send( Message $message ): DeliveryResult;
}
```

Initial transport:

```text
WpMailTransport
```

Every message has one primary recipient and contains:

- HTML body;
- plain-text alternative when supported by the transport layer;
- visible unsubscribe link;
- manage-subscription link;
- `List-Unsubscribe` header;
- `List-Unsubscribe-Post` header where supported;
- campaign and recipient correlation identifiers that do not expose database
  IDs; and
- tracked or direct post URL.

`submitted` means `wp_mail()` accepted the request. It does not mean delivered
to an inbox.

## 11. Unsubscribe and resubscribe

### 11.1 Visible unsubscribe

The visible email link opens a local management page. A GET request displays the
requested action but does not mutate subscription state. The user confirms with
a POST.

This prevents automated link inspection from causing accidental unsubscribe.

### 11.2 One-click unsubscribe headers

A separate HTTPS POST endpoint implements standardized one-click unsubscribe
behavior for compatible mail clients. It accepts only the defined POST action
and an opaque token.

### 11.3 Global effect

Unsubscribe creates or updates global suppression for the normalized email.
That suppression overrides:

- registered-user role inclusion;
- named lists;
- explicit inclusion;
- standalone subscriber status; and
- site defaults.

### 11.4 Resubscribe

Resubscription requires a verified management flow. It cannot be performed by
an administrator merely re-adding the address to a list without an explicit
override workflow and audit record.

## 12. Click tracking

Tracked links contain an opaque random token mapped to:

- campaign recipient;
- allowed destination kind; and
- canonical destination generated by the server.

The public request cannot provide an arbitrary destination URL.

The endpoint records the click and performs a safe local redirect to the
canonical post permalink.

Statistics include:

```text
eligible recipients
submitted
failed
skipped by reason
unique clickers
total clicks
first click
most recent click
tracked click-through rate
```

Click statistics are approximate because security systems may inspect links.
Open tracking is out of scope for the initial release.

## 13. Capabilities

Suggested capabilities:

```text
manage_post_notifications
send_post_notifications
view_post_notification_stats
manage_post_notification_subscribers
manage_post_notification_lists
```

Activation grants administrative capabilities to administrators. Editor
capabilities are an explicit site decision. Sending does not imply permission
to view all subscriber data or edit global templates.

## 14. Privacy and retention

The plugin supplies:

- privacy-policy helper text;
- personal-data exporter;
- personal-data eraser;
- configurable retention for completed campaign recipient details;
- configurable retention for click events;
- cleanup for expired pending subscribers and tokens;
- user-deletion and post-deletion handling;
- documented uninstall choices; and
- no external telemetry by default.

Aggregate campaign counts may remain after recipient-level data is erased if
they can no longer identify a person.

## 15. Security controls

- Capability checks for all administrative actions.
- Nonces for authenticated state-changing actions.
- Intentional POST confirmation for public subscription management.
- Generic public responses to prevent address enumeration.
- Secure random tokens with hashed storage and expiry.
- Token rotation after resend or state change.
- Rate limits for subscribe, resend, confirmation, and management requests.
- Prepared SQL and centralized schema repositories.
- Output escaping at render time.
- Allow-listed template tokens.
- Safe local redirects.
- No shared-recipient mail.
- No raw secrets or full tokens in logs.
- No raw IP retention by default.
- Database uniqueness for campaign and recipient idempotency.

## 16. Scheduled-publication acceptance tests

The release test suite must prove:

1. Draft saved: no campaign.
2. Draft scheduled as future: no campaign.
3. Scheduled post edited: no campaign.
4. Scheduled date changed: no campaign.
5. WP-Cron publishes at due time: one initial campaign.
6. WP-Cron publishes late: one initial campaign at actual publish.
7. Editor manually publishes early: one initial campaign at actual publish.
8. Immediate draft-to-publish: one initial campaign.
9. Published post edited: no new campaign.
10. Published post moved to draft and republished: no second initial campaign.
11. Duplicate `wp_after_insert_post` invocation: no duplicate campaign.
12. Two concurrent publication observers: no duplicate campaign.
13. Notification intent `do_not_send`: no campaign.
14. Empty or fully ineligible audience: campaign completes with zero eligible
    recipients and diagnostic skip counts.
15. Scheduled publication through WP-CLI or another valid core path follows the
    same rules.

## 17. Initial non-goals

Deferred unless separately approved:

- arbitrary unverified imported email lists;
- marketing automation sequences;
- category/topic preference centers;
- daily or weekly digests;
- open tracking;
- remote telemetry;
- provider-specific bounce webhooks;
- confirmed inbox-delivery claims;
- external email validation APIs;
- paid mailing-provider integration;
- WooCommerce customer segmentation;
- network-wide multisite campaigns; and
- automatic notification on ordinary post updates.

## 18. WordPress.org release model

The Git repository remains the authoritative development history. A GitHub tag
and release archive are not, by themselves, a WordPress.org release.

Before initial submission:

1. verify the requested slug is still available;
2. finish and test the complete plugin;
3. prepare the main plugin header and `readme.txt`;
4. run the supported WordPress/PHP matrix with `WP_DEBUG` enabled;
5. run WordPress Plugin Check using the current review checks;
6. audit GPL compatibility and third-party notices;
7. inspect the exact submission ZIP and keep it below WordPress.org's upload
   limit;
8. ensure the companion dependency is available from WordPress.org if declared;
   and
9. produce a written submission-readiness report.

After approval, publish stable releases through WordPress.org SVN using matching
version tags. Do not use SVN trunk as a development branch or push noisy
intermediate commits.

## 19. Relevant WordPress behavior

The implementation should be checked against current official WordPress
documentation before coding or changing hooks.

Key references:

- `transition_post_status`:
  `https://developer.wordpress.org/reference/hooks/transition_post_status/`
- `wp_after_insert_post`:
  `https://developer.wordpress.org/reference/hooks/wp_after_insert_post/`
- `wp_publish_post`:
  `https://developer.wordpress.org/reference/functions/wp_publish_post/`
- Plugin headers and dependencies:
  `https://developer.wordpress.org/plugins/plugin-basics/header-requirements/`
- Detailed Plugin Guidelines:
  `https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/`
- Common review issues:
  `https://developer.wordpress.org/plugins/wordpress-org/common-issues/`
- WordPress.org `readme.txt` format:
  `https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/`
- WordPress.org submission and maintenance:
  `https://developer.wordpress.org/plugins/wordpress-org/planning-submitting-and-maintaining-plugins/`
- WordPress Cron:
  `https://developer.wordpress.org/plugins/cron/`
- Plugin privacy:
  `https://developer.wordpress.org/plugins/privacy/`

<!-- EOF: ~/src/wp-argentwolf-post-notifier/ARCHITECTURE.md -->
