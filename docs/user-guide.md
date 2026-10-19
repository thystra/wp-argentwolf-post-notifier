# ArgentWolf Post Notifier: user and operator guide

Applies to 0.1.0-rc.4.

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

## Site-wide notification defaults

**Post Notifier → Settings** controls new-post notifications. Existing sites
upgrade with the send default **Off**; enabling it affects only future
publication events, never posts already published. The default audience is
**All Subscribers**: a dynamic view of opted-in standalone subscribers and
verified, opted-in WordPress accounts. It requires no list membership and never
creates a second consent record. Admins can instead select one named list as
the site default. If that list has been deleted, preparation fails closed
rather than broadening to All Subscribers.

An explicit per-post **Send notification** or **Do not send** overrides the
site-wide send switch. When a post has no positive audience targets, the
site-wide audience applies (individual exclusions still narrow it). Explicit
roles, lists, and individual inclusions on a post replace the default audience.
Campaigns freeze this effective audience on actual publication. Membership
never bypasses consent, verification, or suppression; matching identities
are deduplicated by normalized email before delivery.

The native Post sidebar, improved publish-time prompts, list duplication,
bulk selection, and CSV pending-membership enrollment are planned as separate
qualified changes. The existing editor plugin sidebar remains in place in
this tranche.

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

## Inviting existing users

Existing accounts are never subscribed automatically (GDPR). After activation
an admin notice offers **Post Notifier → Invite users**. Sending there emails
every non-administrator user the same double opt-in invitation as the CSV
import. Nobody is subscribed until they confirm. Suppressed users and users
whose profile preference is already *subscribed* are skipped. Invitations go
out via WP-Cron in batches of 25, one batch per minute, with the usual
per-address cooldown. An optional list can be chosen. The screen shows
progress (processed, invited, skipped, failed). Before starting it shows how
many users would be invited. Administrators are identified by their actual
role, so custom roles and members without a role (for example from
membership plugins) are counted. A run with no users finishes at once with
a message. If the run never sent an invitation, it can be reset and started
again; once any invitation has gone out, it can't be run again, and
reactivating the plugin doesn't offer it again. A missing or stuck state
repairs itself: it goes back to the start form, or the next batch is
rescheduled if invitations were already sent. The notice can be dismissed
and the screen stays available.

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
- Without per-recipient consent, a click only increments the campaign's total
  and unique counters. No click event row and no per-recipient count is stored;
  a first-click marker on the delivery record is kept only to de-duplicate the
  unique total.
- **Per-recipient click tracking** (off by default, **Post Notifier → Privacy**,
  requires click counting): consent is a separate, unticked box. New
  subscribers see it only on the confirmation page they reach from the
  confirmation email, next to the "Confirm subscription" button. The email
  says the page offers this optional choice and gives the wording version.
  Confirming without ticking it does not grant consent. The public signup
  form has no tracking box, so a third party or a resubmission can never grant
  consent. Logged-in users can give or withdraw it on their profile, and
  link holders on the manage page. The consent time and wording version are
  recorded. Only for consenting
  recipients is each click stored with its time and a *likely scanner* flag
  (clicks within N seconds of delivery, default 30). Withdrawal (manage page
  or profile) stops attribution at once and deletes clicks already linked to
  the person; totals stay. Attribution is cleared after its own retention
  period (default 30 days); turning the feature off clears all of it on the
  next daily run.
- **Post Notifier → Recipient clicks** (`view_post_notification_stats`) shows
  attributed clicks and exports them as CSV. Each view and export is written
  to the audit log (actor and campaign only).
- Retention: click events default to 90 days. Per-recipient delivery records
  are anonymized 365 days after a campaign completes. A daily job processes
  bounded batches.
- **Tools → Export/Erase Personal Data** include this plugin. Erasure deletes the
  subscriber, redacts delivery records and clicks, removes the account
  preference and click-tracking consent, and keeps only the suppression hash,
  which is reported as retained. The export includes consent records and
  attributed click times.
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
| `view_post_notification_stats` | Per-recipient click view and CSV export (granted to administrators on activation, removed on uninstall) |
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
