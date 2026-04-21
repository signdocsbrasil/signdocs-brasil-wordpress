# `.wordpress-org/` — plugin directory assets

Files in this directory are NOT shipped inside the plugin zip. They're
published to the plugin's WordPress.org listing via the `assets/` folder
of the WP.org SVN repo. The `10up/action-wordpress-plugin-deploy` action
in `.github/workflows/wp-org-deploy.yml` takes care of syncing them.

## Required sizes (WP.org spec)

| File | Size | Shown in |
|---|---|---|
| `icon-256x256.png` | 256 × 256 | Plugin directory list, plugin admin search, block inserter |
| `icon-128x128.png` | 128 × 128 | Retina fallback / older themes |
| `banner-1544x500.png` | 1544 × 500 | Plugin page header (desktop) |
| `banner-772x250.png` | 772 × 250 | Plugin page header (mobile / fallback) |
| `screenshot-1.png` | 1280 × 720 (recommended) | First entry in the "Screenshots" section of the plugin page |
| `screenshot-2.png` ... | 1280 × 720 | Subsequent screenshots |

Screenshot captions come from `readme.txt` under `== Screenshots ==`,
one line per `screenshot-N.png` (in order).

## What's already here

- `icon-256x256.png` / `icon-128x128.png` — derived from the canonical
  `signdocs-icon-512.png`.
- `banner-1544x500.png` / `banner-772x250.png` — auto-generated with
  the brand palette (navy `#0B2545` background, `#8EC5FF` accent,
  white wordmark). **Designer should replace these** with a properly
  typeset banner before WP.org listing goes live — the auto-generated
  versions are functional placeholders, not marketing quality.

## Screenshots — TODO before submission

Need 4-6 screenshots captured from a real WordPress install with the
plugin configured. Suggested coverage (in the order they should appear
in `readme.txt`):

1. **Settings page** — credentials + environment toggle (HML/prod) +
   default policy dropdown.
2. **Gutenberg block** — inserted in a post with the signing button
   preview and the right-sidebar config (document ID, policy, mode).
3. **Verification admin page** — with a completed envelope result
   showing signer table + consolidated download links.
4. **Audit log** — `WP_List_Table` view with filter controls on top
   and a mix of `info` / `warning` entries.
5. **Envelope edit screen** — multi-signer repeater + sequential /
   parallel toggle.
6. **WooCommerce product tab** — "SignDocs Assinatura" tab with
   document ID + policy selector.

Save as `screenshot-1.png` through `screenshot-6.png` in this directory.

## Caption block for `readme.txt`

Paste this (adjust to your final screenshots) under `== Screenshots ==`:

```
== Screenshots ==

1. Configure credentials, environment, and signing defaults.
2. Embed a signing button via the Gutenberg block — zero-code.
3. Verify an evidence pack or multi-signer envelope from WP admin.
4. Audit log with filter controls and CSV export.
5. Multi-signer envelope with sequential or parallel signing.
6. WooCommerce integration — auto-send signing links on order completion.
```
