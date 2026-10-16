# Endpoint security review

Reviewed for 0.1.0-rc.1 (Milestones 12–13). This is a code-level review by the
development agent, not an independent audit.

## Public (logged-out) endpoints

| Endpoint | Method | Authorization | State change protection | Notes |
| --- | --- | --- | --- | --- |
| Signup form (`PublicSignupController`) | POST | none (public) | nonce, honeypot, rate limit, generic response | Responses do not reveal whether an address exists. |
| Confirmation (`ConfirmationController`) | GET → POST | opaque token (hashed at rest, expiring) | GET only shows a form; nonce-protected POST confirms | Link scanners cannot confirm by prefetching. |
| Manage subscription (`ManageSubscriptionController`) | GET → POST | opaque manage token (rotated) | nonce-protected POST | |
| Campaign unsubscribe / manage (`RecipientUnsubscribeController`) | GET → POST | per-attempt recipient token, 1-year TTL | nonce-protected POST; resubscribe bound to a one-time ledger binding, 30-day TTL | Generic result pages, `no-referrer`, no-cache. |
| RFC 8058 one-click | POST | recipient token | requires `List-Unsubscribe=One-Click` body | Idempotent. |
| Click (`ClickController`) | GET | per-attempt click token, 1-year TTL | records at most one event per request | The destination never comes from the request: it is always the post's current permalink, or home. `wp_safe_redirect`, `no-referrer`, `noindex`, no-cache. No IP address, user agent or cookie is stored. |

## Authenticated endpoints

| Area | Capability | CSRF |
| --- | --- | --- |
| Subscribers, suppression, CSV export | `manage_post_notification_subscribers` | `check_admin_referer` |
| CSV import | `manage_post_notification_subscribers` | `check_admin_referer` |
| Named lists | `manage_post_notification_lists` | `check_admin_referer` |
| Template settings, preview, test send (admin-post/AJAX) | `manage_options` | nonces |
| Delivery screen and manual queue run | `manage_options` | `check_admin_referer` |
| Privacy settings | `manage_options` | `check_admin_referer` |
| Editor REST: audience estimate, contact lookup | `send_post_notifications` (+ `edit_post`) | REST nonce; request throttle |
| Profile preference | own profile (`edit_user`) | core profile nonce |

`send_post_notifications` lets editors send without exposing subscriber management.

## Data handling

- All SQL uses `$wpdb->prepare` with `%i` for identifiers; IN lists are built only from `%d` placeholders.
- Tokens are stored as SHA-256 hashes. Email identities are keyed HMAC hashes.
- Logs (`CampaignDiagnostics`, the audit ledger) never contain email addresses or tokens.
- No telemetry, no remote code, no update checker. The only outbound mail goes through `wp_mail()`.

## Test evidence

The integration suite covers capability denial, nonce failure, token expiry and
replay, enumeration-safe responses, rate limiting, open-redirect attempts on the
click endpoint, and privacy export and erasure. Run with
`vendor/bin/phpunit -c phpunit.integration.xml.dist`.

## Known limitations

- Mail security scanners may follow tracked links, which inflates click counts. Counts are labelled approximate. Attributed clicks within the scanner window are flagged `likely_scanner`.
- Per-recipient attribution is consent-gated, off by default, capability-gated (`view_post_notification_stats`) and audit-logged on view and export. Public-form consent is stored on the pending row created by whoever submitted the form; it only takes effect after the address owner confirms, and any manage link withdraws it.
- Click and unsubscribe tokens are bearer tokens: anyone holding the email can use them until they expire.
