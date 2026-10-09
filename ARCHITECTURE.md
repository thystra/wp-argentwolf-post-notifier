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

The repository has completed the implementation tranches planned for
`0.1.0-beta.2`. Alpha.2 established the verification-provider contract; alpha.3
froze the persistent data foundation; alpha.4 and alpha.5 added standalone
double opt-in, administration, registered-user preferences, suppression, named
lists, bounded CSV intake, audience resolution, and administrative audit events.
Beta.1 added revision-aware editorial notification metadata, a capability-gated
block-editor workflow, aggregate audience estimates, and pre-publish confirmation.

Beta.2 observes completed WordPress publication saves and atomically reserves a
single `building` campaign for eligible posts with explicit `send` intent. Draft
and scheduled edits create no campaign; ordinary published edits and
unpublish/republish cannot reserve a second initial campaign. Cross-process
contention and WP-CLI publication paths have dedicated CI qualification.
Privacy-safe reservation diagnostics and read-only publication Site Health checks
are present. These campaign rows have no recipients or rendered message bodies:
content rendering, recipient freezing, queue processing, and post-notification
delivery remain future milestones. The beta checkpoint is not a public release.

Beta.3 tranche 1 registers the editor-only Email Cutoff marker block. Its
public render callback always returns the empty string. Tranche 2 adds a
selection-only service: it returns a `source`, `format`, and `content` without
rendering dynamic blocks, shortcodes, or messages, or making campaign writes.
Email templates, previews, snapshots, and delivery remain unimplemented.

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

`https://forgejo.argentwolf.org/alan/wp-plugin-argentwolf-email-verification`

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

For WordPress.org distribution, the notifier has a formal dependency on the
separately published **ArgentWolf Email Verification** plugin:

```text
Requires Plugins: argentwolf-email-verification
```

The companion is now published in the WordPress.org Plugin Directory under that
slug. Its public API uses the `argentwolf_email_verification_...` prefix, so the
dependency is resolvable through normal WordPress plugin-dependency handling.
Runtime health checks remain mandatory because installation metadata cannot prove
that a loaded provider is current, callable, or operational.

### 3.5 WordPress.org distribution architecture

Forgejo is the authoritative development and issue-tracking repository. Any
GitHub repository is a downstream mirror. WordPress.org SVN is a directory release
repository and receives only reviewed release artifacts.

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

The Beta.1 metadata contract registers these keys only for `post`. REST schemas
expose them in `edit` context, not public `view` responses. Every normal metadata write
requires both `edit_post` for the target post and `send_post_notifications`. Metadata
opts into core revision support. When WordPress creates an autosave revision, the
notification metadata is stored on that revision. Core may instead update an unlocked
draft owned by the current author in place; AWPN treats either autosave path as
editorial state only and never as campaign state. Because core autosave/revision helpers
can write revision metadata without consulting registered-meta auth callbacks, AWPN
also removes its revision keys for unauthorized users and rejects unauthorized REST
autosave metadata before the route callback runs.

`send_intent` accepts `site_default`, `send`, or `do_not_send`. `audience_config` stores
canonical role slugs, named-list IDs, and typed user/subscriber include/exclude IDs; it
does not persist a resolved audience. `content_mode` accepts `site_default`, `excerpt`,
or `full`; the later rendering milestone defines the site-default cutoff precedence.
Template ID `0` means site default, and CTA text is an optional bounded plain-text
override.

Beta.1 tranche 2 adds a capability-gated `PluginSidebar` to the post
block editor. The sidebar reads unsaved metadata through the `core/editor` data store
and updates it with `editPost`, so ordinary editor save/autosave behavior remains the
only persistence path. It exposes send intent, content mode, CTA override, and the
saved audience configuration without resolving recipients or creating campaigns. The
runtime asset is enqueued only for the `post` editor when the current user has
`send_post_notifications`.

Tranche 3 adds role and named-list audience-source controls. The editor bootstrap
contains only WordPress role slug/label pairs and named-list ID/name pairs; list
membership, descriptions, counts, and subscriber records are not disclosed merely
because a user can send notifications.

