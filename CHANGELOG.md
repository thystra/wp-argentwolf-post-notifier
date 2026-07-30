<!-- ~/src/wp-argentwolf-post-notifier/CHANGELOG.md -->
# ArgentWolf Post Notifier Changelog

## 0.1.0-alpha.2 — Unreleased

- Add a typed registered-user verification-provider contract.
- Integrate with the released ArgentWolf Email Verification 0.3.4 public API.
- Add provider version and health reporting with fail-closed eligibility.
- Add the alternate-provider filter and an administrator health warning.
- Add unit and companion-backed WordPress integration tests.
- Deliberately omit private companion metadata access and mail-success inference.

## 0.1.0-alpha.1 — 2026-07-29

- Add the initial plugin bootstrap and namespaced service container.
- Add activation, deactivation, upgrade, and uninstall skeletons.
- Select WordPress 7.0 and PHP 8.4 as the initial minimum versions.
- Add Composer, PHPUnit, WordPress Coding Standards, and JavaScript tooling.
- Resolve initial PHPCS line-length, docblock, comment, and PSR-4 filename-policy findings.
- Correct JavaScript formatting and SCSS lint targeting, require the supported npm floor,
  and make JavaScript CI use the committed lock file.
- Add continuous integration and deterministic distribution packaging.
- Correct the WordPress integration installer so prerequisite and download
  failures stop CI before PHPUnit runs with a missing test library.
- Leave the WordPress core destination absent until SVN exports core into it.
- Define the WordPress test-site constants required by the core bootstrap.
- Use PHPUnit 9.6, the supported runner for WordPress 7.0 integration tests.
- Do not declare the unresolved WordPress.org companion dependency.
- Do not implement campaign, subscriber, delivery, unsubscribe, or statistics
  behavior in this scaffold.

<!-- EOF: ~/src/wp-argentwolf-post-notifier/CHANGELOG.md -->
