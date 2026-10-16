=== ArgentWolf Post Notifier ===
Contributors: thystra
Tags: email, notifications, newsletter, subscribers, double opt-in
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 0.1.0-rc.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Email new posts to verified users and double opt-in subscribers, sent from your own site after publication. One-click unsubscribe included.

== Description ==

ArgentWolf Post Notifier emails a post to an audience you choose, but only after WordPress has actually published it. Scheduling a post never sends anything early.

* **Verified, opted-in audiences.** Registered users are included only if they have opted in on their profile and their email address is verified by the required ArgentWolf Email Verification plugin. Visitors subscribe with a block and confirm by double opt-in.
* **Per-post control.** In the block editor sidebar you choose whether to notify, who receives it (roles, named lists, individual contacts), the content mode (excerpt, full, or up to an Email Cutoff block) and the template.
* **Reliable delivery.** Each campaign is frozen at publication and sent through `wp_mail()` by a lease-based queue with retries. It runs from WP-Cron or a WP-CLI worker.
* **Unsubscribe that works.** Every message has a personal unsubscribe link and RFC 8058 one-click `List-Unsubscribe` headers. Unsubscribing suppresses the address globally.
* **Privacy first.** Opens are never tracked. Click counting is optional and stores no IP address, browser data or cookies. Integrates with the WordPress personal-data export and erasure tools. No telemetry.

= Requirements =

* WordPress 7.0 or later and PHP 8.4 or later.
* [ArgentWolf Email Verification](https://wordpress.org/plugins/argentwolf-email-verification/) 1.0.2 or later (declared with `Requires Plugins`). If the provider is missing or unhealthy, registered users are skipped (fail closed).
* A working `wp_mail()` setup. On most hosts an SMTP plugin is strongly recommended.

= Data stored and privacy =

* Standalone subscribers: email address, optional name, status, signup time and source, and the consent text shown.
* Registered users: a notification preference in user meta.
* Per-campaign delivery records: address, status and time. These are anonymized after a configurable period (default 365 days after the campaign completes).
* Click events (if click counting is on): the delivery record and the time only. They are deleted after a configurable period (default 90 days). Click counts are approximate, because mail security scanners can follow links automatically.
* Per-recipient click attribution (off by default; only for recipients who tick a separate, optional consent box): the time of each click linked to the delivery record, a likely-scanner flag, and the consent time and wording version. Legal basis: consent. Only users with the `view_post_notification_stats` capability (administrators) can view or export it, and each access is logged. Cleared after a configurable period (default 30 days); totals are kept. Withdraw any time from the manage link or profile.
* Suppressions: a keyed hash of each unsubscribed address, so it is never mailed again.
* Email addresses are hashed with a site-specific key for matching. Tokens are stored only as hashes. Logs never contain addresses or tokens.

Turn click counting off under **Post Notifier → Privacy** (direct-link mode). Suggested privacy-policy text is added to **Settings → Privacy**.

= Uninstall =

Deleting the plugin keeps your data unless you tick the **Uninstall** option under **Post Notifier → Privacy**. With that option on, uninstall removes the plugin tables, options and user preferences.

== Installation ==

1. Install and activate **ArgentWolf Email Verification**.
2. Upload and activate **ArgentWolf Post Notifier**.
3. Make sure your site can send mail. Use **Settings → Post Notifier Templates → Send test email to my account** to check.
4. Optionally add the **Post notification signup** block to a page or widget area for visitor signups.
5. When writing a post, open the **Post Notifications** sidebar, choose to send and pick an audience, then publish.

For reliable timing on busy or low-traffic sites, disable WP-Cron's page-load trigger and run the worker from system cron:

`*/5 * * * * wp argentwolf-post-notifier queue run --path=/path/to/site`

== Frequently Asked Questions ==

= Does scheduling a post send email? =

No. A campaign is created only when WordPress actually publishes the post. Scheduled posts are sent once they go live.

= What happens if I unpublish or trash a post while it is sending? =

Remaining unsent messages are cancelled. Readers who follow an old link to a post that is not public are sent to your home page.

= Why do registered users get skipped? =

They have not opted in on their profile (the default is not subscribed), their email is not verified, they are suppressed, or the verification provider is unavailable. The Delivery screen shows skip reasons.

= Are opens tracked? =

No. There are no tracking pixels. Click counting can be turned off entirely.

= Can I run the queue from WP-CLI? =

Yes: `wp argentwolf-post-notifier queue run`, `queue status` and `queue prepare <campaign-id>`.

= Which hooks are available? =

See `docs/user-guide.md` in the source repository for filters such as `argentwolf_post_notifier_queue_batch_size`, `argentwolf_post_notifier_recipient_eligible` and `argentwolf_post_notifier_delivery_result`.

== Changelog ==

= 0.1.0-rc.4 =
* Fix the Invite existing users screen on upgraded sites; robust audience count; self-healing state.
* Retry deadlocked campaign status updates under MySQL 8.

= 0.1.0-rc.3 =
* Per-recipient tracking consent is given only on the confirmation page (separate, unticked), never on the public form.

= 0.1.0-rc.2 =
* New lists show immediately; manual double opt-in "Add subscriber" form.
* Optional, consent-based per-recipient click tracking (off by default) with an audited admin view.
* One-time double opt-in invitation for existing users (no auto-enrolment).

= 0.1.0-rc.1 =
* Optional, privacy-minimal click counting with direct-link mode, and a campaign dashboard.
* Personal-data exporter and eraser, privacy-policy text, retention jobs, and deleted user and post handling.
* Uninstall data-deletion setting in the admin.
* Release-candidate packaging and documentation.

= 0.1.0-beta.3 =
* Campaign rendering, per-recipient queue and delivery, unsubscribe, resubscribe, and the delivery screen.

Earlier development releases are listed in CHANGELOG.md in the source repository.

== Upgrade Notice ==

= 0.1.0-rc.4 =
Fixes the existing-user invitation screen and a MySQL 8 queue deadlock.

= 0.1.0-rc.3 =
Tracking consent for new subscribers moves to the confirmation page.

= 0.1.0-rc.2 =
Staging fixes, optional consent-based per-recipient click tracking (off by default), and existing-user invitations. Runs schema migration 4.

= 0.1.0-rc.1 =
Release candidate. Adds click statistics (can be disabled) and WordPress privacy tools integration.
