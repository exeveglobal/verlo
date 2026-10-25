<?php
// Simulates Verlo pushing signed articles to this site, through WordPress's real REST stack.
// A real delivery is not made by an administrator, so WordPress filters the content it saves (this strips <script>
// tags and leaves their text behind). Run every check that way, as it happens on a live site.
wp_set_current_user( 0 );
kses_init_filters();
$key  = 'vlo_' . str_repeat('a1B2', 12);
$dest = '11111111-2222-4333-8444-555555555555';
Verlo_Settings::update( array( 'destination_id' => $dest, 'key' => $key ) );
function req( $path, $payload, $key, $headers_extra = array(), $mutate = null ) {
	$body = is_string( $payload ) ? $payload : wp_json_encode( $payload );
	$h    = Verlo_Signature::headers( $key, $body, $headers_extra );
	$send = $mutate ? $mutate( $body ) : $body;
	$r = new WP_REST_Request( 'POST', '/verlo/v1/' . $path );
	foreach ( $h as $k => $v ) { $r->set_header( $k, $v ); }
	$r->set_header( 'content-type', 'application/json' );
	$r->set_body( $send );
	return rest_do_request( $r );
}
$article = array(
	'id' => 'art-1', 'external_id' => null, 'version' => 1, 'title' => 'How to descale a kettle', 'slug' => 'descale-kettle',
	'meta_description' => 'A short guide to descaling a kettle safely at home.', 'excerpt' => 'Descale safely.', 'focus_keyword' => 'descale kettle',
	'content_html' => '<p>Intro paragraph about descaling.</p><h2>Why it matters</h2><p>Limescale slows heating.</p><ul><li>One</li><li>Two</li></ul><h2>FAQ</h2><h3>How often?</h3><p>Every month for hard water areas, or when you see scale.</p><script>alert(1)</script>',
	'faq' => array(),
);
$payload = array( 'event' => 'article.publish', 'attempt_id' => 'a1', 'article' => $article, 'destination' => array( 'post_mode' => 'draft', 'config' => array( 'category' => 'Guides' ) ), 'site' => array( 'url' => 'http://127.0.0.1:8099' ) );
$out = array();
$ok = function ( $name, $cond, $detail = '' ) use ( &$out ) { $out[] = ( $cond ? 'PASS ' : 'FAIL ' ) . $name . ( $detail ? " — $detail" : '' ); };

// 1. ping
$r = req( 'ping', array( 'event' => 'ping' ), $key );
$ok( 'ping accepted', 200 === $r->get_status() && ! empty( $r->get_data()['ok'] ), json_encode( $r->get_data() ) );

