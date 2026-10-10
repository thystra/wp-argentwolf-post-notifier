<!-- ~/src/wp-argentwolf-post-notifier/README.md -->
# ArgentWolf Post Notifier

ArgentWolf Post Notifier is a planned GPL-licensed WordPress plugin for sending
verified, unsubscribe-capable email notifications when posts are actually
published.

The `0.1.0-beta.2` implementation checkpoint completes the scheduled and immediate
publication lifecycle while remaining a development build, not a public release.
The earlier Beta.1 milestone established the editor workflow. The verification-provider contract, frozen schema
foundation, standalone double opt-in, subscriber administration, registered-user
preferences, global suppression, named lists, bounded CSV intake, structured audit
events, and reusable audience resolver are present. Beta.1 established the editor workflow
with a revision-aware REST post-meta contract for send intent, audience configuration,
content mode, template selection, and CTA override. Per-post notification writes
require both permission to edit the target post and the independently delegable
`send_post_notifications` capability. Beta.1 tranches 2 through 5 add the
post-editor sidebar, role and named-list selectors, and individual WordPress-user or
subscribed-standalone-subscriber include/exclude controls. Individual contacts are
found only by exact email through an authenticated POST lookup that returns masked
addresses and does not provide a browsable contact directory. The editor can explicitly
resolve the current unsaved audience configuration to aggregate eligible and skip counts
through the same reusable audience policy used by later campaign work; no resolved
recipient identities are returned, and neutral `site_default` registered-user preferences
remain fail-closed until a site policy defines that default. The sidebar also warns when
registered-user verification is unavailable or unhealthy and when an explicit estimate
resolves to zero eligible recipients; detailed provider diagnostics remain administrator-only.
Beta.1 also exposes the existing template-ID metadata through a bounded editor template
catalog: ID `0` uses the site default, and unavailable saved custom IDs are preserved and
flagged instead of silently changed. The block editor now also presents a native
pre-publish summary of the current notification intent, privacy-safe audience selections,
content mode, template, CTA override, and relevant warning state. The summary is
informational only and does not resolve recipients, render email, send mail, or create a
campaign. Beta.1 qualification verifies that the complete editor configuration survives
save/reload, scheduling, scheduled-post edits, revisions, and autosaves without creating
campaign state. The Beta.1 editor workflow intentionally supports the block editor only;
AWPN provides no notification-configuration UI in the Classic Editor. Template rendering,
previews, storage, and test sends are deferred together to Milestone 8 so they share one
canonical message-composition path. Beta.2 adds an actual-publication observer:
an explicit-send post reserves one idempotent `building` campaign after WordPress
publishes it. Concurrency and WP-CLI paths are qualified; privacy-safe campaign
diagnostics and read-only Site Health checks are included. The checkpoint does
not render notification messages, freeze recipients, queue work, or deliver
post-notification mail. Confirmation and invitation emails for standalone
subscribers are separate, already-implemented workflows.
The intended public distribution channel, once the plugin is complete and
operational, is the WordPress.org Plugin Directory.

## Development requirements

- WordPress 7.0 or newer;
- PHP 8.4 or newer;
- Composer 2 for PHP development dependencies;
- Node.js 20.19 or newer (or 22.13 or newer) and npm 10.2.3 or newer for editor tooling.

The source tree includes a verified project-local Composer installer helper.
The runtime package has no third-party PHP dependency in this beta and retains
a reviewed fallback PSR-4 autoloader.

## Beta.3 content-cutoff groundwork

