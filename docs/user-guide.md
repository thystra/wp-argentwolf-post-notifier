# ArgentWolf Post Notifier: user and operator guide

Applies to 0.1.0-rc.1.

## Install and upgrade

1. Install and activate **ArgentWolf Email Verification** 1.0.2 or later from
   WordPress.org. The notifier declares it with `Requires Plugins`, so WordPress
   will not activate the notifier without it.
2. Upload the release ZIP through **Plugins → Add New → Upload Plugin** and activate it.
3. Activation creates the plugin tables (schema 3) and grants administrators the
   `manage_post_notification_subscribers`, `manage_post_notification_lists` and
   `send_post_notifications` capabilities.

Upgrades: replace the plugin with a newer ZIP. Schema migrations run
automatically under a lock on the next request and never rewrite earlier
migrations. Deactivation clears scheduled events. Data is kept unless
destructive uninstall is enabled (see below).

## Companion verification

Registered users are eligible only if they have opted in on their profile and
ArgentWolf Email Verification reports their current address as verified. The
neutral `site_default` preference currently means *not subscribed*: there is no
site-wide opt-in policy setting yet. If the companion is missing, too old or
unhealthy:

- campaigns that include registered users wait (bounded to one day) and then fail closed;
- the editor sidebar and an admin notice warn;
- standalone double opt-in subscribers are not affected.

## Cron and the WP-CLI worker

Publishing a post creates a `building` campaign. A cron event prepares it
(renders content and freezes the audience) and the queue worker sends it in
batches.

- WP-Cron works out of the box, but only runs when the site receives traffic.
- For dependable timing, set `define( 'DISABLE_WP_CRON', true );` and run:

  ```
  */5 * * * * wp cron event run --due-now --path=/srv/site
  */5 * * * * wp argentwolf-post-notifier queue run --path=/srv/site
  ```

- Commands: `wp argentwolf-post-notifier queue run`, `queue status`,
  `queue prepare <campaign-id>`.
- Workers lease rows, so several concurrent runners are safe. This is verified by
  `tests/concurrent-queue.sh`.
- **Post Notifier → Delivery** shows the queue status and offers a bounded
  **Run queue now** button.

## SMTP and `wp_mail()`

Messages go through `wp_mail()`, one message per recipient, with no Cc or Bcc. The
plugin records *submission to `wp_mail()`*, not inbox delivery. Bounces and
complaints are not processed. Use an SMTP or API mail plugin and set up SPF,
DKIM and DMARC for your domain. Large lists depend on your provider's rate
limits. Tune them with `argentwolf_post_notifier_queue_batch_size`.

## Scheduled posts

Nothing happens when a post is scheduled. When WordPress publishes it
(`publish_future_post`), exactly one campaign is created. Content is
captured at that moment. Republishing does not create a second initial
campaign. If a post is unpublished, trashed or deleted while queued or sending,
the remaining messages are cancelled (`post_removed`).

## Subscription block and double opt-in

Add the **Post notification signup** block. A submission creates a pending
record and sends a confirmation email (link valid 24 hours, 15-minute resend
cooldown). The link opens a page with a confirm button, so link scanners
cannot subscribe anyone. Responses are generic, so the form cannot be used to
find out who is subscribed. Signups are rate-limited and have a honeypot.
Pending records are purged seven days after they expire.

## Unsubscribe, manage and privacy

- Every campaign message has a personal unsubscribe link, a manage link and
  RFC 8058 `List-Unsubscribe`/`List-Unsubscribe-Post` headers.
- Unsubscribing adds a global suppression (keyed hash). Links work for one year.
  A campaign-link resubscribe works for 30 days and only undoes the matching
  unsubscribe.
- Registered users manage their preference on their profile.
- **Click counting** (on by default, **Post Notifier → Privacy**): only links
  to the post are rewritten to an opaque per-recipient link. Clicking it
  records the time and redirects to the post's current permalink. No IP
  address, user agent, referrer or cookie is stored. Opens are never tracked.
  Turn it off for direct links. Counts are approximate, because security
  scanners click links.
- Retention: click events default to 90 days. Per-recipient delivery records
  are anonymized 365 days after a campaign completes. A daily job processes
  bounded batches.
- **Tools → Export/Erase Personal Data** include this plugin. Erasure deletes the
  subscriber, redacts delivery records and clicks, removes the account
  preference and keeps only the suppression hash, which is reported as retained.
- Suggested policy text appears under **Settings → Privacy → Policy guide**.
- Deleting a WordPress user redacts their delivery records.

## Templates, tokens and cutoff precedence

Templates (**Settings → Post Notifier Templates**) accept only these tokens:
`{{site_name}}`, `{{post_title}}`, `{{post_url}}`, `{{content}}`,
`{{heading}}`, `{{before}}`, `{{after}}`, `{{cta}}`. Admin text is plain text.
Layout colours, font, width and CTA style are validated.

Content selection per post (`content mode`):

1. `full`: the whole post.
2. Otherwise, the first **Email Cutoff** block, if present.
3. Otherwise, the core **More** block, if enabled in settings.
4. Otherwise, the manual excerpt.
5. Otherwise, a generated excerpt (configurable word count).

The post content, template and layout are captured when the post is
published. Later edits do not change the campaign.

## Capabilities

| Capability | Grants |
| --- | --- |
| `send_post_notifications` | Editor sidebar, audience estimate, contact lookup, sending on publish |
| `manage_post_notification_subscribers` | Subscriber screen, suppression, CSV import and export |
| `manage_post_notification_lists` | Named lists |
| `manage_options` | Templates, Delivery screen, Privacy settings |

Give `send_post_notifications` to editors without exposing subscriber data.

## Hooks

Filters:

- `argentwolf_post_notifier_verification_provider`: replace the verification provider.
- `argentwolf_post_notifier_recipient_eligible`: veto a recipient at send time.
- `argentwolf_post_notifier_queue_batch_size`, `_queue_lease_seconds`, `_queue_max_attempts`: tune the queue.
- `argentwolf_post_notifier_max_preparation_deferral_seconds`: set how long to wait for an unhealthy provider.
- `argentwolf_post_notifier_recipient_token_ttl_seconds`, `_recipient_resubscribe_ttl_seconds`: set link lifetimes.
- `argentwolf_post_notifier_editor_request_limit`: set the editor lookup rate limit.
- `argentwolf_post_notifier_editor_template_choices`: change the template choices shown in the editor.

Actions:

- `argentwolf_post_notifier_activated`, `_deactivated`.
- `argentwolf_post_notifier_campaign_reservation_resolved`, `_campaign_creation_failed`, `_campaign_snapshot_failed`.
- `argentwolf_post_notifier_delivery_result`: fires after each recipient attempt, with IDs and status only.

## Uninstall

By default, deleting the plugin removes only its capabilities. To remove
everything (tables, options, user preference meta), tick the **Uninstall**
option under **Post Notifier → Privacy** before deleting.

## Translations

All strings use the `argentwolf-post-notifier` text domain. WordPress loads
translations from translate.wordpress.org on demand. Editor scripts register
their translations with `wp_set_script_translations`.
