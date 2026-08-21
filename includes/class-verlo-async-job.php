<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Generic background-job engine for the plugin's site-level (not per-article)
 * AI calls: analyze, generate topical map, generate next brief. Generalizes
 * the same loopback+cron+poll-driven-self-heal pattern Verlo_Generator
 * already uses for article generation (see that file's queue_draft()) - that
 * class is left as-is since its per-article-id status storage (post-meta on
 * a specific brief) doesn't map cleanly onto this class's one-job-per-site-
 * per-key model, but the underlying mechanism here is the same idea.
 *
 * Running any of these three AI calls inside the browser's own request
 * blocks for up to 60-90 real seconds (Verlo_SaaS_Client::run_job()'s
 * sleep-based poll loop) - long enough that hosts with a shorter proxy/PHP
 * timeout than that (common on shared hosting) return a 503/504 before the
 * job finishes, even though the job itself would have succeeded server-side.
 * Queuing through here instead returns control to the browser immediately;
 * the page shows a live progress state (see Verlo_Profile_Admin::progress_overlay())
 * and polls until the job is actually done.
 */
class Verlo_Async_Job {

	/** Must comfortably exceed the slowest real job (topical-map, 90s SaaS
	 *  timeout) plus submission overhead - same reasoning as
	 *  Verlo_Generator::LOCK_TTL, just a smaller worst case since none of
	 *  these three flows sideload images afterward. */
	const LOCK_TTL = 150;

	/**
	 * Which includes/ class+method actually performs job $job_key's work.
	 * Each callable takes the job's stored context array and returns
	 * true|string|array{message?,meta?}|WP_Error. Deliberately a fixed map
	 * (not a WP hook/filter) so this stays simple and statically traceable
	 * for code this money-sensitive - a lookup miss here must throw, never
	 * silently no-op a queued job.
	 */
	protected static function runner( $job_key ) {
		$map = array(
			'analyze'     => array( 'Verlo_Profile', 'run_pending' ),
			'brief-next'  => array( 'Verlo_Strategist', 'run_pending' ),
			'topical-map' => array( 'Verlo_Topical_Map', 'run_pending' ),
		);
		return isset( $map[ $job_key ] ) ? $map[ $job_key ] : null;
	}

	public static function get_status( $job_key ) {
		$raw = get_option( self::status_option( $job_key ), array() );
		return wp_parse_args( is_array( $raw ) ? $raw : array(), array(
			'state'      => 'idle',
			'message'    => '',
			'updated_at' => 0,
			'run_id'     => '',
			'meta'       => array(),
			'context'    => array(),
		) );
	}

	protected static function set_status( $job_key, $state, $message = '', $meta = array() ) {
		$current = self::get_status( $job_key );
		update_option( self::status_option( $job_key ), array(
			'state'      => $state,
			'message'    => $message,
			'updated_at' => time(),
			'run_id'     => $current['run_id'],
			'meta'       => $meta,
			'context'    => $current['context'],
		), 'no' );
	}

	protected static function status_option( $job_key ) {
		return 'verlo_async_' . sanitize_key( $job_key );
	}

	/**
	 * Queue $job_key to run in the background. $context is stored alongside
	 * the status and handed to the runner unchanged (e.g. brief-next's
	 * target article id, resolved once up front rather than re-picked later
	 * if the map changes mid-flight).
	 */
	public static function queue( $job_key, $context = array() ) {
		$status = self::get_status( $job_key );
		if ( in_array( $status['state'], array( 'queued', 'running' ), true )
			&& ( time() - (int) $status['updated_at'] ) < 3 * MINUTE_IN_SECONDS ) {
			return true; // already in flight and fresh - don't queue a second worker
		}

		$run_id = 'job_' . sanitize_key( $job_key ) . '_' . substr( wp_generate_password( 8, false ), 0, 8 );
		update_option( self::status_option( $job_key ), array(
			'state'      => 'queued',
			'message'    => 'Queued…',
			'updated_at' => time(),
			'run_id'     => $run_id,
			'meta'       => array(),
			'context'    => $context,
		), 'no' );

		$dispatched = self::dispatch_worker( $job_key );

		$cron_ok = false;
		if ( ! wp_next_scheduled( 'verlo_cron_async', array( $job_key ) ) ) {
			$cron_ok = ( false !== wp_schedule_single_event( time() + 20, 'verlo_cron_async', array( $job_key ) ) );
		}
		self::spawn_cron();

		if ( class_exists( 'Verlo_Log' ) ) {
			Verlo_Log::info( 'async.queued', 'Background job queued', array(
				'job_key'        => $job_key,
				'run_id'         => $run_id,
				'loopback_sent'  => $dispatched ? 'yes' : 'no',
				'cron_scheduled' => $cron_ok ? 'yes' : 'already/failed',
			) );
		}
		return true;
	}

	/**
	 * Fire the non-blocking loopback worker request. Returns true if the
	 * request was dispatched without an immediate transport error (a
	 * security plugin can still silently drop it, which is why cron + the
	 * poll-driven self-heal exist as fallbacks).
	 */
	protected static function dispatch_worker( $job_key ) {
		$token = wp_generate_password( 20, false );
		set_transient( 'verlo_async_token_' . $job_key, $token, 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg( 'action', 'verlo_run_async', admin_url( 'admin-post.php' ) );

		$res = wp_remote_post( $url, array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'headers'   => array( 'cookie' => '' ),
			'body'      => array(
				'job_key' => $job_key,
				'token'   => $token,
			),
		) );
		return ! is_wp_error( $res );
	}

	/**
	 * Nudge WP-Cron to run now (non-blocking), so a scheduled fallback fires
	 * on low-traffic sites without waiting for the next page view.
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
	 * Background worker entry point (admin-post, token-authenticated since
	 * the loopback carries no cookie).
	 */
	public static function run_background() {
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
		ignore_user_abort( true );

		$job_key  = sanitize_key( (string) ( $_POST['job_key'] ?? '' ) );
		$token    = (string) ( $_POST['token'] ?? '' );
		$expected = get_transient( 'verlo_async_token_' . $job_key );

		if ( ! $job_key || ! $expected || ! hash_equals( (string) $expected, $token ) ) {
			status_header( 403 );
			exit;
		}
		delete_transient( 'verlo_async_token_' . $job_key );

		self::run_pending( $job_key, 'loopback' );
		exit;
	}

	/** WP-Cron fallback worker. */
	public static function run_via_cron( $job_key ) {
		self::run_pending( (string) $job_key, 'cron' );
	}

	/**
	 * Shared "run if still pending" routine, used by the loopback worker, the
	 * cron fallback, and the poll-driven self-heal (ajax_status()). Returns a
	 * short status string for diagnostics.
	 *
	 * The atomic add_option()-based lock (acquire_lock()) is what actually
	 * prevents a duplicate paid AI API call when the loopback, cron, and a
	 * self-heal poll can all race to run the same job - a get-then-set check
	 * on the status option alone would not be atomic (see Verlo_Generator's
	 * acquire_lock() docblock for the full reasoning; identical here).
	 */
	public static function run_pending( $job_key, $source ) {
		$runner = self::runner( $job_key );
		if ( ! $runner ) { return 'unknown_job'; }

		$status = self::get_status( $job_key );
		if ( ! in_array( $status['state'], array( 'queued', 'running' ), true ) ) {
			return 'nothing_pending'; // idle/done/error: no work queued right now
		}

		$lock_token = self::acquire_lock( $job_key );
		if ( false === $lock_token ) {
			return 'locked_busy'; // another worker is genuinely mid-run
		}

		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
		ignore_user_abort( true );
		self::set_status( $job_key, 'running', 'Working (' . $source . ')…' );

		try {
			$result = call_user_func( $runner, $status['context'] );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Verlo_Log' ) ) {
				Verlo_Log::error( 'async.fatal', 'Fatal during background job: ' . $e->getMessage(), array(
					'job_key' => $job_key,
					'source'  => $source,
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				) );
			}
			self::set_status( $job_key, 'error', 'Unexpected error: ' . $e->getMessage() );
			self::release_lock( $job_key, $lock_token );
			return 'error';
		}
		self::release_lock( $job_key, $lock_token );

		if ( is_wp_error( $result ) ) {
			if ( class_exists( 'Verlo_Log' ) ) {
				Verlo_Log::from_wp_error( 'async.error', $result, array( 'job_key' => $job_key, 'source' => $source ) );
			}
			$meta = array( 'error_code' => $result->get_error_code() );
			if ( class_exists( 'Verlo_SaaS_Client' ) ) {
				$meta['is_billing_error'] = Verlo_SaaS_Client::is_billing_error( $result );
			}
			self::set_status( $job_key, 'error', $result->get_error_message(), $meta );
			return 'error';
		}

		$message = 'Done.';
		$meta    = array();
		if ( is_string( $result ) && '' !== $result ) {
			$message = $result;
		} elseif ( is_array( $result ) ) {
			if ( ! empty( $result['message'] ) ) { $message = (string) $result['message']; }
			if ( ! empty( $result['meta'] ) && is_array( $result['meta'] ) ) { $meta = $result['meta']; }
		}

		if ( class_exists( 'Verlo_Log' ) ) {
			Verlo_Log::info( 'async.done', 'Background job completed', array( 'job_key' => $job_key, 'source' => $source ) );
		}
		self::set_status( $job_key, 'done', $message, $meta );
		return 'done';
	}

	/**
	 * Poll-driven self-heal, same shape as Verlo_Brief_Admin::ajax_gen_status():
	 * if the job looks stalled (loopback dropped, cron blocked), the open
	 * admin tab takes over and runs it synchronously right here.
	 */
	public static function ajax_status() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array(), 403 ); }
		check_ajax_referer( 'verlo_async_status', 'nonce' );

		$job_key = sanitize_key( (string) ( $_GET['job_key'] ?? '' ) );
		$force   = ! empty( $_GET['force'] );
		if ( ! self::runner( $job_key ) ) { wp_send_json_error( array(), 400 ); }

		$status = self::get_status( $job_key );
		$age    = time() - (int) $status['updated_at'];
		$delay  = class_exists( 'Verlo_Env' ) ? Verlo_Env::self_heal_delay() : 15;
		$stalled = in_array( $status['state'], array( 'queued', 'running' ), true ) && $age >= $delay;

		if ( in_array( $status['state'], array( 'queued', 'running' ), true ) && ( $force || $stalled ) ) {
			self::run_pending( $job_key, 'browser' );
			$status = self::get_status( $job_key );
			$age    = time() - (int) $status['updated_at'];
		}

		wp_send_json_success( array(
			'state'   => $status['state'],
			'message' => $status['message'],
			'meta'    => $status['meta'],
			'age'     => $age,
		) );
	}

	/**
	 * Echo the small config block a page needs so the shared client-side
	 * poller (Verlo_Profile_Admin::progress_overlay()) knows to resume
	 * watching a job that's already queued/running after a redirect landed
	 * here. Call only when the page's notice is the "still working" sentinel.
	 */
	public static function render_poll_bootstrap( $job_key, $kind, $base_url ) {
		?>
		<script>
		window.verloAsyncPoll = {
			jobKey:  <?php echo wp_json_encode( $job_key ); ?>,
			kind:    <?php echo wp_json_encode( $kind ); ?>,
			baseUrl: <?php echo wp_json_encode( $base_url ); ?>,
			ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonce:   <?php echo wp_json_encode( wp_create_nonce( 'verlo_async_status' ) ); ?>
		};
		</script>
		<?php
	}

	// --- Locking: identical atomic add_option()-based mutex to
	// Verlo_Generator::acquire_lock() - see that method's docblock for the
	// full rationale (add_option() is a real test-and-set because wp_options
	// has a UNIQUE key on option_name; get/set transients are not atomic). ---

	protected static function acquire_lock( $job_key ) {
		$option = '_verlo_async_lock_' . $job_key;
		$token  = wp_generate_password( 12, false );

		if ( add_option( $option, time() . '|' . $token, '', 'no' ) ) {
			return $token;
		}

		$held_at = self::lock_held_at( $option );
		if ( $held_at && ( time() - $held_at ) > self::LOCK_TTL ) {
			update_option( $option, time() . '|' . $token, 'no' );
			return $token;
		}
		return false;
	}

	private static function lock_held_at( $option ) {
		$raw = (string) get_option( $option, '' );
		if ( '' === $raw ) { return 0; }
		return (int) strtok( $raw, '|' );
	}

	protected static function release_lock( $job_key, $token = null ) {
		$option = '_verlo_async_lock_' . $job_key;
		if ( null === $token ) {
			delete_option( $option );
			return;
		}
		$raw = (string) get_option( $option, '' );
		if ( '' === $raw ) { return; }
		$parts = explode( '|', $raw, 2 );
		if ( isset( $parts[1] ) && hash_equals( $parts[1], $token ) ) {
			delete_option( $option );
		}
	}
}
