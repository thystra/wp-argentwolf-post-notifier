<!-- ~/src/wp-argentwolf-post-notifier/README.md -->
# ArgentWolf Post Notifier

ArgentWolf Post Notifier is a planned GPL-licensed WordPress plugin for sending
verified, unsubscribe-capable email notifications when posts are actually
published.

The project is now in `0.1.0-alpha.4` development. The verification-provider
contract, lifecycle/tooling foundation, and frozen schema-1 data foundation are
present. Alpha.4 begins the standalone-subscriber workflow with typed lifecycle
states, canonical email identity, secure hashed confirmation tokens, pending
record creation/refresh, token expiry, resend cooldown, and explicit confirmation
promotion. The second tranche adds generic non-enumerating signup coordination,
keyed local rate limits that do not retain raw network addresses, honeypot handling,
and confirmation-message submission through a transport abstraction. The public
block and intentional-POST confirmation route remain later Milestone-4 work.
The intended public distribution channel, once the plugin is complete and
operational, is the WordPress.org Plugin Directory.

## Development requirements

- WordPress 7.0 or newer;
- PHP 8.4 or newer;
- Composer 2 for PHP development dependencies;
- Node.js 20.19 or newer (or 22.13 or newer) and npm 10.2.3 or newer for editor tooling.

The source tree includes a verified project-local Composer installer helper.
The runtime package has no third-party PHP dependency in this alpha and retains
a reviewed fallback PSR-4 autoloader.

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

Scheduling a post must not create a campaign or send email. Notification intent
is stored with the scheduled post. The campaign is created only when WordPress
actually changes the post from `future` to `publish`.

If WP-Cron runs late, the notification is delayed until actual publication; it
is never sent early merely because the editor selected a future date.

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
obsolete, or failing verification APIs still fail closed.

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
current WordPress 7.1 patch release against the minimum and current qualified
ArgentWolf Email Verification releases. After source and integration checks pass,
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

<!-- EOF: ~/src/wp-argentwolf-post-notifier/README.md -->
