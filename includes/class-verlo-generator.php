<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The Generator: turns an approved Content Brief into a real article and saves
 * it as a WordPress DRAFT (never auto-published). Phase 3 core — quality review
 * loop and featured images are layered on in later increments.
 *
 * Output protocol is delimiter-based plain text (not JSON): a long HTML article
 * inside a JSON string field is fragile to escape, so we use explicit markers
 * and parse them, which is far more robust for big bodies.
 */
class Verlo_Generator {

	/**
	 * Queue an article generation to run in the BACKGROUND, returning control to
	 * the browser immediately. This is the path the admin UI uses: a full
	 * article can take 1-3 minutes, far longer than typical nginx/PHP-FPM
	 * timeouts, so running it inside the page request causes 504 Gateway
	 * Time-out errors (and, on retry, duplicate articles). Instead we fire a
	 * non-blocking loopback request that does the work, and the UI polls for
	 * completion.
	 *
	 * Returns true if queued (or already running), WP_Error on a pre-flight
	 * failure the user can act on immediately.
	 */
	public static function queue_draft( $article_id ) {
		$article_id = (int) $article_id;

		if ( ! Verlo_Topical_Map::is_approved() ) {
			return new WP_Error( 'verlo_map_not_approved', 'Approve the Topical Map first.' );
		}
		if ( ! Verlo_Auth::is_connected() ) {
			return new WP_Error( 'verlo_not_connected', 'Connect Verlo first under Strategy Profile → Verlo connection.' );
		}
		if ( ! Verlo_Brief::get( $article_id ) ) {
			return new WP_Error( 'verlo_no_brief', 'Generate a content brief for this article first.' );
		}

		$status = Verlo_Brief::get_gen_status( $article_id );
		if ( in_array( $status['state'], array( 'queued', 'running' ), true ) ) {
			// Already in flight and fresh — don't queue a second worker. A stale
			// status (a worker that died) is allowed to fall through; the lock,
			// which expires on its own TTL, is what actually prevents a duplicate
			// paid API call.
			if ( ( time() - (int) $status['updated_at'] ) < 3 * MINUTE_IN_SECONDS ) {
				return true;
			}
		}

		Verlo_Brief::set_gen_status( $article_id, 'queued', 'Queued…' );

		// One correlation id for this whole generation, so every related log row
		// (queued, api.ok, timing, done/error) can be grouped in the Logs tab.
		$run_id = 'gen_' . $article_id . '_' . substr( wp_generate_password( 8, false ), 0, 8 );
		Verlo_Brief::set_run_id( $article_id, $run_id );

		$dispatched = self::dispatch_worker( $article_id );

		// Schedule a WP-Cron fallback regardless (no-ops if already done).
		$cron_ok = false;
		if ( ! wp_next_scheduled( 'verlo_cron_generate', array( $article_id ) ) ) {
			$cron_ok = ( false !== wp_schedule_single_event( time() + 20, 'verlo_cron_generate', array( $article_id ) ) );
		}

		// Spawn cron immediately via a non-blocking ping so it doesn't wait for
		// the next visitor (low-traffic sites otherwise never run cron).
		self::spawn_cron();

		Verlo_Log::info( 'gen.queued', 'Article generation queued', array(
			'run_id'          => $run_id,
			'article_id'      => $article_id,
			'loopback_sent'   => $dispatched ? 'yes' : 'no',
			'cron_scheduled'  => $cron_ok ? 'yes' : 'already/failed',
			'cron_disabled'   => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'yes' : 'no',
		) );

		return true;
	}

	/**
	 * Fire the non-blocking loopback worker request. Returns true if the request
	 * was dispatched without an immediate transport error (note: a security
	 * plugin can still silently drop it, which is why cron + poll-driven
	 * fallbacks exist).
	 */
	protected static function dispatch_worker( $article_id ) {
		$token = wp_generate_password( 20, false );
		set_transient( 'verlo_gen_token_' . $article_id, $token, 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg( 'action', 'verlo_run_generation', admin_url( 'admin-post.php' ) );

		$res = wp_remote_post( $url, array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'headers'   => array( 'cookie' => '' ),
			'body'      => array(
				'article_id' => $article_id,
				'token'      => $token,
			),
		) );
		return ! is_wp_error( $res );
	}

