=== Verlo ===
Contributors: exeve
Tags: seo, content, publishing, articles, faq schema
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes the articles you write and approve in Verlo to your site, with the layout, SEO fields and FAQ data in place.

== Description ==

Verlo plans, writes and edits your SEO articles at app.verlohub.com. This plugin does one job: it receives the finished, approved articles and creates or updates the post on your WordPress site.

* **Native blocks.** Articles open in the block editor as real blocks, with no "convert to blocks" step.
* **SEO fields filled in.** The SEO title, description and focus keyword are written for Yoast SEO, Rank Math, SEOPress or The SEO Framework, whichever is active.
* **FAQ structured data.** Each article's FAQ is added as schema.org FAQPage data.
* **Updates in place.** When you change an article in Verlo and publish the update, the same post is updated. It is never duplicated.
* **Drafts or live.** You choose in Verlo whether new articles arrive as drafts or go live straight away.
* **Works on any site.** Verlo can send articles to your site, or your site can collect them itself, which works behind firewalls, on staging sites and on private networks.

Every request is signed with your connection key and checked before anything is touched. Nothing else on your site is read or changed.

== Installation ==

1. Install and activate the plugin.
2. In Verlo, open your project, then the Connection tab, and create a connection for a WordPress site.
3. In WordPress, open Settings, Verlo, and paste the connection ID and key.
4. Press Send test. When it says Connected, publish an article from Verlo.

== Frequently Asked Questions ==

= Does this plugin write or edit articles? =

No. Writing and editing happen in Verlo. The plugin only receives and publishes.

= What data leaves my site? =

The plugin contacts Verlo to collect waiting articles and to report whether each one was published (the post number and address). It sends no other content from your site.

= What happens if I uninstall it? =

Verlo's settings are removed. Posts that were published stay exactly as they are.

== Changelog ==

= 2.0.0 =
* Rebuilt as a publisher. Planning, writing and editing moved to Verlo.
* Signed delivery in both directions, with replay protection.
* Collect-or-receive delivery, native blocks, SEO fields, FAQ data and updates in place.
