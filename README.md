# Verlo (WordPress plugin)

Verlo plans, writes and edits SEO articles at app.verlohub.com. This plugin is the publisher: it receives finished, approved articles and creates or updates the matching post, with native blocks, SEO fields and FAQ structured data.

By [EXEVE](https://exeve.global/).

## How articles arrive

Both ways are signed with the site's connection key (HMAC-SHA256 over `<timestamp>.<body>`, five minute window, constant-time check).

- **Push:** Verlo calls `POST /wp-json/verlo/v1/publish` (and `/ping`).
- **Pull:** the site asks Verlo for waiting articles every five minutes (`GET /v1/connect/pending`) and reports each result (`POST /v1/connect/result`). Works anywhere the site can reach the internet. When Verlo can reach the site it also sends a signed `POST /wp-json/verlo/v1/wake`, which makes the site collect immediately; if that cannot get through, the five-minute check still does the job.

The same article always maps to the same post (`_verlo_article_id`), so an update changes that post and a repeated delivery changes nothing.

## Setup

1. Install and activate.
2. Settings, Verlo: paste the connection ID and key from the project's Connection tab in Verlo.
3. Send test.

## Testing

`tests/push-test.php` runs through WordPress's real REST stack with WP-CLI and checks publish, update in place, idempotency, deleted posts, SEO fields, FAQ data and every rejection path:

```
wp eval-file tests/push-test.php
```

PHP syntax is linted in CI. The release workflow zips the plugin without `tests/`.
