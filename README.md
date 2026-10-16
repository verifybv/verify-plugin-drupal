<img src="media/logo_small.png" alt="Verify" width="96">

# Verify DAM for Drupal

Use your [Verify](https://joinverify.com) asset library directly inside Drupal.
Browse and search your verified images, videos, audio and PDFs, and add them to
your Drupal site with a single click — no downloading and re-uploading.

## What you get

- **Your Verify library in Drupal** — newest assets first. New uploads in
  Verify appear automatically, no page refresh needed.
- **Only verified assets** — the library only ever shows assets with the status
  *Verified*, so editors can't accidentally use unapproved content.
- **Search** — find files by name (e.g. `TMR6200` or `24-08-15_TMR6200.jpg`),
  or describe what's in the picture (e.g. `surfing`).
- **Filter by type** — show all assets, or only images, videos, audio or PDFs.
- **One-click copy to Drupal** — click an asset and it's added to your site.
  Images are copied as a web-optimised version (1000 px), so pages stay fast;
  videos, audio and PDFs are copied as the original file.
- **No duplicates** — assets already copied are marked **In Drupal**.
- **Personal, secure login** — each editor connects their own Verify API key
  once. Keys stay on your server and are never shown in full.

## Requirements

- Drupal 10.3 or Drupal 11
- PHP 8.1 or newer
- A Verify account with an API key (ask your Verify account administrator, or
  contact [info@joinverify.com](mailto:info@joinverify.com))

## Installation

### With Composer (recommended)

Run these commands in the root of your Drupal project:

```bash
composer config repositories.verify vcs https://github.com/verifybv/verify-plugin-drupal
composer require joinverify/verify_dam
vendor/bin/drush en verify_dam -y
vendor/bin/drush cr
```

### Without Composer

1. Download the latest release ZIP from the
   [Releases page](https://github.com/verifybv/verify-plugin-drupal/releases)
   (or **Code → Download ZIP**).
2. Unzip it, rename the folder to `verify_dam` and place it in
   `web/modules/custom/`.
3. In Drupal, go to **Extend**, tick **Verify DAM** and click **Install**.

## Getting started

1. **Give your editors access.** Go to **People → Permissions** and grant:

   | Permission | Allows |
   |---|---|
   | Use the Verify DAM library | Connecting a Verify API key, browsing and searching |
   | Copy Verify assets into Drupal | Adding assets to the site with a click |
   | Administer Verify DAM settings | Changing the module settings (site admins) |

2. **Open the library.** Click **Verify DAM** in the admin menu (or go to
   `/verify`).
3. **Connect your Verify account.** The first time, you're asked for your API
   key. It's checked with Verify and remembered, so you only do this once.
   You can view or disconnect it later via the **Settings** button.

## Using the library

- **Browse** — the newest verified assets are shown first.
- **Search** — type a file name or a description and press Enter (or click the
  magnifying glass). Clear the search box to return to the full library.
- **Filter** — use the type menu next to the search bar to show only images,
  videos, audio or PDFs.
- **Add to Drupal** — click an asset. A spinner shows while it's being copied;
  when it's done the asset is marked **In Drupal** and a message links to the
  new file.

Copied assets appear under **Content → Files** (and under **Content → Media**
if Drupal's Media module is enabled), ready to use in your content.

## Settings

Site administrators can adjust the module under
**Configuration → Media → Verify DAM**:

- **Live refresh interval** — how often the library checks Verify for new
  assets (default: every 15 seconds).
- **Maximum assets shown** — how many assets the library and search show
  (default 60, maximum 100).
- **Verify API base URL** — only change this if Verify asks you to.

## Updating

```bash
composer update joinverify/verify_dam
vendor/bin/drush updb -y
vendor/bin/drush cr
```

Without Composer, replace the `verify_dam` folder with the new version, then
visit `/update.php` on your site.

## Troubleshooting

| Problem | Solution |
|---|---|
| "This API key is not valid" | Check that you copied the complete key from Verify, then reconnect via **Settings**. |
| "Could not reach the Verify API" | Your server can't reach Verify. Check its internet access or firewall, then try again. |
| The library or filters don't look or work as expected after an update | Clear Drupal's caches (**Configuration → Performance → Clear all caches**, or `drush cr`) and reload the page. |
| **Verify DAM** is missing from the menu | Make sure the module is enabled and your role has the *Use the Verify DAM library* permission. |
| An asset you expect isn't shown | Only *Verified* assets are shown. Check the asset's status in Verify. |

## Privacy and security

- Your API key is stored on your own Drupal server, per user, and is never
  sent to the browser.
- All communication with Verify happens from your server over HTTPS.
- Uninstalling the module removes all stored API keys. Assets already copied
  into Drupal are kept.

## Support

Questions or problems? Contact Verify at
[info@joinverify.com](mailto:info@joinverify.com).

## License

Copyright © Verify. All rights reserved.

This module is provided to Verify customers for use with their Verify
subscription, under the terms of their agreement with Verify. No other rights
are granted.