// 2. first publish creates a draft
$r = req( 'publish', $payload, $key, array( 'X-Verlo-Idempotency-Key' => 'art-1:v1:create' ) );
$d = $r->get_data();
$ok( 'publish 200', 200 === $r->get_status(), json_encode( $d ) );
$id = (int) ( $d['external_id'] ?? 0 );
$p = get_post( $id );
$ok( 'post exists as draft', $p && 'draft' === $p->post_status );
$ok( 'title and slug', $p && 'How to descale a kettle' === $p->post_title );
$ok( 'blocks used', $p && false !== strpos( $p->post_content, '<!-- wp:heading' ) && false !== strpos( $p->post_content, '<!-- wp:list' ) );
$ok( 'script removed', $p && false === strpos( $p->post_content, 'alert(1)' ) );
// FAQ structured data: kept in post meta and printed in the page head, never in the body (a script in the body is
// stripped on a live site and its JSON ends up visible as text).
$ok( 'no schema text or script left in the post body', $p && false === strpos( $p->post_content, 'FAQPage' ) && false === strpos( $p->post_content, 'ld+json' ) && false === strpos( $p->post_content, '@context' ) );
$stored = get_post_meta( $id, '_verlo_faq_jsonld', true );
$ok( 'FAQ schema stored as post meta', is_string( $stored ) && false !== strpos( $stored, '"@type":"FAQPage"' ) && false !== strpos( $stored, 'How often?' ), (string) $stored );
// Reading the page is a different request from the delivery: an administrator previewing the draft.
wp_set_current_user( 1 );
$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $id, 'post_status' => 'any' ) );
ob_start(); Verlo_Faq_Schema::output(); $head = ob_get_clean();
$ok( 'FAQ schema printed once in the page head as a script', 1 === substr_count( $head, 'application/ld+json' ) && false !== strpos( $head, '"@type":"FAQPage"' ) && false !== strpos( $head, '</script>' ), $head );
$ld = json_decode( trim( preg_replace( '#</?script[^>]*>#', '', $head ) ), true );
$ok( 'the printed schema is valid JSON with the question and answer', is_array( $ld ) && 'Question' === ( $ld['mainEntity'][0]['@type'] ?? '' ) && 'How often?' === ( $ld['mainEntity'][0]['name'] ?? '' ) );
$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'post', 'posts_per_page' => 2 ) );
ob_start(); Verlo_Faq_Schema::output(); $listing = ob_get_clean();
$ok( 'nothing is printed on a list of posts', '' === trim( $listing ) );
update_post_meta( $id, '_verlo_faq_jsonld', wp_slash( '{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Q?","acceptedAnswer":{"@type":"Answer","text":"a </script><script>alert(1)</script>"}}]}' ) );
$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $id, 'post_status' => 'any' ) );
ob_start(); Verlo_Faq_Schema::output(); $evil = ob_get_clean();
$ok( 'a closing script tag inside an answer cannot break out of the schema', 1 === substr_count( strtolower( $evil ), '</script>' ) && false === strpos( $evil, '<script>alert' ) );
update_post_meta( $id, '_verlo_faq_jsonld', wp_slash( $stored ) );
wp_set_current_user( 0 ); // back to the delivery's (unauthenticated) context
$ok( 'category set', has_term( 'Guides', 'category', $id ) );
$ok( 'tracked by article id', get_post_meta( $id, '_verlo_article_id', true ) === 'art-1' );

// 3. same delivery again changes nothing
$mod = $p->post_modified_gmt;
sleep( 1 );
$r = req( 'publish', $payload, $key, array( 'X-Verlo-Idempotency-Key' => 'art-1:v1:create' ) );
clean_post_cache( $id );
$ok( 'repeat is idempotent', 200 === $r->get_status() && (int) $r->get_data()['external_id'] === $id && get_post( $id )->post_modified_gmt === $mod );