Tranche 4 adds typed individual include/exclude controls. Contact lookup requires
`edit_post` for the target post plus `send_post_notifications`, accepts only exact email
lookup rather than browse or fuzzy search, and transmits lookup inputs in authenticated
POST bodies so raw addresses are not placed in request URLs. Responses contain typed
entity IDs, display labels, and masked addresses. Saved-ID hydration is bounded and may
return only IDs already present in that post's canonical audience configuration. The
lookup does not change preferences, subscription state, suppression, or campaign state.

Tranche 5 adds an explicit aggregate-only resolved audience estimate. A reusable
`AudienceRequestBuilder` converts the canonical editor configuration into the existing
`AudienceResolutionRequest`, including deterministic expansion of selected WordPress
roles. The editor estimate endpoint then applies `AudienceResolver` and returns only the
eligible-recipient count plus stable aggregate skip counts. It does not serialize resolved
recipient objects, email addresses, keyed email hashes, user IDs, subscriber IDs, or list
membership. The request uses the current unsaved editor configuration, so changing an
audience selection invalidates the displayed estimate until the sender explicitly refreshes
it. Registered users in the neutral `site_default` preference remain fail-closed until a
later site-level policy explicitly defines that default.

Tranche 6 adds advisory editor warnings without changing delivery or campaign state.
Authorized senders see a non-dismissible warning when registered-user verification is
unavailable or unhealthy, but the editor bootstrap exposes only a Boolean health flag;
provider descriptions, versions, error codes, and detailed diagnostics remain confined to
the administrator notice. After an explicit audience estimate, a zero eligible-recipient
result produces a separate warning based solely on the aggregate count.

Verification-provider and audience-resolver construction used by editor integration is
deferred until editor enqueue or estimate execution. This ensures alternate-provider
filters registered by later-loading plugins are available before health or recipient
eligibility is evaluated.

Tranche 7 adds template selection without implementing the Milestone 8 template system.
`EditorTemplateCatalog` always exposes ID `0` as the site-default sentinel and accepts
only bounded, sanitized positive-ID custom choices from the
`argentwolf_post_notifier_editor_template_choices` filter. The editor persists only the
existing `template_id` post metadata. If a previously saved nonzero ID is absent from the
current catalog, the selector preserves that value as unavailable and shows an advisory
warning until the sender chooses Site default or another available template. Beta.1 does
not resolve, render, preview, or snapshot templates.

Tranche 8 adds a native `PluginPrePublishPanel` alongside the persistent sidebar. It reads
the same current edited post metadata and presents a publication-time summary of notification
intent, privacy-safe audience selection counts, content mode, template selection, and CTA
override. Provider-health and invalid-template warnings are repeated in the pre-publish
surface. The panel is informational only: it does not resolve recipients, render email,
submit mail, or create campaign state.

Tranche 9 qualifies the editorial lifecycle rather than adding another production path.
Integration tests exercise complete notification metadata through the core post REST
controller for ordinary save/reload, draft-to-future scheduling, edits while the post
remains scheduled, ordinary revisions, and autosaves. Each path also asserts that no
campaign or campaign-recipient rows are created. The pre-publish panel reads the same edited
metadata contract whose persistence is qualified by these tests.

Beta.1 editor integration is block-editor-only. AWPN does not enqueue its editor runtime or
provide notification-configuration controls in the Classic Editor. The protected post-meta
contract remains server-side data, but Classic Editor users must not be presented as having
an AWPN notification workflow.

Preview and test-send actions are intentionally deferred to Milestone 8. They must consume
the same canonical post-content renderer, validated HTML/plain-text templates, token
handling, and composed message contract used by real notification content. Preview and test
send must remain non-campaign operations and must not create campaign or recipient rows.

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

Block attributes control presentation, not trusted subscription state. The rendered
consent/source context is authenticated by the server before it is accepted back
from a public form submission. Return destinations are restricted to local URLs.

The public form follows post-redirect-get and exposes one generic accepted-request
message regardless of whether the address belongs to a WordPress user, already
exists as a standalone subscriber, is cooling down, or is rate limited. Structural
form failures use a separate generic retry message without disclosing account or
subscriber existence. The form remains fully usable without frontend JavaScript.

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

The subscriber domain service never returns its internal signup outcome directly
to a public requester. It may expose a plaintext confirmation token only to the
mail layer when the resend cooldown permits a message; the database stores only
the SHA-256 token hash. Repeated signup of an already subscribed, unsubscribed,
or suppressed address does not move that record back to `pending`.

Suggested states:

