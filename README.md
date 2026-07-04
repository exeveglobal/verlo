# Verlo

Verlo is a WordPress plugin that plans, writes, and optimizes SEO content for your site, end to end. It builds a knowledge graph of your existing content, designs a topical map of pillars and planned articles, turns each into a content brief, and generates publish-ready draft articles, complete with on-page SEO, internal links, and stock images, for your review before publishing.

By [EXEVE](https://exeve.global/).

## How it works

Verlo is a thin client. All AI intelligence lives in the Verlo SaaS, a separate service this plugin talks to over a job API. The plugin itself never calls an AI model directly.

```
WordPress site  --(reads)-->  Plugin  --(jobs)-->  Verlo SaaS  --(AI)-->  model
WordPress site  <-(applies)-- Plugin  <-(results)- Verlo SaaS
```

The pipeline: **Knowledge Graph** (crawl existing content) -> **Strategy Profile** (site niche, audience, voice) -> **Topical Map** (pillars and planned articles) -> **Content Briefs** (outline, intent, internal links) -> **Article Generation** (draft with SEO meta and images).

Every generated post is created as a draft. Nothing publishes automatically.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Verlo license key (connects the plugin to the SaaS backend)

## Getting started

1. Install and activate the plugin.
2. Go to Verlo -> Strategy Profile and connect with your license key.
3. Fill in your site's niche, audience, and voice (or let Verlo infer it from existing content).
4. Generate a topical map, approve it, then generate briefs and articles from there.

## Development

No build step. Plain PHP/JS/CSS following WordPress coding conventions.

- Lint any changed PHP file: `php -l path/to/file.php`
- Bump the version in both the plugin header and the `VERLO_VERSION` constant in `verlo.php`
- All identifiers are prefixed `Verlo_` / `VERLO_` / `verlo_`

## License

GPL-2.0-or-later
