# WordPress.org submission readiness: 0.1.0-rc.1

Preparation only. **Nothing has been submitted**, no SVN repository exists, and
nothing has been tagged or pushed. Prepared 2026-10-15 by the development agent.

## Package

| Check | Result |
| --- | --- |
| ZIP | `dist/argentwolf-post-notifier-0.1.0-rc.1.zip`, about 250 KB (limit 10 MB) |
| Checksum | `dist/SHA256SUMS`. The value for the exact candidate is recorded in the delivery report. Rebuild from the tagged commit and re-record it before submission. |
| Contents | Runtime allowlist only: main file, `autoload.php`, `uninstall.php`, `LICENSE`, `readme.txt`, `src/`, `assets/runtime/`, `blocks/*/block.json`. No tests, vendor tree, CI files, docs or dotfiles. |
| Built assets | None. Editor JavaScript is hand-written, readable source shipped as is. |
| Bundled third-party code | None. |
| Upload install | Installed by unzipping into `wp-content/plugins` on a local WordPress 7.0.4 site with ArgentWolf Email Verification 1.0.2. Upload through **Plugins → Add New → Upload Plugin** has not been exercised: to do on staging. |

## Metadata

| Item | Value | Status |
| --- | --- | --- |
| Slug | `argentwolf-post-notifier` | Not found on wordpress.org (API: "Plugin not found"). Reserved or pending slugs are not visible, so availability is only confirmed at submission. |
| Name | ArgentWolf Post Notifier (header and readme) | OK |
| Plugin URI | Forgejo repository | OK, unique |
| Requires at least / PHP / Tested up to | 7.0 / 8.4 / 7.1 | Tested locally on 7.0.4 only. Confirm 7.1 before submission. |
| Version = Stable tag | 0.1.0-rc.1 | Match. Submit as `0.1.0` after staging validation. |
| License | GPL-2.0-or-later in header, readme and `LICENSE` | OK |
| Contributors | `thystra` (profile exists; also the companion's author) | Owner to confirm contact email |
| Requires Plugins | `argentwolf-email-verification`. Published, version 1.0.2. | OK |
| readme.txt | 5.7 KB, 5 tags, short description under 150 characters | OK |

## Directory guidelines

- No update checker, telemetry, remote code, CDN assets or external HTTP requests. Mail goes only through `wp_mail()`.
- Tracking is disclosed: the readme, the privacy-policy text and the admin screen describe click counting, and it can be turned off. Opens are never tracked.
- Data storage, retention and uninstall behaviour are documented in the readme.
- Translations load on demand from translate.wordpress.org. There is no `load_plugin_textdomain` call.

## Automated checks (local, this candidate)

| Check | Result |
| --- | --- |
| Plugin Check 2.1.0 (SHA-256 pinned as in CI), modes `new` static, `new` runtime and `update` runtime | **No errors found** in all three. Same gate script as CI (`scripts/run-plugin-check.sh`). |
| WP_DEBUG run (concurrency scripts plus Plugin Check plus admin page renders) | Only expected `wpdb` "Deadlock found" notices from the deliberate 6-worker contention test. Those statements are retried by `QueueRepository::write`. No PHP notices or warnings. |
| PHPUnit unit, integration; `tests/run.php`; PHP lint; PHPCS (src and tests) | Pass. See the delivery report for counts. |
| Concurrency: campaign reservation (5 processes) and queue leases (6 workers, 200 recipients) | Pass |

## Still open before submission

1. Run on staging: install the exact ZIP via upload, then check immediate and
   scheduled publication, registered-user verification, double opt-in,
   unsubscribe and click tracking.
2. Bump the version to `0.1.0` (header, `Version::PLUGIN`, Stable tag, blocks,
   package files, tests) and run the full CI.
3. Confirm `Tested up to: 7.1` against WordPress 7.1, or adjust it.
4. Directory assets: icon, banner, screenshots, with matching readme
   `== Screenshots ==` entries.
5. Manual review items not done by the agent: editor end-to-end tests,
   accessibility review, and an independent security review.
6. Owner tasks: whitelist `plugins@wordpress.org`, upload the ZIP, answer
   review feedback, set up SVN, and use the release workflow after approval.