```text
pending
subscribed
unsubscribed
suppressed
```

`pending` records expire and are cleaned in bounded batches.

Administrator CSV intake is an invitation path, not a migration into subscribed state.
It requires an explicit operator approval, accepts at most 250 nonblank rows per
synchronous request, and recognizes `email` plus an optional `name` or `display_name`
column. Other columns, including any imported status, are ignored. The importer parses
the complete bounded row set before sending, deduplicates canonical email addresses
inside the file, and reuses the standalone confirmation-token and cooldown rules. New
contacts remain `pending`; existing subscribed, unsubscribed, and suppressed contacts
are never reactivated. A distinct invitation message explains that the recipient is not
subscribed until they intentionally confirm.

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

Missing or malformed preference metadata resolves to `site_default`; it is never
interpreted as an implicit opt-in. The WordPress profile control is self-service:
an administrator editing another user's profile does not silently change this
preference. Future management or resubscription workflows must remain explicit
and auditable rather than using ordinary profile administration as an override.

Standalone subscribers are stored in a plugin table.

An email address may appear through multiple sources. Audience resolution
deduplicates by normalized email. A global suppression record prevents an
unsubscribed address from re-entering a campaign through a role, list, or
alternate source.

When an email belongs to both a WordPress user and a standalone subscriber:

- audience policy returns at most one normalized recipient;
- registered-user verification and preference still apply to the user source;
- standalone status still applies to the subscriber source;
- if both sources are eligible, the registered user is the deterministic primary
  identity while both eligible IDs remain available to the later campaign snapshot;
- if the registered source is ineligible but the standalone source is eligible, the
  standalone subscriber remains eligible rather than being discarded by deduplication;
- a global suppression overrides both records; and
- explicit resubscription requires a verified management workflow.

The Alpha.5 resolver accepts a caller-supplied site-default opt-in flag. `site_default`
is fail-closed when that policy is not supplied, so missing or malformed user metadata
never becomes an implicit opt-in. The editor and campaign milestones will supply the
actual site/post policy when those settings exist.

### 4.5 Named lists

Named lists can contain:

- WordPress user references;
- standalone subscriber references; and
- future supported contact types through a typed membership interface.

Schema 1 persists a canonical membership key such as `user:123` or
`subscriber:456`, so the same typed entity cannot be added to one list twice even
though a normalized email may currently exist through more than one source. List
administration resolves the requested source explicitly and does not merge those
sources implicitly.

Lists do not confer eligibility and do not bypass verification, preferences, or
global suppression. Adding a suppressed contact to a list does not resubscribe or
unsuppress the address. Removing a contact from a named list does not erase the
contact or revoke a global subscription. Cross-source email deduplication remains an
audience-resolution responsibility.

### 4.6 Verification adapters

Internal interface:

