# Verify DAM

Browse, search and copy assets from the [Verify](https://joinverify.com) DAM
inside Drupal 10.3+ / 11.

## Features

- **One-time login:** each editor connects their own Verify API key once; it's
  remembered on their Drupal account.
- **Live library:** verified assets, newest first. New uploads in Verify show up
  automatically (the page refreshes every 15 seconds).
- **Search:** file names are matched first (`Photo01.jpg` finds that file);
  otherwise Verify's AI search is used.
- **Copy to Drupal:** click an asset to download the full-resolution original
  into Drupal files (and a Media item, if the Media module is enabled).

## Installation

Add the Verify repository to your Drupal project's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/verifybv/verify-plugin-drupal"
    }
]
```

Then install and enable the module:

```
composer require joinverify/verify_dam
vendor/bin/drush en verify_dam -y
vendor/bin/drush cr
```

If the repository is private, first give Composer a GitHub token that has
read access to it (provided by Verify):

```
composer config --global github-oauth.github.com <token>
```

Updating later: `composer update joinverify/verify_dam`.

Without Composer: download a release zip, place it at
`web/modules/custom/verify_dam` and enable **Verify DAM** under *Extend*.

Then grant permissions under *People → Permissions*:

| Permission | Purpose |
|---|---|
| Use the Verify DAM library | Connect an API key, browse and search |
| Copy Verify assets into Drupal | Click-to-copy assets into Drupal |
| Administer Verify DAM settings | Change module settings |

## Usage

1. Open **Verify DAM** in the admin menu (`/verify`).
2. The first time, enter your Verify API key. It's checked against the API and
   saved, so you only do this once. Use the **Settings** button to view or
   disconnect it.
3. Search with the search bar (Enter or the magnifying glass).
4. Click an asset to copy it into Drupal. Copied assets show an **In Drupal**
   badge; clicking one again doesn't create a duplicate.

Copied files are stored in `public://verify/YYYY-MM/` and listed under
*Content → Files* (and *Content → Media* when Media is enabled).

## Configuration

*Configuration → Media → Verify DAM* (`/admin/config/media/verify`):

- Verify API base URL
- Live refresh interval
- Maximum number of assets shown
- Only show verified assets (on by default)

## How it works

- All Verify API calls happen server-side with the `x-api-key` header; API
  keys never reach the browser. The settings page shows only the last 4
  characters.
- API keys are stored per user in Drupal's `user.data` table.
- Verify's API offers AI search (max. 20 results per call) but no list
  endpoint, so the module remembers every asset it has seen (key-value store,
  per API key) to keep the library stable and complete over time.
- Uninstalling removes stored API keys and the module's bookkeeping; copied
  files and media items are kept.

## Requirements

- Drupal 10.3 or 11, PHP 8.1+
- A Verify account and API key

## License

Copyright © Verify. All rights reserved.

This module is provided to Verify customers for use with their Verify
subscription, under the terms of their agreement with Verify. No other rights
are granted.
