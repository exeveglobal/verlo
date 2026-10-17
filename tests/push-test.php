<?php
// Simulates Verlo pushing signed articles to this site, through WordPress's real REST stack.
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
$ok( 'FAQ schema embedded', $p && false !== strpos( $p->post_content, 'application/ld+json' ) && false !== strpos( $p->post_content, 'FAQPage' ) );
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

echo implode( "\n", $out ) . "\n";
