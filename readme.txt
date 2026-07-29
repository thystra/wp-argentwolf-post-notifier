=== ArgentWolf Post Notifier ===
Contributors: thystra
Tags: email, notifications, posts, subscribers
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0-alpha.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Development scaffold for verified, unsubscribe-capable post notification campaigns created after posts are actually published.

== Description ==

ArgentWolf Post Notifier is currently an alpha development scaffold.

The plugin establishes its bootstrap, service container, lifecycle handlers,
version tracking, development tooling, tests, continuous integration, and
distribution packaging. Campaign creation, subscriber collection, email
delivery, unsubscribe handling, and statistics are not implemented in this
alpha.

The intended design creates an explicit immutable campaign only after WordPress
actually publishes a post. Scheduling a post must not create a campaign or send
email.

Registered-user verification remains a runtime integration with the separately
developed ArgentWolf Email Verification plugin. The formal Requires Plugins
header will be added only after the companion WordPress.org slug is approved.

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

It is the planned authoritative provider for registered-user verification.
During development, integration is discovered at runtime. A formal WordPress.org
dependency will be added only after the companion plugin is approved there.

== Changelog ==

= 0.1.0-alpha.1 =

* Add the initial plugin bootstrap and namespaced service container.
* Add activation, deactivation, upgrade, and uninstall skeletons.
* Select WordPress 7.0 and PHP 8.2 as the initial minimum versions.
* Add Composer, PHPUnit, WordPress Coding Standards, and JavaScript tooling.
* Add continuous integration and deterministic package-manifest validation.
* Preserve the rule that scheduled posts never create campaigns before actual publication.
