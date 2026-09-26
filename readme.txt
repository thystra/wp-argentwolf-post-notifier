=== ArgentWolf Post Notifier ===
Contributors: thystra
Tags: email, notifications, posts, subscribers
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 0.1.0-alpha.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Alpha foundation for verified, unsubscribe-capable post notification campaigns created after publication.

== Description ==

ArgentWolf Post Notifier is currently an alpha development build.

The plugin establishes its bootstrap, service container, lifecycle handlers,
verification-provider contract, development tooling, tests, continuous
integration, deterministic packaging, and frozen initial database schema.
Registered-user verification supports the public API introduced in ArgentWolf
Email Verification 0.3.4 and is tested against the current 1.0.2 release; it
fails closed when no authoritative provider is healthy. Alpha.4 begins the
standalone-subscriber workflow with secure pending records, hashed confirmation
tokens, expiry, resend cooldown, generic signup responses, keyed local rate limits,
and confirmation-message submission through a transport abstraction. Confirmation
links now open a display-only page and require a separate nonce-protected POST to
promote a pending subscriber. Alpha.4 also includes the dynamic public subscribe block,
configurable consent/presentation text, and a no-JavaScript post-redirect-get form with
generic status messages.
It also adds an administrator-only subscriber screen with search/status filters,
one-way manual suppression, and filtered CSV export. Alpha.5 begins registered-user
preference handling with `site_default`, `subscribed`, and `unsubscribed` states plus
a self-service WordPress profile control. Alpha.5 also adds canonical global
suppression and secure standalone subscription management. Successful confirmation
issues a random management bearer while only its SHA-256 hash is stored; management
GET requests are display-only and state changes require nonce-protected POST.
Self-service resubscription can remove only a suppression created by the same source,
so administrator suppression remains authoritative.
Alpha.5 also adds administrator-managed named lists with typed WordPress-user and
standalone-subscriber memberships. List membership never changes subscription or
suppression state.

The intended design creates an explicit immutable campaign only after WordPress
actually publishes a post. Scheduling a post must not create a campaign or send
email.

Registered-user verification remains implemented by the separately developed
ArgentWolf Email Verification plugin. That companion is published on WordPress.org
under the `argentwolf-email-verification` slug, and this plugin declares it through
the formal `Requires Plugins` header. Runtime health checks remain fail-closed.

Development source and architecture documentation are available at the Plugin
URI.

== Installation ==

This alpha is intended for development and controlled testing.

1. Upload the `argentwolf-post-notifier` directory to `/wp-content/plugins/`.
2. Activate ArgentWolf Post Notifier through the Plugins screen.
3. Confirm that activation completes without warnings.

No notification campaign features are available yet.

== Frequently Asked Questions ==

= Does this alpha send post-notification email? =

No. This release is a development skeleton and intentionally sends no campaign
email.

= Does scheduling a post send anything? =

No. The project invariant is that campaign creation occurs only after actual
publication, never merely because a future publication time was selected.

= Is ArgentWolf Email Verification required? =

Yes. It is the authoritative provider for registered-user verification and is
now available from WordPress.org. This plugin declares the dependency while still
checking provider health and API compatibility at runtime.

== Changelog ==

= 0.1.0-alpha.5 =
* Begin registered-user preference handling for the alpha.5 audience-management milestone.
* Add canonical `site_default`, `subscribed`, and `unsubscribed` user-meta preferences.
* Add a self-service WordPress profile control without allowing administrator override of another user's preference.
* Keep verification and global suppression authoritative over registered-user preference.
* Add global email suppression using the frozen schema-1 suppression table.
* Add hashed standalone management bearers and a display-only self-service management page.
* Require nonce-protected POST for unsubscribe and verified resubscribe.
* Keep administrator suppression authoritative over all self-service resubscribe paths.
* Block suppressed addresses from public standalone signup and confirmation.
* Add administrator-managed named lists with typed user and standalone-subscriber membership.
* Keep list membership from changing subscription, resubscription, or suppression state.

= 0.1.0-alpha.4 =
* Add the dynamic public subscribe block with configurable consent text and a no-JavaScript form.
* Require an explicit nonce-protected POST after opening a confirmation link.
* Add generic standalone-signup coordination, local keyed rate limits, and confirmation mail transport.
* Begin the standalone-subscriber lifecycle and persistence foundation.
* Add secure hashed confirmation tokens with 24-hour expiry and a 15-minute resend cooldown.
* Keep subscribed, unsubscribed, and suppressed records from silently returning to pending through public signup.
* Add explicit pending-to-subscribed confirmation primitives and qualified public POST routing.
* Add daily limited cleanup for pending subscribers after a seven-day post-expiry grace period.
* Add administrator-only subscriber search/filter, manual suppression, and CSV export controls.

= 0.1.0-alpha.3 =
* Begin versioned schema migrations and create the initial plugin-owned tables.
* Add canonical normalized email identity with a persistent keyed SHA-256 hash.
* Add migration locking and preserve-by-default uninstall handling for the new data foundation.
* Add UTC persistence helpers, bounded cleanup primitives, same-schema repair, and qualified destructive uninstall handling.
* Freeze schema 1 as immutable upgrade history and qualify upgrades from both tagged schema-zero releases.
* Add exact-package Forgejo CI qualification with pinned Plugin Check static/runtime gates and WP_DEBUG review.

= 0.1.0-alpha.2 =
* Add the registered-user verification-provider contract and typed statuses.
* Integrate with the released ArgentWolf Email Verification 0.3.4 public API.
* Add provider health/version reporting, fail-closed eligibility, and an alternate-provider filter.
* Add an administrator warning and companion-backed integration tests.

= 0.1.0-alpha.1 =

* Add the initial plugin bootstrap and namespaced service container.
* Add activation, deactivation, upgrade, and uninstall skeletons.
* Select WordPress 7.0 and PHP 8.4 as the initial minimum versions.
* Add Composer, PHPUnit, WordPress Coding Standards, and JavaScript tooling.
* Add continuous integration and deterministic package-manifest validation.
* Preserve the rule that scheduled posts never create campaigns before actual publication.