```php
interface VerificationProvider {
    public function is_available(): bool;
    public function status_for_user( int $user_id ): VerificationStatus;
    public function description(): string;
    public function health(): VerificationProviderHealth;
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

The adapter requires the canonical public functions provided by ArgentWolf Email
Verification 1.0.2 or later. It detects the companion version from the
file that defines the public status function and reports distinct health codes
for a missing API, failed provider health check, unknown version, obsolete API,
and healthy provider (`missing_api`, `provider_health_failed`, `unknown_version`,
`obsolete_api`, and `healthy`).

No private `_wrav_ev_*` metadata compatibility adapter is included. The
`argentwolf_post_notifier_verification_provider` filter may replace the default
provider, but a filtered value must implement `VerificationProvider`. Missing,
invalid, obsolete, or failing providers resolve to `unknown`, so registered-user
delivery fails closed. A successful `wp_mail()` call is transport evidence only
and is never verification evidence.

The `0.1.0-alpha.2` implementation boundary ends at this provider contract,
health reporting, administrator warning, and reusable registered-user eligibility
policy. It does not yet resolve campaign audiences or send campaign mail. The
audience resolver and queue milestones consume this policy later and are where
per-recipient resolution and pre-send rechecks become executable behavior.

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

The selection service uses `parse_blocks()` and `serialize_blocks()` rather
than regular-expression cutoff matching. Its `full`, `email_cutoff`, `more`,
`manual_excerpt`, and `generated_excerpt` sources have explicit tests.
`full` mode overrides all markers; Email Cutoff outranks More even if it is
later in the post. More can be disabled by the caller. The first applicable
marker determines the content prefix, with presentation markers removed.
For a marker nested inside a container, the selector returns only static
plain text preceding it rather than potentially malformed partial block HTML.
Generated excerpts also use static plain text and bounded word trimming.
The service does not execute dynamic block callbacks, embeds, or shortcodes.
Its `format=blocks` output is untrusted markup and MUST NOT be used
independently as email HTML. The canonical `EmailContentRenderer` consumes the
selector and returns one selected source as sanitized HTML and plain text.
The renderer interprets saved **static** block inner content only: paragraph,
heading, list, list-item, quote, pullquote, separator, preformatted and verse.
Group/column containers are flattened to supported children. Unknown blocks,
dynamic blocks, core embed/shortcode/html blocks, media and images are omitted
rather than executed; registered shortcodes are stripped without invoking
callbacks. Raw Classic Editor markup is sanitized with an explicit tag and
attribute allowlist, unsafe URLs are removed, and relative links become absolute
site URLs. Plain text is derived from that sanitized result and retains visible
link destinations. No image loads or remote fetches occur in composition.

These are **content fragments**, not complete email messages. Tranche 4
introduces `EmailTemplateComposer`, which composes fixed default responsive-width
HTML and plain-text messages by calling that same canonical fragment renderer
once. This is an inert composition service: it does not create campaigns,
recipients, snapshots, tokens, transport messages, or queue work. It requires
three already-created absolute same-site HTTP(S) URLs: the published post,
unsubscribe and manage-notifications links. Invalid links fail closed. The
caller must not use placeholder or invented recipient bearer URLs in actual
sending.

The fixed default body templates interpolate only `{{site_name}}`,
`{{post_title}}`, `{{post_url}}`, and `{{content}}` using output-context-specific
escaping. The HTML layout has no external styles, imagery, tracking URLs, or
remote assets. A mandatory subscribe-management footer is appended **after**
both fixed bodies; it cannot be omitted through a template token choice.
These internal placeholders are not yet the configurable custom-template
syntax and do not replace the broader future allowlist described below.
Custom templates, CTA overrides, settings persistence, preview routes,
campaign snapshots and mail transport remain future work. No persistent site
settings for More or generated-excerpt word count are added in this tranche;
caller parameters are bounded. Classic `<!--more-->` text markers remain
unsupported.

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

Beta.2 tranche 1 implements the first runtime boundary of this design. AWPN observes
`wp_after_insert_post`, after the completed core save, and only reserves an initial campaign
when the final stored post is `publish`, the prior state was not `publish`, notification
intent is explicitly `send`, the post is not a revision/autosave, and the stored GMT
publication time is not materially in the future. Neutral `site_default` intent remains
fail-closed until a site-wide send policy is defined.

The reservation is an atomic insert into the frozen schema-1 campaigns table using
`initial:{site_id}:{post_id}` and its existing unique `campaign_key` constraint. The row
starts in `building` state. Subject, HTML, and text bodies are empty build-state placeholders,
and no recipient rows are created. Later rendering and audience milestones populate the
immutable content and recipient snapshots before any campaign may become queueable.

Beta.2 tranche 2 qualifies non-published status saves, scheduled edits and date changes,
on-time and late scheduled publication, manual early publication, and direct core publication.
WordPress's direct `wp_publish_post()` helper can transition a draft to `publish` without
populating an otherwise-zero `post_date_gmt`. AWPN therefore treats `publish` as the primary
authority and applies the future-time rejection only when a real GMT publication timestamp is
present. A nonzero malformed or materially future timestamp still fails closed.

### Beta.2 reservation diagnostics

Unexpected initial-campaign persistence failures cannot reverse a WordPress publication.
The publication observer emits `argentwolf_post_notifier_campaign_creation_failed` with the
numeric post ID and the fixed `persistence_error` code, and the registered campaign diagnostic
handler records a single line in the server PHP error log. Arbitrary reasons supplied by
other listeners are reduced to `unknown_error`; no throwable message, stack trace, email,
recipient, subject, body, token, URL, raw request parameter, IP address, or user agent is
interpolated. Logs contain only fixed event/status codes and numeric site/post IDs.

A successful attempt emits `argentwolf_post_notifier_campaign_reservation_resolved` with
numeric post/campaign IDs. It may have resolved a previously reserved row; the event does
**not** mean a new campaign was created or any email was queued. Logging this successful
resolution is disabled by default, even when `WP_DEBUG_LOG` is enabled. Operators may
explicitly define `ARGENTWOLF_POST_NOTIFIER_DEBUG_CAMPAIGNS` to boolean `true` in
`wp-config.php` to turn on these ID-only debug lines. Ordinary non-publication saves,
neutral send intent, and scheduling do not emit campaign diagnostics. The diagnostics
layer adds no tables, recipient access, delivery behavior, or outside telemetry.

### Beta.2 Site Health boundary

WordPress's `site_status_tests` filter registers two direct, read-only checks.
The overdue-publication check performs a bounded `WP_Query` for at most one
supported `post` with `post_status=future`, explicit stored send intent `send`,
and `post_date_gmt` at least 15 minutes past due. It reports `recommended` when
an overdue post exists and includes only fixed operator text and an administrator
link to the scheduled-post list. Otherwise it reports `good`. Neither test exposes
post identifiers, titles, stored metadata, recipients, messages, nor URLs from
post content; no campaign data is queried. It does not run WordPress cron or
publish the post.

The queue-wake-up check is a consciously informational `good` result while the
Milestone 10 delivery worker does not exist. No queue event name is fabricated,
no worker is scheduled, and no absence-of-hook failure is emitted. The result
**must not** be interpreted as confirmation of queue reliability or email
sending. Milestone 10 replaces this provisional status with a real wake-up and
worker-health contract after implementation. An operator using an external cron
runner is not falsely warned solely because `DISABLE_WP_CRON` is configured.

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

Beta.2 adds a disposable-site concurrency test in the exact-package CI job.
Independent PHP/WordPress processes invoke the publication observer against the
same published fixture. The first process holds its inserted campaign key in an
uncommitted database transaction while five others contend for it. The test
requires that the contenders do not complete before commit, then all resolve
the same campaign ID without changing its building state or creating recipients.
This exercises the real InnoDB unique-index conflict path, not merely sequential
hook re-entry. Qualification is pending a successful Forgejo run.

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
3. Apply explicit typed exclusions.
4. Normalize email addresses and group sources by normalized email.
5. Apply registered-user preference and verification to each user source.
6. Require standalone subscriber status `subscribed` for each subscriber source.
7. Apply global suppression to the complete normalized-email group.
8. Select one deterministic recipient identity per normalized email.
9. Record duplicate and aggregate skip reasons.
10. Snapshot eligible recipients when campaign persistence is implemented.

Alpha.5 implements steps 1-9 as a reusable policy layer. Role expansion itself remains
a caller responsibility: the resolver receives the role-derived user IDs, which keeps
WordPress role querying separate from recipient policy. Milestone 9 will consume this
resolver when it creates immutable campaign-recipient rows and performs pre-send
hard-ineligibility rechecks.

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

Table prefixes use `$wpdb->prefix`. Schema changes are forward-only, ordered
migrations. The schema option advances only after a migration completes. A
connection-scoped MySQL advisory lock serializes migration execution for the
active site prefix; callers wait only for a bounded interval and fail rather
than running migrations concurrently without a lock. Re-running a completed
migration path must be harmless.

Schema version 1 creates the seven tables below. It intentionally does not add
foreign-key constraints so WordPress table-prefix operation, `dbDelta()`
compatibility, and controlled uninstall remain straightforward; application
repositories enforce typed relationships and all required lookup/uniqueness
indexes remain database constraints.

Email deduplication and suppression use one canonical normalization service and
a persistent random 32-byte site-local HMAC key. Only deterministic SHA-256 HMAC
values are used for keyed email identity. The key is not derived from WordPress
salts because rotating site salts must not silently change stored identity
hashes. The key is preserved with plugin data and removed only during explicitly
destructive uninstall.

There is no automatic schema downgrade. Before an operator deliberately runs
older code against a newer schema, restore a compatible database backup or use a
documented forward recovery migration. Failed migrations do not advance the
stored schema version, so the same code can retry after the underlying fault is
corrected. Re-running the current idempotent migration under the advisory lock is
also the supported repair path for recoverable table/index drift. Plugin-version
upgrades revalidate the current schema even when the numeric schema version has
not changed. A database claiming a schema newer than the running code fails
closed rather than attempting a downgrade.

All plugin-owned `*_at_gmt` values are persisted through the canonical UTC
conversion helper. Persistence code must not depend on the PHP default timezone
or the site's display timezone.

Schema 1 is frozen as the reviewed alpha.3 database contract. Its migration is
protected by a committed source digest so accidental edits fail the dependency-
free project checks. Later structural changes require schema 2 or newer; they do
not rewrite schema 1. Tagged alpha.1 and alpha.2 checkpoints both represent
schema 0 and are exercised as released upgrade origins before schema 1.

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
email_snapshot             nullable after retention redaction
email_hash                 nullable after retention redaction
unsubscribe_token_hash
click_token_hash
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
personal_data_erased_at_gmt
UNIQUE campaign_id,email_hash
```

