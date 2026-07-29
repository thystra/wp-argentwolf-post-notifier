\
<!-- /home/alan/src/wp-argentwolf-post-notifier/README.md -->
# ArgentWolf Post Notifier

ArgentWolf Post Notifier is a planned GPL-licensed WordPress plugin for sending
verified, unsubscribe-capable email notifications when posts are actually
published.

The project is currently in the architecture and repository-scaffolding stage.
No functional plugin release is available yet.

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

[ArgentWolf Email Verification](https://github.com/thystra/wp-argentwolf-email-verification)

The notifier will use a stable public verification API from that plugin and
will fail closed for registered-user recipients when no authoritative
verification provider is available.

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

## Development

Primary development checkout:

```text
/home/alan/src/wp-argentwolf-post-notifier
```

Primary development host:

```text
fafnir
```

The first development milestones are:

1. establish the plugin skeleton and automated quality gates;
2. add a public verification contract to the companion plugin;
3. implement versioned database schema;
4. implement the verified standalone subscription block;
5. implement the editor and actual-publication campaign lifecycle; and
6. implement content rendering, queue delivery, unsubscribe, and statistics.

See [TODO.md](TODO.md) for acceptance criteria and release planning.

## License

ArgentWolf Post Notifier is licensed under the GNU General Public License,
version 2 or later. See [LICENSE](LICENSE).

## Support the project

Development is supported through the repository's GitHub funding links:

- [GitHub Sponsors](https://github.com/sponsors/thystra)
- [Ko-fi](https://ko-fi.com/thewolfandtheraven)
- [Patreon](https://www.patreon.com/WolfandRavenBlog)
- [Wolf & Raven](https://www.wolfandraven.blog)

Financial support does not change the GPL license or grant exclusive control
over the open-source project.

<!-- EOF: /home/alan/src/wp-argentwolf-post-notifier/README.md -->