// 4. update changes the same post
$payload['event'] = 'article.update'; $payload['article']['external_id'] = (string) $id; $payload['article']['version'] = 2; $payload['article']['title'] = 'How to descale a kettle (updated)'; $payload['article']['content_html'] = '<p>New intro.</p><h2>FAQ</h2><h3>Q here?</h3><p>An answer long enough to count.</p>';
$r = req( 'publish', $payload, $key, array( 'X-Verlo-Idempotency-Key' => 'art-1:v2:update' ) );
clean_post_cache( $id );
$ok( 'update keeps same post', 200 === $r->get_status() && (int) $r->get_data()['external_id'] === $id && 'How to descale a kettle (updated)' === get_post( $id )->post_title );
$count = count( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_verlo_article_id', 'meta_value' => 'art-1', 'numberposts' => -1, 'fields' => 'ids' ) ) );
$ok( 'still one post for the article', 1 === $count, (string) $count );
$ok( 'update keeps draft status', 'draft' === get_post_status( $id ) );

// 5. attacks / mistakes
$r = req( 'publish', $payload, $key, array(), function ( $b ) { return str_replace( 'updated', 'hacked', $b ); } );
$ok( 'tampered body rejected (401)', 401 === $r->get_status(), (string) $r->get_status() );
$r = req( 'publish', $payload, 'vlo_wrong_key_wrong_key_wrong_key' );
$ok( 'wrong key rejected (401)', 401 === $r->get_status() );
$body = wp_json_encode( $payload ); $r2 = new WP_REST_Request( 'POST', '/verlo/v1/publish' ); $r2->set_header( 'x_verlo_timestamp', (string) ( time() - 4000 ) ); $r2->set_header( 'x_verlo_signature', Verlo_Signature::sign( $key, time() - 4000, $body ) ); $r2->set_body( $body );
$ok( 'old timestamp rejected (401)', 401 === rest_do_request( $r2 )->get_status() );
$r3 = new WP_REST_Request( 'POST', '/verlo/v1/publish' ); $r3->set_body( $body );
$ok( 'unsigned rejected (401)', 401 === rest_do_request( $r3 )->get_status() );
$r = req( 'publish', array( 'event' => 'article.publish' ), $key );
$ok( 'incomplete article rejected', 422 === $r->get_status() );

// 6. a deleted post is recreated on update
wp_delete_post( $id, true );
$payload['article']['version'] = 3;
$r = req( 'publish', $payload, $key, array( 'X-Verlo-Idempotency-Key' => 'art-1:v3:update' ) );
$ok( 'deleted post is recreated', 200 === $r->get_status() && (int) $r->get_data()['external_id'] !== $id && get_post( (int) $r->get_data()['external_id'] ) );

// 7. publish mode
$payload2 = $payload; $payload2['article']['id'] = 'art-2'; $payload2['article']['external_id'] = null; $payload2['destination']['post_mode'] = 'publish';
$r = req( 'publish', $payload2, $key, array( 'X-Verlo-Idempotency-Key' => 'art-2:v1:create' ) );
$ok( 'publish mode goes live', 200 === $r->get_status() && 'publish' === get_post_status( (int) $r->get_data()['external_id'] ) );

// 8. hero image: only Pexels addresses are ever fetched; a bad address is ignored and the post still publishes
$payload3 = $payload; $payload3['article']['id'] = 'art-3'; $payload3['article']['external_id'] = null;
$payload3['article']['image'] = array( 'url' => 'https://evil.example/photos/1/x.jpeg', 'alt' => 'x', 'credit' => 'Photo by A on Pexels', 'credit_url' => 'https://www.pexels.com' );
$r = req( 'publish', $payload3, $key, array( 'X-Verlo-Idempotency-Key' => 'art-3:v1:create' ) );
$ok( 'a non-Pexels image address is ignored, article still published', 200 === $r->get_status() && ! has_post_thumbnail( (int) $r->get_data()['external_id'] ) );

// 9. wake: Verlo tells a collecting site that an article is waiting. Same signature rules as every other request.
$r = req( 'wake', new stdClass(), $key );
$ok( 'wake with a valid signature is accepted', 200 === $r->get_status() && ! empty( $r->get_data()['ok'] ), json_encode( $r->get_data() ) );
$r = req( 'wake', new stdClass(), 'vlo_' . str_repeat( 'z9Y8', 12 ) );
$ok( 'wake with the wrong key is refused', 401 === $r->get_status() );
$r = req( 'wake', new stdClass(), $key, array(), function ( $body ) { return $body . ' '; } );
$ok( 'wake with a changed body is refused', 401 === $r->get_status() );
$stale = new WP_REST_Request( 'POST', '/verlo/v1/wake' );
$old   = Verlo_Signature::sign( $key, time() - 3600, '{}' );
$stale->set_header( 'x_verlo_timestamp', (string) ( time() - 3600 ) ); $stale->set_header( 'x_verlo_signature', $old ); $stale->set_body( '{}' );
$ok( 'wake with an old timestamp is refused', 401 === rest_do_request( $stale )->get_status() );

echo implode( "\n", $out ) . "\n";