The email snapshot is needed so a frozen campaign does not silently change
destination when a profile is edited after campaign creation. After a completed
campaign crosses the configured retention boundary, bounded cleanup can clear
the user/subscriber identifiers, email snapshot, keyed email hash, public-link
token hashes, and display name while retaining aggregate delivery/click state. The erasure timestamp keeps
that maintenance operation idempotent.

### 8.3 `argentwolf_pn_subscribers`

Representative fields:

```text
id
uuid
email
email_hash                UNIQUE
display_name
status
confirmation_token_hash      UNIQUE when present
confirmation_expires_at_gmt
manage_token_hash            UNIQUE when present
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
member_key                  canonical `user:<id>` or `subscriber:<id>` identity
created_at_gmt
UNIQUE list_id,member_key
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

The alpha.5 standalone-management implementation uses a random 256-bit bearer
created at successful confirmation and stores only its SHA-256 hash. The bearer
may remove only a suppression whose recorded source is `subscriber_manage`. An
administrator-created `subscriber_admin` suppression cannot be removed by the
self-service management path.

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

The capability model grants plugin-owned capabilities to administrators by default.
The capability-layout version is checked during ordinary plugin upgrade handling so an
already-active installation receives newly introduced grants. Deactivation preserves
role assignments; uninstall removes plugin-owned capabilities from every role. Beta.1
adds `send_post_notifications` as an independently delegable permission and does not
grant it automatically to editors. Sending does not imply permission to view
subscriber data or edit global templates.

Standalone subscriber administration and CSV intake require
`manage_post_notification_subscribers`. Named-list administration requires
`manage_post_notification_lists`. The two capabilities may be delegated independently;
a list-only role receives a directly accessible Lists menu instead of depending on the
subscriber-management parent menu. Neither capability is granted automatically to
editors or send-only roles. Subscriber suppression writes both the canonical global
suppression and the standalone row state. Self-service resubscription cannot remove an
administrator-created suppression; operator override remains a separate workflow.

## 14. Privacy and retention

The plugin supplies:

- preserve-by-default uninstall behavior; destructive uninstall is enabled only
  by the explicit `argentwolf_post_notifier_delete_data_on_uninstall` option and
  removes plugin-owned tables, version state, and the keyed email-hash secret;
- privacy-policy helper text;
- personal-data exporter;
- personal-data eraser;
- configurable retention for completed campaign recipient details;
- configurable retention for click events;
- daily cleanup for pending subscriber records after a seven-day post-expiry
  grace period, limited to 250 rows per scheduled run;
- bounded cleanup primitives with a hard per-operation batch ceiling; the
  database layer accepts caller-selected cutoffs and never silently chooses a
  retention period;
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

The package CI runtime supplements PHPUnit with two disposable-site integration
gates: (1) independent PHP/WordPress processes contending on the initial-campaign
key under an actual InnoDB row lock; and (2) independent WP-CLI commands for
post creation, metadata configuration, immediate and manual-early publish, scheduled edits,
`wp cron event run publish_future_post`, and unpublish/republish. The CLI test
changes a scheduled fixture's stored timestamp directly only to simulate an
overdue WordPress cron event; this database-only fixture change must not itself
fire publication hooks. Tests assert campaign uniqueness, preserved ID, the
`building` state, empty message bodies, and no recipients before queue work.
Both gates operate on an installed distribution package and delete fixtures
afterwards. Neither test simulates email delivery.

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

The Forgejo Git repository remains the authoritative development history. A
Forgejo tag and release archive are not, by themselves, a WordPress.org release.

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
