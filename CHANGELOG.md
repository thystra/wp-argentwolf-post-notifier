<!-- ~/src/wp-argentwolf-post-notifier/CHANGELOG.md -->
# ArgentWolf Post Notifier Changelog

## 0.1.0-alpha.1 — Unreleased

- Add the initial plugin bootstrap and namespaced service container.
- Add activation, deactivation, upgrade, and uninstall skeletons.
- Select WordPress 7.0 and PHP 8.2 as the initial minimum versions.
- Add Composer, PHPUnit, WordPress Coding Standards, and JavaScript tooling.
- Resolve initial PHPCS line-length, docblock, comment, and PSR-4 filename-policy findings.
- Correct JavaScript formatting and SCSS lint targeting, require the supported npm floor,
  and make JavaScript CI use the committed lock file.
- Add continuous integration and deterministic distribution packaging.
- Correct the WordPress integration installer so prerequisite and download
  failures stop CI before PHPUnit runs with a missing test library.
- Leave the WordPress core destination absent until SVN exports core into it.
- Define the WordPress test-site constants required by the core bootstrap.
- Do not declare the unresolved WordPress.org companion dependency.
- Do not implement campaign, subscriber, delivery, unsubscribe, or statistics
  behavior in this scaffold.

<!-- EOF: ~/src/wp-argentwolf-post-notifier/CHANGELOG.md -->