Beta.3 tranche 1 adds the editor-visible `Email Cutoff` marker block.
The marker is stored in post block content and deliberately produces no public
HTML. Tranche 2 adds `EmailContentSelector`, a **selection-only** service that
resolves full content, Email Cutoff, core More, manual excerpt, and generated
excerpt in that order. It returns unrendered block markup or static plain text;
truncation inside nested blocks conservatively returns plain text instead of
broken HTML. A caller can specify whether More is enabled and the bounded
word count for generated excerpts; a persistent site-settings UI is not yet
implemented. Classic `<!--more-->` markers are not interpreted in this tranche.
Beta.3 tranche 3 adds `EmailContentRenderer`, which calls the canonical
selector and returns sanitized HTML plus a corresponding plain-text fragment.
It supports static core text blocks, headings, lists and quotations while
omitting dynamic/third-party blocks, embeds, shortcodes, images, and unsupported
markup without executing callbacks. Relative site links are made absolute and
links survive in plain text as visible URLs. These are **content fragments**,
not complete HTML/plain-text emails. Tranche 4 adds fixed responsive-width HTML
and plain-text defaults through `EmailTemplateComposer`, including mandatory
same-site unsubscribe and manage links that must be supplied by the trusted
caller. The fixed body placeholders are restricted to site name, post title,
post URL and canonical content. Tranche 5 adds a **Settings → Post Notifier Templates** administrator screen for
site-default plain-text subject, heading, text before/after the article, CTA
label and optional footer note. Only `{{site_name}}` and `{{post_title}}` are
valid custom tokens. Custom text is escaped in HTML; it cannot replace the
canonical article fragment or remove the mandatory subscriber links. Invalid
saved options revert safely to defaults. This is not the future named template
library (editor template IDs remain unchanged). Tranche 6 adds a read-only administrator post preview under the same settings
screen, using saved settings and a published post. It displays a sandboxed,
noninteractive HTML pane and a plain-text pane. Subscriber links in previews
are clearly labeled synthetic local examples, not usable unsubscribe links;
no subscriber tokens are created. Preview does not create campaigns, recipients,
or send mail. Tranche 7 adds an on-demand **Preview unsaved edits** action:
the current form text and selected published post are validated and composed
in memory via a nonce-protected administrator-only AJAX request. No setting is
saved, and the same synthetic footer and sandbox restrictions apply. Test
sends, campaign snapshots, subscriber-token generation, and delivery remain
deferred. Classic `<!--more-->` support
and persistent content settings are still pending.

## Planned features

- Select registered recipients by WordPress role, named list, or individual
  inclusion and exclusion.
- Require verified email status for every registered WordPress recipient.
- Let non-users subscribe through a block with double-opt-in verification.
- Confirm notification intent in the block editor's publish workflow.
- Handle immediate and scheduled posts without sending early.
- Send an excerpt by default, with full-post, More block, and custom Email
  Cutoff block options.
- Customize subject, HTML message, plain-text message, footer, and call to
  action.
- Queue one message per recipient rather than sending synchronously during
  publication.
- Provide visible unsubscribe, standardized one-click unsubscribe headers,
  global suppression, and verified resubscription.
- Track submitted, failed, skipped, unique-click, and total-click statistics.
- Provide privacy export, erasure, retention, and uninstall controls.

## Scheduled posts

Scheduling a post does not create a campaign or send email. Notification intent is stored
with the scheduled post. The Beta.2 publication observer reserves the initial `building`
campaign only when WordPress actually changes the post from `future` to `publish`.

If WP-Cron runs late, campaign reservation is delayed until actual publication; nothing is
created early merely because the editor selected a future date. Rendering, recipient
freezing, queueing, and mail delivery remain later milestones.

## Email verification

Registered WordPress account verification remains a separate companion plugin:

[ArgentWolf Email Verification](https://forgejo.argentwolf.org/alan/wp-plugin-argentwolf-email-verification)

The notifier will use the companion plugin's canonical
`argentwolf_email_verification_...` public API and will fail closed for
registered-user recipients when no authoritative verification provider is
available.

ArgentWolf Email Verification is now published on WordPress.org under the
`argentwolf-email-verification` slug. The notifier declares that formal
`Requires Plugins` dependency while retaining runtime health checks so missing,
obsolete, or failing verification APIs still fail closed. The minimum supported
companion release is 1.0.2; earlier releases and development tags are obsolete.

Standalone subscribers are maintained by the notifier and must complete a
double-opt-in confirmation before they are eligible for post notifications.

## Project documents

- [Architecture](ARCHITECTURE.md)
- [Milestones and tasks](TODO.md)
- [Agent and maintainer instructions](AGENTS.md)

## Architecture summary

A notification is an explicit campaign created at actual publication time. The
campaign freezes its content, audience, and recipient records. Delivery occurs
asynchronously through a queue. Verification, subscription preferences,
deduplication, and global suppression are applied before a recipient can enter
the send queue and are rechecked before delivery.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the complete proposed design.

## Canonical name

The public product name is **ArgentWolf Post Notifier**. The canonical plugin
slug and text domain are `argentwolf-post-notifier`. Public code identifiers
use the `ArgentWolf\PostNotifier` namespace or the
`argentwolf_post_notifier_` prefix.

## Campaign reservation diagnostics

When an eligible first publication cannot reserve its initial campaign, AWPN writes a
fixed-code event to the PHP error log containing only the site ID and post ID. Neither
recipient data nor exception text is logged. The post remains published; a failed
reservation must be investigated and does not represent an email delivery attempt.

Successful reservation **resolution** is silent by default. To log ID-only successful
resolutions for troubleshooting, an operator may add the following to `wp-config.php`
(before WordPress is loaded):

```php
define( 'ARGENTWOLF_POST_NOTIFIER_DEBUG_CAMPAIGNS', true );
```

Remove the definition or set it to `false` to disable the additional logs. A successful
resolution can refer to an already-existing campaign and does not mean any email was
queued or sent. Server/PHP error-log retention and access remain the site operator's
responsibility. No remote telemetry is used.

## Scheduled-publication Site Health (Beta.2)

Under **Tools → Site Health → Status**, ArgentWolf Post Notifier registers two direct
read-only checks:

- **Scheduled publications:** reports a recommendation when at least one post with
  explicit `send` intent remains `future` at least 15 minutes after its scheduled
  GMT time. The check examines at most one matching post and does not display
  post titles, recipient data, campaign content, or addresses. The action links
  to WordPress's scheduled-post list; investigate WP-Cron or a configured system
  cron runner if overdue publications persist. Neutral `site_default` intent and
  `do_not_send` do not trigger this warning.
- **Queue wake-ups:** reports the current Beta.2 state as informational. No
  delivery queue, worker, or queue wake-up event exists yet, so an absent worker
  event must not be treated as a site failure. A green informational result does
  **not** mean email delivery is healthy or active. Queue and worker health
  checks are deferred until those features are implemented.

These checks do not schedule cron jobs, publish posts, create campaigns, or send
mail. WordPress can publish an overdue post only when its regular publication
process actually executes.

## Development

A conventional local checkout is:

```bash
mkdir -p ~/src
cd ~/src
git clone https://forgejo.argentwolf.org/alan/wp-plugin-argentwolf-post-notifier.git wp-argentwolf-post-notifier
cd ~/src/wp-argentwolf-post-notifier
```

The first development milestones are:

1. establish the plugin skeleton and automated quality gates;
2. publish a canonical verification contract from the companion plugin;
3. implement versioned database schema;
4. implement the verified standalone subscription block;
5. implement the editor and actual-publication campaign lifecycle; and
6. implement content rendering, queue delivery, unsubscribe, and statistics.

WordPress.org submission is a separate release gate after operational testing.
The exact submitted ZIP must pass Plugin Check, package inspection, privacy and
license review, and the full supported-version test matrix.

Forgejo CI uses qualified PHP 8.4 and 8.5 images by immutable digest. The
integration matrix exercises the maintained WordPress 7.0 patch release and the
current WordPress 7.1 patch release against the qualified ArgentWolf Email
Verification 1.0.2 release. After source and integration checks pass,
CI builds one deterministic installable ZIP, installs that exact ZIP into a
disposable WordPress site with the qualified verification companion, verifies
the installed tree against the package bytes, and runs pinned Plugin Check 2.1.0
in static/new, runtime/new, and runtime/update modes. Plugin Check findings and
notifier-specific `WP_DEBUG_LOG` findings are blocking.

See [TODO.md](TODO.md) for acceptance criteria and release planning.

## License

ArgentWolf Post Notifier is licensed under the GNU General Public License,
version 2 or later. See [LICENSE](LICENSE).

## Support the project

Development is supported through these project funding links:

- [GitHub Sponsors](https://github.com/sponsors/thystra)
- [Ko-fi](https://ko-fi.com/thewolfandtheraven)
- [Patreon](https://www.patreon.com/WolfandRavenBlog)
- [Wolf & Raven](https://www.wolfandraven.blog)

Financial support does not change the GPL license or grant exclusive control
over the open-source project.

### Test email from template settings (Beta.3)

Administrators can send an explicitly labeled test of a selected published
post using the current unsaved template fields. The only recipient is their
WordPress account email address; there is no arbitrary-address input. The
message uses the canonical HTML composer with a plain-text alternative and a
`[TEST]` subject prefix. Subscriber management links are local preview examples
**without valid tokens** and are not functional; test emails are not for
forwarding as real notifications. A one-minute cooldown applies after a
successful transport submission. Submitting a test does not save settings,
create campaigns or recipients, or queue notification delivery. A successful
submission does not guarantee inbox delivery.

<!-- EOF: ~/src/wp-argentwolf-post-notifier/README.md -->
