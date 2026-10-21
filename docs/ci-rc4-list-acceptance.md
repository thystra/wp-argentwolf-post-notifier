# RC4 CSV-to-list acceptance in Forgejo CI

The authoritative workflow is `.forgejo/workflows/ci.yml` on Forgejo.
GitHub is only the downstream push mirror. The `package` CI job creates a fresh
WordPress installation and isolated `mysql:8.4` service database, installs the
verified Email Verification companion, then activates the exact built AWPN ZIP.

The `Qualify RC4 CSV-to-list shared-consent lifecycle` step runs:

```bash
AWPN_RUN_ACCEPTANCE=1 wp --path="$WP_ROOT" eval-file \
  "$FORGEJO_WORKSPACE/tests/Integration/RC4ListCsvAcceptance.php"
```

The workflow sets `WP_ENVIRONMENT_TYPE` to `staging` and verifies `DB_NAME`,
`DB_HOST`, plugin activation, and environment type before running the test.
The test itself also refuses to run outside WP-CLI or without explicit opt-in
and a `local`/`staging` WordPress environment. **It is not self-provisioning**;
CI is responsible for creating the disposable installation and database.

The test captures confirmation mail in memory and does not send it to a real
recipient. It checks central pending consent, one invitation for repeated
imports, multiple pending list memberships, removal before consent, one
confirmation, deduplication across overlapping audiences, copied list
memberships, and global unsubscribe. It attempts to remove its fixtures, but
only the ephemeral CI database provides full data isolation.

The acceptance check is run against the **installed distribution ZIP**, not
only the development checkout. The `php` job independently runs the related
consent/provider smoke checks on PHP 8.4 and 8.5.

This is not yet an assertion of a real email provider's delivery or a complete
campaign queue end-to-end test. Those need separate tests without external mail.

To run the CI gates, push a feature branch to **Forgejo** and open a Forgejo
pull request to `main` (or use `workflow_dispatch`). The current workflow
runs automatically on PRs and `main` pushes, not on every standalone feature
branch push. Verify the `php`, `wordpress`, `javascript`, and `package` jobs
green before merging. Do not push directly to GitHub.
