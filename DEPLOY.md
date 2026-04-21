# Deployment runbook — SignDocs Brasil WP plugin

Two distribution channels:

1. **GitHub Releases** — every `v*` tag produces a downloadable zip at
   `github.com/signdocsbrasil/signdocs-brasil-wordpress/releases`. Active
   from day one.
2. **WordPress.org plugin directory** — pending WP.org submission +
   review approval. Once approved, every `v*` tag also syncs to the
   WP.org SVN repo at `plugins.svn.wordpress.org/signdocs-brasil/` and
   auto-updates propagate to every install within hours.

The WP.org channel is gated by the repo variable `DEPLOY_TO_WPORG`, so
it's a no-op until you flip the switch post-approval.

---

## WordPress.org submission — one-time

### 1. Submit the plugin

- Go to https://wordpress.org/plugins/developers/add/ while signed in
  to wordpress.org with an account whose username you'll use as the
  plugin's SVN owner.
- Upload `signdocs-brasil-<VERSION>.zip` from the latest GitHub Release
  at https://github.com/signdocsbrasil/signdocs-brasil-wordpress/releases.
- Plugin name: `SignDocs Brasil`.
- Description: matches the top of `readme.txt`.

### 2. Respond to reviewers

Review takes 1-10 business days. Reviewers read the code and typically
ask about:

- **External HTTP calls** — we call `api.signdocs.com.br` and load the
  browser JS SDK from `cdn.signdocs.com.br`. Document this clearly in
  the reply; reviewers need to see it's first-party to a specific
  business, not "phone home".
- **GDPR / LGPD** — we ship `wp_privacy_personal_data_exporters` and
  `wp_privacy_personal_data_erasers` handlers (see `src/Privacy/`).
- **Security** — reviewers check output escaping, nonce verification,
  capability checks, and prepared SQL. All of these are covered; see
  `src/Webhook/Controller.php` for the webhook HMAC posture and
  `src/Admin/` for the admin-page surface.
- **Commercial vs free** — the plugin itself is GPL-2.0-or-later and
  freely downloadable; the API it talks to is commercial. Reviewers
  accept this pattern (Jetpack, Stripe Payments, WPForms Lite, etc.)
  as long as it's disclosed on the plugin page.

If reviewers ask for changes, fix locally, tag a new patch version
(e.g. `v1.2.1`), push, and reply to the review email linking the new
tag.

### 3. Approval — one-time setup

When the approval email arrives it will include:

- The official plugin slug (should be `signdocs-brasil`).
- The SVN URL (`https://plugins.svn.wordpress.org/signdocs-brasil/`).
- The SVN committer username (the wordpress.org account that submitted).

Configure the GitHub repo with the credentials the deploy workflow
needs. Replace `<SVN_USERNAME>` and `<SVN_PASSWORD>` below with the
real values.

```bash
gh secret set SVN_USERNAME -R signdocsbrasil/signdocs-brasil-wordpress --body '<SVN_USERNAME>'
gh secret set SVN_PASSWORD -R signdocsbrasil/signdocs-brasil-wordpress --body '<SVN_PASSWORD>'
gh variable set DEPLOY_TO_WPORG -R signdocsbrasil/signdocs-brasil-wordpress --body 'true'
```

The `SVN_PASSWORD` is the wordpress.org account's **password**, not a
separate SVN token (wordpress.org SVN authenticates with the same
credentials as the wordpress.org account).

### 4. Seed the SVN repo — first push

Immediately after flipping `DEPLOY_TO_WPORG`, trigger a re-run of the
latest tag's deploy workflow so the first publish happens:

```bash
gh workflow run wp-org-deploy.yml -R signdocsbrasil/signdocs-brasil-wordpress --ref v1.2.0
```

Watch the run:

```bash
gh run watch -R signdocsbrasil/signdocs-brasil-wordpress
```

On first run, the `10up/action-wordpress-plugin-deploy` action will:

1. Check out the git tag.
2. `svn co` the (empty) WP.org SVN repo.
3. Copy the plugin tree into `trunk/` and `tags/<version>/`.
4. Copy `.wordpress-org/*.png` into `assets/`.
5. `svn commit` with the tag commit message.

Propagation to https://wordpress.org/plugins/signdocs-brasil/ typically
takes a few minutes after `svn commit` succeeds.

---

## Cutting new releases (after WP.org approval)

1. Bump the version in three places (one commit):
   - `signdocs-brasil.php` header `Version:` + `SIGNDOCS_VERSION`
   - `readme.txt` `Stable tag:` + the top changelog entry
   - `composer.json` `version` (if set)

2. Tag and push:

   ```bash
   git tag vX.Y.Z
   git push origin main
   git push origin vX.Y.Z
   ```

3. GitHub Actions does the rest:
   - `ci.yml` runs tests on the tag
   - `wp-org-deploy.yml` syncs to WP.org SVN (if `DEPLOY_TO_WPORG == 'true'`)

4. After a few minutes, confirm the new version is visible at:
   - https://wordpress.org/plugins/signdocs-brasil/
   - https://github.com/signdocsbrasil/signdocs-brasil-wordpress/releases/tag/vX.Y.Z

---

## Rollback

### GitHub Release
Delete the release (keeps the tag):
```bash
gh release delete vX.Y.Z -R signdocsbrasil/signdocs-brasil-wordpress
```

### WordPress.org
You **cannot** unpublish a version from the WP.org SVN repo, but you
can point `Stable tag:` in `readme.txt` at a previous version:

1. In a new commit on `main`, edit `readme.txt` → `Stable tag: X.Y.(Z-1)`.
2. Bump plugin version to `X.Y.Z+hotfix` to make clear this is a patch.
3. Push and tag `vX.Y.Z+hotfix`.

WP.org only serves "stable tag" to auto-updaters, so the broken version
stops being pushed to sites even though it still exists in SVN history.

---

## FAQ

**Q: My SVN push fails with `403 Forbidden`.**
Check that `SVN_USERNAME` / `SVN_PASSWORD` match the wordpress.org
account that owns the plugin, not a forum login. Password changes on
wordpress.org also change the SVN password — rotate the secret.

**Q: Assets don't show up on the WP.org plugin page.**
Assets live in the SVN `assets/` folder (not inside `tags/` or `trunk/`).
The deploy action reads `.wordpress-org/` and writes to SVN `assets/`.
Check the deploy log; if it says "no assets found", confirm PNGs
exist in `.wordpress-org/` at the tag you pushed.

**Q: WP.org says "Stable tag doesn't match a tag in the repo."**
This is the most common release-day mistake. Every version in
`readme.txt` `Stable tag:` MUST exist as a directory under SVN `tags/`.
If you bump `Stable tag: 1.2.0` but only pushed up to `1.1.5`, the
auto-updater serves nothing. Fix: tag `v1.2.0` and re-run the workflow.

**Q: Can I test the deploy without actually publishing?**
Yes — before flipping `DEPLOY_TO_WPORG`, tag a prerelease (e.g.
`v1.2.0-rc1`) and manually dispatch the workflow with the prerelease
tag. The workflow's `if: vars.DEPLOY_TO_WPORG == 'true'` guard will
cause the job to skip without side effects. Once you flip the variable,
the next prerelease tag pushes to SVN — test with `v<next>-rc1` if
you want a dry run against a real SVN repo.
