# Redirect Chain Fixer

Finds links in your posts and pages that pass through redirects and points them straight at the final URL. Internal links are fixed automatically; external ones wait for your approval.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## Features

- **Internal links fixed automatically.** A link to your own site that redirects (an old slug, a missing trailing slash, `?p=123`, http to https, a chain of moves) is pointed straight at the final page as soon as it's found.
- **External links wait for approval.** Links to other sites go into **Needs review** with every hop shown. Approve or ignore them one at a time or in bulk. Rows that need a second look are flagged: lands on the homepage, moves to another website, or the final page turned the checker away.
- **Only safe redirects count.** Only permanent redirects (301, 308) are followed, and only to a page that loads (2xx, or 401/403 for members-only and bot-protected pages). Temporary redirects (302, 303, 307), loops, runaway chains and redirects to error pages are never applied. A 301 followed by a 302 is shortened to the 302 hop.
- **Leaves deliberate redirects alone.** Internal links that lead to another site (Pretty Links, ThirstyAffiliates, `/go/` links) are skipped, and any domain can be listed under **Skip these domains** (affiliate networks, link shorteners).
- **Careful edits.** Root-relative links stay root-relative, `#anchors` are kept, and a block's own copy of the URL (in its `<!-- wp:... -->` attributes) is updated with the link, so the block editor doesn't flag the block.
- **Every change can be undone.** The **Fixed** list shows each change, when and by whom (or "automatically"). Undo restores exactly the spots it changed, and marks the link as ignored. Each change also saves a revision; posts with no revisions get one of their old content first.
- **Quiet saves.** A fix doesn't change the post's "last modified" date, so sitemaps and "Updated on" dates don't move for a link change.
- **Background scanning.** A full scan runs weekly by default (daily, monthly or off), and each post is checked about 30 seconds after it's saved. **Scan now** runs a scan with a progress bar.

## How a scan works

1. Batches run on WP-Cron (20 seconds each) and, while **Scan now** is open, over AJAX (3 seconds each). An atomic lock in `wp_options` stops two batches running at once, and a batch that dies gives the lock up after two minutes.
2. Each batch first scans posts saved since the last batch (the queue), then carries on with the full scan, in post ID order. A post that someone has open in the editor is skipped and retried a few minutes later.
3. Links are the `href` of every `<a>` in `post_content`. Anchors, `mailto:`, `tel:`, `javascript:`, shortcodes and admin or login links are ignored.
4. Each URL is checked once per scan, however many posts use it (`wp_redirect_chain_fixer_urls`). Checks follow redirects one hop at a time, HEAD first and GET if HEAD is refused, with a 7 second timeout and at most 10 hops.
5. Fixes are saved through `wp_update_post()` (caches clear, revisions save) and logged in `wp_redirect_chain_fixer_fixes`, which is also the review queue and the ignore list.

Published and scheduled posts of the chosen types are scanned (posts and pages by default).

## Security

- **No requests to private networks.** Every request goes through `wp_safe_remote_head()` / `wp_safe_remote_get()` with redirects off, so WordPress validates each hop before it's made: http or https only, ports 80, 443 and 8080, no credentials in the URL, and no loopback, private or reserved IP addresses (only the site's own host is allowed to resolve locally). The `redirect_chain_fixer_request_args` filter can't turn this off.
- **Nothing new reaches post content.** A fix only changes the URL inside an existing `href` (and the matching block attribute). New URLs must be absolute http(s) URLs or root-relative paths, pass `esc_url_raw()` and are written with `esc_attr()`; block attributes are re-encoded with core's `serialize_block_attributes()`. Content isn't run through kses on these saves, because there's no user during WP-Cron and kses would strip embeds an administrator added; since only URLs change, no one can get markup into a post this way.
- **If anything goes wrong, the post is left alone.** A regex failure (for example the backtrack limit on a huge post) returns the original content, never an empty one.
- **Admins only.** The page, settings, every row and bulk action and the AJAX endpoint need `manage_options` and a nonce; applying or undoing a fix also needs `edit_post` for that post. Actions redirect afterwards, so refreshing can't repeat them.
- **Clean uninstall.** Deleting the plugin drops both tables and removes its options and scheduled events, on every site of a multisite network.

## Hooks

```php
// Change the arguments for the checker's HTTP requests (timeout, user agent, headers).
// redirection = 0 and reject_unsafe_urls = true are always re-applied afterwards.
add_filter( 'redirect_chain_fixer_request_args', function ( $args, $url ) {
	$args['timeout'] = 10;
	return $args;
}, 10, 2 );
```

## Automatic updates

Updates come from https://github.com/jmsprsns/Redirect-Chain-Fixer through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `redirect-chain-fixer.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `redirect-chain-fixer.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'REDIRECT_CHAIN_FIXER_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'REDIRECT_CHAIN_FIXER_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
redirect-chain-fixer.php                         Plugin header, constants, boot, activation
uninstall.php                                    Drops the tables, removes options and scheduled events
readme.txt                                       WordPress readme; feeds the "View details" popup
includes/
  class-redirect-chain-fixer-plugin.php          Main singleton and settings
  class-redirect-chain-fixer-scanner.php         Batches, queue, lock, fixing, approve/undo/ignore/restore
  class-redirect-chain-fixer-checker.php         Follows a URL's redirects one hop at a time
  class-redirect-chain-fixer-content.php         Finds and replaces links (HTML and block attributes)
  class-redirect-chain-fixer-db.php              The two tables and their queries
  class-redirect-chain-fixer-admin.php           Content Powered > Redirect Chain Fixer page, settings, actions, AJAX
  class-redirect-chain-fixer-list-table.php      Needs review, Fixed and Ignored lists
  class-redirect-chain-fixer-menu.php            Shared top-level "Content Powered" menu
  class-redirect-chain-fixer-feedback.php        Shared Submit Feedback and Report a Bug pages
  class-redirect-chain-fixer-updater.php         GitHub update checks (PUC wiring)
  class-redirect-chain-fixer-icons.php           Inline Lucide icon helper
assets/
  admin.css, admin.js                            Loaded only on the plugin's page
  icon.svg                                       Icon on the update screens
  content-powered.svg                            Sumo logo for the Content Powered menu
icons/                                           The Lucide icons the page uses (plus LICENSE)
plugin-update-checker/                           Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every Content Powered plugin puts its page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Redirect Chain Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-redirect-chain-fixer-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

## Feedback and bug reports

Every plugin also carries `class-redirect-chain-fixer-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