	/**
	 * Nudge WP-Cron to run now (non-blocking), so a scheduled fallback fires on
	 * low-traffic sites without waiting for the next page view.
	 */
	protected static function spawn_cron() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return; // host runs cron externally; our scheduled event will be picked up
		}
		$cron_url = site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) );
		wp_remote_post( $cron_url, array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		) );
	}

	/**
	 * WP-Cron fallback worker. Runs if the article is still pending (the loopback
	 * worker didn't complete it). Uses the lock so it never races a live worker,
	 * but treats a STALE lock (held by a worker that died) as reclaimable.
	 */
	public static function run_via_cron( $article_id ) {
		self::run_pending( (int) $article_id, 'cron' );
	}

	/**
	 * Shared "run if still pending" routine used by the cron fallback and the
	 * poll-driven self-heal. Returns a short status string for diagnostics.
	 */
	public static function run_pending( $article_id, $source ) {
		$article_id = (int) $article_id;
		$brief      = Verlo_Brief::get( $article_id );
		if ( ! $brief ) { return 'no_brief'; }

		// Already finished?
		if ( ! empty( $brief['draft']['post_id'] ) && get_post( (int) $brief['draft']['post_id'] ) ) {
			$st = Verlo_Brief::get_gen_status( $article_id );
			if ( 'done' !== $st['state'] && 'idle' !== $st['state'] ) {
				Verlo_Brief::set_gen_status( $article_id, 'done', 'Draft article created.' );
			}
			return 'already_done';
		}

		$status = Verlo_Brief::get_gen_status( $article_id );
		if ( 'error' === $status['state'] ) { return 'errored'; }

		// Only defer to "another worker" if the generation lock is actually held
		// (a request is genuinely mid-API-call). If the lock is free, no worker
		// is running — even if status says 'running', that was left by a worker
		// that has since died — so we take over now rather than waiting out a
		// fixed timeout. The lock itself prevents any duplicate paid API call.
		if ( false !== get_transient( 'verlo_gen_lock_' . $article_id ) ) {
			return 'locked_busy';
		}

		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
		ignore_user_abort( true );
		Verlo_Brief::set_gen_status( $article_id, 'running', 'Writing the article (' . $source . ')…' );

		$run_id = Verlo_Brief::get_run_id( $article_id );

		// generate_draft() holds its own lock; if another request is genuinely
		// mid-API-call it returns 'verlo_in_progress' and we leave status as
		// running so the next poll checks again (no duplicate API spend). A lock
		// from a dead worker expires on its own (see acquire_lock TTL).
		try {
			$res = self::generate_draft( $article_id );
		} catch ( \Throwable $e ) {
			Verlo_Log::error( 'gen.fatal', 'Fatal during generation: ' . $e->getMessage(), array(
				'run_id'     => $run_id,
				'article_id' => $article_id,
				'source'     => $source,
				'file'       => $e->getFile(),
				'line'       => $e->getLine(),
			) );
			Verlo_Brief::set_gen_status( $article_id, 'error', 'Unexpected error: ' . $e->getMessage() );
			self::release_lock( 'verlo_gen_lock_' . $article_id );
			return 'error';
		}
		if ( is_wp_error( $res ) ) {
			if ( 'verlo_in_progress' === $res->get_error_code() ) {
				return 'in_progress';
			}
			Verlo_Log::from_wp_error( 'gen.error', $res, array( 'run_id' => $run_id, 'article_id' => $article_id, 'source' => $source ) );
			Verlo_Brief::set_gen_status( $article_id, 'error', $res->get_error_message() );
			return 'error';
		}
		Verlo_Log::info( 'gen.done', 'Draft article created', array(
			'run_id' => $run_id, 'article_id' => $article_id, 'source' => $source, 'post_id' => (int) $res,
		) );
		Verlo_Brief::set_gen_status( $article_id, 'done', 'Draft article created.' );
		return 'done';
	}

	/**
	 * Background worker entry point (admin-post, token-authenticated since the
	 * loopback carries no cookie).
	 */
	public static function run_background() {
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
		ignore_user_abort( true );

		$article_id = (int) ( $_POST['article_id'] ?? 0 );
		$token      = (string) ( $_POST['token'] ?? '' );
		$expected   = get_transient( 'verlo_gen_token_' . $article_id );

		if ( ! $article_id || ! $expected || ! hash_equals( (string) $expected, $token ) ) {
			status_header( 403 );
			exit;
		}
		delete_transient( 'verlo_gen_token_' . $article_id );

		Verlo_Brief::set_gen_status( $article_id, 'running', 'Writing the article…' );

		$res = self::generate_draft( $article_id );
		if ( is_wp_error( $res ) ) {
			Verlo_Brief::set_gen_status( $article_id, 'error', $res->get_error_message() );
		} else {
			Verlo_Brief::set_gen_status( $article_id, 'done', 'Draft article created.' );
		}
		exit;
	}

	/**
	 * Generate (or regenerate) the draft article for a brief's article id.
	 * Returns the post ID, or WP_Error. Runs synchronously; callers that need
	 * to avoid gateway timeouts should use queue_draft() instead.
	 */
	public static function generate_draft( $article_id ) {
		if ( ! Verlo_Topical_Map::is_approved() ) {
			return new WP_Error( 'verlo_map_not_approved', 'Approve the Topical Map first.' );
		}
		if ( ! Verlo_Auth::is_connected() ) {
			return new WP_Error( 'verlo_not_connected', 'Connect Verlo first under Strategy Profile → Verlo connection.' );
		}
		$brief = Verlo_Brief::get( $article_id );
		if ( ! $brief ) {
			return new WP_Error( 'verlo_no_brief', 'Generate a content brief for this article first.' );
		}

		// Idempotency lock: if a generation for this article is already in
		// flight (e.g. the user's browser timed out with a 504 but PHP is still
		// running, or they double-submitted), refuse the second run so we never
		// create a duplicate post or double-charge the API.
		$lock_key = 'verlo_gen_lock_' . (int) $article_id;
		if ( false === self::acquire_lock( $lock_key ) ) {
			return new WP_Error(
				'verlo_in_progress',
				'This article is already being generated (started moments ago). Please wait for it to finish before trying again.'
			);
		}

		$result = self::do_generate_draft( $article_id, $brief );

		self::release_lock( $lock_key );
		return $result;
	}

	/**
	 * Acquire a short-lived generation lock using an autoload-off transient as
	 * a mutex. Returns false if a lock is already held. The TTL is just longer
	 * than the longest single generation (the API call uses a 120s client
	 * timeout), so a worker that genuinely died releases the lock automatically
	 * shortly after, but a live worker mid-call keeps it — preventing two
	 * requests from both calling the paid API for the same article.
	 */
	protected static function acquire_lock( $key ) {
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), 150 );
		return true;
	}

	protected static function release_lock( $key ) {
		delete_transient( $key );
	}

	/**
	 * The actual generation work, run under the lock held by generate_draft().
	 * Each phase is timed server-side so the Logs tab shows exactly where the
	 * time goes (AI call vs. images vs. block conversion), and the true total
	 * duration is recorded (independent of the browser's resetting timer).
	 */
	protected static function do_generate_draft( $article_id, $brief ) {
		$t_start = microtime( true );
		$timing  = array();

		$profile = Verlo_Profile::get();

		$t = microtime( true );
		$parsed  = self::write_article( $brief, $profile );
		$timing['ai_write_s'] = round( microtime( true ) - $t, 1 );
		if ( is_wp_error( $parsed ) ) { return $parsed; }

		$t = microtime( true );
		$content = self::sanitize_content( $parsed['content'], $brief );
		$content = Verlo_Text::scrub_stale_years( $content );
		$content = Verlo_Text::humanize( $content );
		$blocks  = self::to_blocks( $content );
		$timing['process_s'] = round( microtime( true ) - $t, 1 );

		// Resolve the pillar's category (additive; should already exist post-approval).
		$cat_id = self::resolve_category_id( $brief['pillar'] );

		$title = '' !== $parsed['title'] ? $parsed['title'] : ( '' !== $brief['suggested_title'] ? $brief['suggested_title'] : $brief['keyword'] );

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $blocks,
			'post_excerpt' => $parsed['excerpt'],
			'post_status'  => 'draft',
			'post_type'    => 'post',
			'post_name'    => sanitize_title( $brief['keyword'] ),
		);
		if ( $cat_id ) { $postarr['post_category'] = array( $cat_id ); }

		// Reuse the existing draft if one is still present, else create new.
		$existing = isset( $brief['draft']['post_id'] ) ? (int) $brief['draft']['post_id'] : 0;
		if ( $existing && ( $p = get_post( $existing ) ) && 'trash' !== $p->post_status ) {
			$postarr['ID'] = $existing;
			$post_id = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $post_id ) ) { return $post_id; }

		self::apply_seo_meta( $post_id, $brief, $parsed );

		// Stock images (optional, Pexels). Isolated: image failures never break
		// the article. apply_to_post() sets the featured image as a side effect
		// and returns the content with any in-body image blocks spliced in,
		// scaled to article length and never placed inside the FAQ section.
		$timing['images_s'] = 0;
		if ( class_exists( 'Verlo_Images' ) && Verlo_Images::is_configured() ) {
			$t = microtime( true );
			try {
				$with_images = Verlo_Images::apply_to_post( $post_id, $brief['keyword'], $postarr['post_content'] );
				if ( $with_images !== $postarr['post_content'] ) {
					wp_update_post( array( 'ID' => (int) $post_id, 'post_content' => $with_images ) );
				}
			} catch ( \Throwable $e ) {
				Verlo_Log::warn( 'gen.images_failed', 'Image step failed (article kept): ' . $e->getMessage(), array( 'article_id' => $article_id ) );
			}
			$timing['images_s'] = round( microtime( true ) - $t, 1 );
		}

		// Mark the post as plugin-generated, and record state on the brief.
		update_post_meta( $post_id, '_verlo_generated', 1 );
		update_post_meta( $post_id, '_verlo_keyword', $brief['keyword'] );

		$timing['total_s'] = round( microtime( true ) - $t_start, 1 );

		$run_id = isset( $brief['gen']['run_id'] ) ? (string) $brief['gen']['run_id'] : '';

		// Persist the real server-side timing for the UI and diagnostics.
		Verlo_Log::info( 'gen.timing', 'Article generated in ' . $timing['total_s'] . 's', array_merge( $timing, array(
			'run_id'        => $run_id,
			'article_id'    => $article_id,
			'keyword'       => $brief['keyword'],
			'word_target'   => (int) ( $brief['word_count'] ?? 0 ),
			'images'        => Verlo_Images::is_configured() ? 'on' : 'off',
		) ) );

		// Durable article-history record (survives map rebuilds and the event
		// log rolling over). Keyed by post_id so a regenerate updates in place.
		if ( class_exists( 'Verlo_Article_Log' ) ) {
			Verlo_Article_Log::record( array(
				'post_id'     => (int) $post_id,
				'article_id'  => (int) $article_id,
				'keyword'     => (string) $brief['keyword'],
				'title'       => (string) $title,
				'pillar'      => (string) ( $brief['pillar'] ?? '' ),
				'word_target' => (int) ( $brief['word_count'] ?? 0 ),
				'gen_seconds' => (float) $timing['total_s'],
				'run_id'      => $run_id,
			) );
		}

		// Re-read the brief so we merge onto the latest stored copy (the
		// background worker may have written gen-status in the meantime) rather
		// than overwriting it with our captured-at-start copy.
		$fresh = Verlo_Brief::get( $article_id );
		if ( is_array( $fresh ) ) { $brief = $fresh; }

		$brief['draft'] = array(
			'post_id'    => (int) $post_id,
			'status'     => 'draft',
			'created_at' => isset( $brief['draft']['created_at'] ) ? (int) $brief['draft']['created_at'] : time(),
			'updated_at' => time(),
			'gen_seconds'=> isset( $timing['total_s'] ) ? (float) $timing['total_s'] : null,
		);
		Verlo_Brief::save( $article_id, $brief );

		return (int) $post_id;
	}

	/**
	 * Domains permitted for outbound links. The built-in defaults are
	 * NICHE-AGNOSTIC (universal authorities only) so the plugin is safe on any
	 * site. Each site adds its own niche-relevant trusted domains via the
	 * "Trusted outbound domains" setting, or via the verlo_outbound_allowlist
	 * filter. Nothing here assumes a particular topic.
	 */
	public static function outbound_allowlist() {
		$list = array(
			'wikipedia.org', 'en.wikipedia.org',
			'britannica.com', 'merriam-webster.com',
			'who.int', 'nih.gov', 'ncbi.nlm.nih.gov', 'cdc.gov',
		);

		// Per-site additions from settings (one domain per line or comma-separated).
		if ( function_exists( 'verlo_get_settings' ) ) {
			$settings = verlo_get_settings();
			$raw      = isset( $settings['outbound_domains'] ) ? (string) $settings['outbound_domains'] : '';
			foreach ( preg_split( '/[\s,]+/', $raw ) as $d ) {
				$d = strtolower( trim( preg_replace( '#^https?://#', '', $d ) ) );
				$d = preg_replace( '#/.*$#', '', $d ); // strip any path
				$d = preg_replace( '/^www\./', '', $d );
				if ( '' !== $d && false !== strpos( $d, '.' ) ) { $list[] = $d; }
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$list = apply_filters( 'verlo_outbound_allowlist', $list );
		}
		return array_values( array_unique( $list ) );
	}

	protected static function host_allowed( $host ) {
		if ( ! $host ) { return false; }
		$host = strtolower( preg_replace( '/^www\./', '', $host ) );
		foreach ( self::outbound_allowlist() as $d ) {
			$d = strtolower( $d );
			if ( $host === $d || self::str_ends_with( $host, '.' . $d ) ) { return true; }
		}
		// Allow government/education TLDs broadly (high authority).
		if ( preg_match( '/\.(gov|edu)(\.[a-z]{2})?$/', $host ) || self::str_ends_with( $host, '.ac.uk' ) ) {
			return true;
		}
		return false;
	}

	protected static function str_ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		return 0 === $len || ( strlen( $haystack ) >= $len && 0 === substr_compare( $haystack, $needle, -$len ) );
	}

	/**
	 * Submit the article job to the Verlo SaaS and return a parsed result.
	 * Returns [ 'title', 'meta', 'excerpt', 'content' ] or WP_Error.
	 */
	protected static function write_article( $brief, $profile ) {
		$payload = array(
			'profile' => array(
				'niche'              => $profile['niche'],
				'audience'           => $profile['audience'],
				'voice'              => $profile['voice'],
				'monetization_model' => $profile['monetization_model'],
				'geo'                => $profile['geo'],
				'language'           => $profile['language'],
				'constraints'        => $profile['constraints'],
			),
			'brief' => array(
				'keyword'         => $brief['keyword'],
				'pillar'          => $brief['pillar'] ?? '',
				'intent'          => $brief['intent'] ?? 'informational',
				'suggested_title' => $brief['suggested_title'] ?? '',
				'angle'           => $brief['angle'] ?? '',
				'search_intent'   => $brief['search_intent'] ?? '',
				'audience_note'   => $brief['audience_note'] ?? '',
				'outline'         => $brief['outline'] ?? array(),
				'internal_links'  => $brief['internal_links'] ?? array(),
				'external_ideas'  => $brief['external_ideas'] ?? array(),
				'faq'             => $brief['faq'] ?? array(),
				'word_count'      => (int) ( $brief['word_count'] ?? 1500 ),
				'voice_note'      => $brief['voice_note'] ?? '',
			),
			'word_target' => (int) ( $brief['word_count'] ?? 1500 ),
		);

		$result = Verlo_SaaS_Client::run_job( 'article', $payload, 180 );
		if ( is_wp_error( $result ) ) { return $result; }

		if ( empty( $result['content_html'] ) ) {
			return new WP_Error( 'verlo_bad_output', 'The Verlo server did not return article content.' );
		}

		return array(
			'title'   => sanitize_text_field( $result['title'] ?? '' ),
			'meta'    => sanitize_text_field( $result['meta_description'] ?? '' ),
			'excerpt' => sanitize_text_field( $result['excerpt'] ?? '' ),
			'content' => $result['content_html'],
		);
	}

	/**
	 * Allow only safe post HTML, and unwrap any link that is not to our own
	 * domain (defends against hallucinated/spam external URLs).
	 */
	protected static function sanitize_content( $html, $brief ) {
		// Strip code fences if the model wrapped the body.
		$html = preg_replace( '/^```(html)?/i', '', trim( $html ) );
		$html = preg_replace( '/```$/', '', trim( $html ) );

		$html = wp_kses_post( $html );

		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$html = preg_replace_callback(
			'/<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
			function ( $m ) use ( $home ) {
				$host = wp_parse_url( $m[1], PHP_URL_HOST );
				if ( ! $host || $host === $home ) {
					return $m[0]; // internal link -> keep as-is
				}
				if ( self::host_allowed( $host ) ) {
					// Authoritative outbound link: keep, dofollow, safe target attrs.
					return '<a href="' . esc_url( $m[1] ) . '" target="_blank" rel="noopener">' . $m[2] . '</a>';
				}
				return $m[2]; // unknown external link -> keep anchor text only
			},
			$html
		);
		return $html;
	}

	protected static function resolve_category_id( $pillar_name ) {
		foreach ( Verlo_Topical_Map::get()['pillars'] as $p ) {
			if ( 0 === strcasecmp( $p['name'], $pillar_name ) && ! empty( $p['category_id'] ) ) {
				return (int) $p['category_id'];
			}
		}
		$term = get_term_by( 'name', $pillar_name, 'category' );
		return ( $term instanceof WP_Term ) ? (int) $term->term_id : 0;
	}

	/**
	 * Store SEO title / meta description / focus keyword for both Yoast and
	 * Rank Math (harmless if either plugin is absent).
	 */
	protected static function apply_seo_meta( $post_id, $brief, $parsed ) {
		$meta_desc = '' !== $parsed['meta'] ? $parsed['meta'] : $brief['angle'];
		$seo_title = '' !== $parsed['title'] ? $parsed['title'] : $brief['suggested_title'];
		$keyword   = $brief['keyword'];

		$seo_title = self::clamp_text( $seo_title, 60 );
		$meta_desc = self::clamp_text( $meta_desc, 155 );

		// Yoast
		update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
		// Rank Math
		update_post_meta( $post_id, 'rank_math_title', $seo_title );
		update_post_meta( $post_id, 'rank_math_description', $meta_desc );
		update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
	}

	/**
	 * Convert clean article HTML into native Gutenberg block markup. This makes
	 * the draft load as real blocks (no "convert to blocks" step) and removes the
	 * stray whitespace/line-break artifacts that broke lists in the editor.
	 * Falls back to whitespace-normalised HTML if DOMDocument is unavailable.
	 */
	public static function to_blocks( $html ) {
		$html = trim( $html );
		if ( '' === $html ) { return ''; }

		if ( ! class_exists( 'DOMDocument' ) ) {
			// Fallback: at least collapse inter-tag whitespace so lists are tight.
			$html = preg_replace( '/>\s+</', '><', $html );
			return $html;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		// Let libxml wrap the fragment in html>body; iterate the body's children.
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		$bodies = $dom->getElementsByTagName( 'body' );
		$root   = $bodies->length ? $bodies->item( 0 ) : null;
		if ( ! $root ) { return preg_replace( '/>\s+</', '><', $html ); }

		$out = '';
		foreach ( $root->childNodes as $node ) {
			$out .= self::node_to_block( $dom, $node );
		}
		return trim( $out );
	}

	protected static function node_to_block( $dom, $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return ''; // ignore stray top-level text/whitespace
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) { return ''; }

		$tag   = strtolower( $node->nodeName );
		$inner = self::inner_html( $dom, $node );
		$inner = trim( preg_replace( '/\s+/', ' ', $inner ) );

		switch ( $tag ) {
			case 'p':
				if ( '' === $inner ) { return ''; }
				return "<!-- wp:paragraph -->\n<p>{$inner}</p>\n<!-- /wp:paragraph -->\n\n";
			case 'h2':
			case 'h3':
			case 'h4':
				$level = (int) substr( $tag, 1 );
				return "<!-- wp:heading {\"level\":{$level}} -->\n<{$tag}>{$inner}</{$tag}>\n<!-- /wp:heading -->\n\n";
			case 'ul':
			case 'ol':
				return self::list_to_block( $dom, $node, 'ol' === $tag );
			default:
				// Wrap anything else as a paragraph if it has content.
				return '' !== $inner ? "<!-- wp:paragraph -->\n<p>{$inner}</p>\n<!-- /wp:paragraph -->\n\n" : '';
		}
	}

	protected static function list_to_block( $dom, $node, $ordered ) {
		$items = '';
		foreach ( $node->childNodes as $li ) {
			if ( XML_ELEMENT_NODE === $li->nodeType && 'li' === strtolower( $li->nodeName ) ) {
				$text = trim( preg_replace( '/\s+/', ' ', self::inner_html( $dom, $li ) ) );
				if ( '' !== $text ) {
					$items .= "<!-- wp:list-item -->\n<li>{$text}</li>\n<!-- /wp:list-item -->\n";
				}
			}
		}
		if ( '' === $items ) { return ''; }
		$tag  = $ordered ? 'ol' : 'ul';
		$attr = $ordered ? ' {"ordered":true}' : '';
		return "<!-- wp:list{$attr} -->\n<{$tag}>\n{$items}</{$tag}>\n<!-- /wp:list -->\n\n";
	}

	protected static function inner_html( $dom, $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Trim text to a maximum length at a word boundary (no mid-word cut, no
	 * trailing punctuation), so SEO title/description sit in Yoast's green range.
	 */
	protected static function clamp_text( $text, $max ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( strlen( $text ) <= $max ) { return $text; }
		$cut = substr( $text, 0, $max );
		$sp  = strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > $max * 0.6 ) { $cut = substr( $cut, 0, $sp ); }
		return rtrim( $cut, " ,.;:-" );
	}
}

