=== Verlo ===
Contributors: exeve
Tags: seo, content generation, ai writer, topical map, content strategy
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verlo plans, writes, and optimizes SEO content for your site, end to end — knowledge graph, topical map, briefs, and drafts.

== Description ==

Verlo is a WordPress plugin that plans, writes, and optimizes SEO content for your site, end to end. It builds a knowledge graph of your existing content, designs a topical map of pillars and planned articles, turns each into a content brief, and generates publish-ready draft articles, complete with on-page SEO, internal links, and stock images, for your review before publishing.

Verlo is a thin client. All AI intelligence lives in the Verlo service, a separate hosted backend this plugin talks to over a job API. The plugin itself never calls an AI model directly, and no AI provider is ever named in anything the plugin shows you.

**The pipeline**

1. **Knowledge Graph** — Verlo crawls and indexes your site's existing published content.
2. **Strategy Profile** — your site's niche, audience, and brand voice, inferred from your content or set by hand.
3. **Topical Map** — a structured plan of content pillars and the individual articles under each one.
4. **Content Briefs** — an outline, target intent, and internal links for each planned article, generated before the full draft.
5. **Article Generation** — a publish-ready draft with on-page SEO, internal links, and relevant images.

Every generated post is created as a WordPress draft. Nothing publishes automatically — you review, edit, and approve everything before it goes live.

= Requirements =

* A Verlo account and license key (connects the plugin to the hosted backend)
* WordPress 6.0 or newer
* PHP 7.4 or newer

== Installation ==

1. Install and activate the plugin, either by uploading the plugin ZIP or through the WordPress plugin directory.
2. In your WordPress admin, go to **Verlo → Strategy Profile**.
3. Sign up for a Verlo account and connect a license key from your account's dashboard.
4. Fill in your site's niche, audience, and voice, or let Verlo infer it from your existing content.
5. Generate a topical map, approve it, then generate content briefs and articles from there.

== Frequently Asked Questions ==

= Will Verlo publish anything without my approval? =

No. Every generated article is created as a WordPress draft. Nothing is published automatically at any stage of the pipeline.

= Does Verlo work without a license key? =

No — the plugin is a thin client for the hosted Verlo service, and a valid license key is required to submit jobs (analysis, topical maps, briefs, and articles).

= Can I move a license key to a different site? =

Yes, but a key is only active on one site at a time. Remove it from the current site before connecting it elsewhere.

= What AI model does Verlo use? =

Verlo doesn't expose which AI provider or model it uses in any part of the plugin, its output, or its support channels — this is intentional and not configurable.

== Changelog ==

= 1.1.9 =
* Deleting the plugin (not just deactivating it) now fully removes its data: the knowledge-graph database tables, and every stored option, including the encrypted license key and connection token. Previously these were left behind indefinitely.

= 1.1.8 =
* The "Generated articles" history now keeps every past version of a regenerated draft, not just the latest — regenerating an article no longer erases the record of what it looked like before.

= 1.1.7 =
* Prior release. See the full user guide at https://verlohub.com/guide for setup and usage details.

== Upgrade Notice ==

= 1.1.9 =
Deleting the plugin now cleans up its database tables and stored options instead of leaving them behind.

= 1.1.8 =
Schema migrations run automatically on plugin update — no manual steps required.
