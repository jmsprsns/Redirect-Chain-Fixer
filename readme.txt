=== Redirect Chain Fixer ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: redirects, redirect chains, internal links, broken links, seo
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds links in your posts and pages that pass through redirects and points them straight at the final URL. Internal links are fixed automatically; external ones wait for your approval.

== Description ==

Every time a page moves, the links to it keep working through a redirect. Move it again and you have a redirect chain: visitors and search engines hop from URL to URL before they reach the page. Each hop adds a delay, and search engines may stop following long chains.

Redirect Chain Fixer checks every link in your posts and pages, follows its redirects to the page it really ends up on, and updates the link to point there directly.

= Features =

* Internal links (links to your own site) are fixed automatically, as soon as they're found.
* External links (links to other websites) go into a Needs review list. Approve a change, or ignore it to leave the link as it is.
* Only permanent redirects (301 and 308) are followed, and only to a page that loads. Temporary redirects (login pages, maintenance pages, geo redirects), loops and redirects to missing pages are left alone.
* Cloaked affiliate links on your own site (Pretty Links, ThirstyAffiliates, /go/ links) are left alone, and you can list domains that are never checked, such as link shorteners.
* Keeps root-relative links root-relative, keeps #anchors, and updates the copy of a link that some blocks keep in their settings, so the block editor doesn't complain.
* Every change is logged with the old and new link and can be undone. Each change also saves a revision of the post.
* Updating a link doesn't change a post's "last modified" date.
* Scans in the background: a full scan weekly (or daily, monthly or never), plus each post a minute or so after it's saved. Click Scan now to watch a scan run.
* Posts, pages and any other public content type.

Find it under Content Powered > Redirect Chain Fixer in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `redirect-chain-fixer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress. The first scan starts in the background.
3. Go to Content Powered > Redirect Chain Fixer to see what it fixed and review external links.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= What's a redirect chain? =

A link that passes through more than one redirect before it reaches a page, such as http://example.com/old-page to https://example.com/old-page to https://example.com/new-page. The plugin also fixes links that pass through a single redirect, since linking straight to the page is always faster.

= Why are external links only changed after I approve them? =

Other websites can redirect for reasons that aren't obvious from the outside: an old article that now sends everyone to the homepage, a link shortener whose click counts you rely on, an affiliate link. The plugin shows you where each link ends up, warns you when it lands on a homepage or another website, and leaves the decision to you.

= Can I undo a change? =

Yes. Every change is listed under Fixed. Undo puts the link back the way it was and stops the plugin from changing it again. If the post has been edited since, use its Revisions screen instead: each change saved a revision.

= Does it change my content in any other way? =

No. Only the link address changes. The link text, the rest of the post and its "last modified" date stay the same.

= Does it check links in page builders? =

It checks the post content that the block editor and classic editor save. Page builders that store their layouts separately (Elementor, Divi, Beaver Builder) aren't scanned.

= Is it safe to run on a live site? =

Yes. Checks run in short background batches, each URL is checked once per scan however many posts use it, and only your own site and public websites are contacted (never private or internal network addresses). Settings and the review list are only available to administrators.

== Changelog ==

See https://github.com/jmsprsns/Redirect-Chain-Fixer/releases for the full release history.
