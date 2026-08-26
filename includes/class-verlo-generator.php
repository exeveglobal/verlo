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
	 *
	 * Self-reschedules while genuinely still in progress: a single one-shot
	 * cron event, 20 seconds after queuing, was enough back when one
	 * invocation could see a generation all the way through by itself. Now
	 * that generation is submit-then-poll (see do_generate_draft()), a job
	 * can legitimately still be mid-flight well past that — this keeps
	 * checking every few seconds until it's actually done, so a browser tab
	 * being closed doesn't strand it, capped so a genuinely abandoned/errored
	 * job doesn't reschedule forever.
	 */
	public static function run_via_cron( $article_id ) {
		$article_id = (int) $article_id;
		$outcome    = self::run_pending( $article_id, 'cron' );

		if ( 'still_writing' === $outcome ) {
			$status = Verlo_Brief::get_gen_status( $article_id );
			$age    = $status['queued_at'] ? ( time() - (int) $status['queued_at'] ) : 0;
			if ( $age < 10 * MINUTE_IN_SECONDS && ! wp_next_scheduled( 'verlo_cron_generate', array( $article_id ) ) ) {
				wp_schedule_single_event( time() + 10, 'verlo_cron_generate', array( $article_id ) );
				self::spawn_cron();
			}
		}
	}

	/**
	 * Shared "run if still pending" routine used by the cron fallback and the
	 * poll-driven self-heal. Returns a short status string for diagnostics.
	 */
	public static function run_pending( $article_id, $source ) {
		$article_id = (int) $article_id;
		$brief      = Verlo_Brief::get( $article_id );
		if ( ! $brief ) {
			Verlo_Log::warn( 'gen.no_brief', 'Background worker found no brief for this article', array(
				'article_id' => $article_id, 'source' => $source,
			) );
			return 'no_brief';
		}

		// Already finished?
		if ( ! empty( $brief['draft']['post_id'] ) && get_post( (int) $brief['draft']['post_id'] ) ) {
			$st = Verlo_Brief::get_gen_status( $article_id );
			if ( 'done' !== $st['state'] && 'idle' !== $st['state'] ) {
				Verlo_Brief::set_gen_status( $article_id, 'done', 'Draft article created.' );
			}
			return 'already_done';
		}

		$status = Verlo_Brief::get_gen_status( $article_id );
		// This early return is the single most important line in this file for
		// diagnosing a "nothing happens" report: it means SOME earlier attempt
		// already failed and left status 'error', and every subsequent
		// loopback/cron/self-heal call is declining to auto-retry a failed job
		// (only a fresh user-initiated queue_draft() call resets status). If
		// this fires immediately after a fresh "Generate" click rather than
		// after a real wait, queue_draft()'s set_gen_status('queued', ...)
		// isn't winning its race against a fast loopback/self-heal call — that
		// race, not this line itself, is the bug to chase next.
		if ( 'error' === $status['state'] ) {
			Verlo_Log::info( 'gen.declined_stale_error', 'Declined to auto-retry: status was already \'error\'', array(
				'article_id'      => $article_id,
				'source'          => $source,
				'existing_message' => $status['message'],
				'status_age_s'    => time() - (int) $status['updated_at'],
			) );
			return 'errored';
		}

		// Only defer to "another worker" if the generation lock is actually held
		// (a request is genuinely mid-API-call). If the lock is free, no worker
		// is running — even if status says 'running', that was left by a worker
		// that has since died — so we take over now rather than waiting out a
		// fixed timeout. The lock itself prevents any duplicate paid API call.
		if ( self::lock_held( 'verlo_gen_lock_' . $article_id ) ) {
			$lock_raw = (string) get_option( '_verlo_lock_verlo_gen_lock_' . $article_id, '' );
			Verlo_Log::warn( 'gen.locked_busy', 'Declined to run: generation lock is held by another worker', array(
				'article_id'   => $article_id,
				'source'       => $source,
				'lock_age_s'   => $lock_raw ? ( time() - (int) strtok( $lock_raw, '|' ) ) : null,
				'lock_ttl_s'   => self::LOCK_TTL,
			) );
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
				Verlo_Log::info( 'gen.in_progress', 'generate_draft() found its own lock already held; leaving status as running for the next poll', array(
					'run_id' => $run_id, 'article_id' => $article_id, 'source' => $source,
				) );
				return 'in_progress';
			}
			if ( 'verlo_still_writing' === $res->get_error_code() ) {
				// Not an error - do_generate_draft() either just submitted the SaaS
				// job or checked on one already in flight and it isn't done yet.
				// Status is already 'running' (set above); the next poll/cron/
				// loopback cycle checks again. See that function's docblock for
				// why this exists as a distinct signal instead of blocking here.
				Verlo_Log::info( 'gen.still_writing', $res->get_error_message(), array(
					'run_id' => $run_id, 'article_id' => $article_id, 'source' => $source,
				) );
				return 'still_writing';
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
			// A rejected loopback call is silent by default from the browser's
			// point of view (the original request already returned and moved
			// on) - if this is the ONLY thing that ever runs for a queued job
			// (WP-Cron blocked, self-heal not yet due), the job just looks
			// stuck with zero explanation anywhere. Log it so that trail
			// exists: a missing/expired transient usually means the loopback
			// arrived unusually late (host under load, or a security plugin
			// delaying/duplicating the request), not a code bug.
			Verlo_Log::warn( 'gen.loopback_rejected', 'Loopback worker request rejected: missing/expired/mismatched token', array(
				'article_id'      => $article_id,
				'had_token'       => '' !== $token,
				'transient_found' => false !== $expected,
			) );
			status_header( 403 );
			exit;
		}
		delete_transient( 'verlo_gen_token_' . $article_id );

		// This used to duplicate run_pending()'s work inline (set 'running',
		// call generate_draft(), set 'done'/'error') instead of calling it -
		// which meant the loopback, the actual FIRST and fastest of the three
		// dispatch paths to reach a real article on every attempt, skipped
		// run_pending()'s lock check (a second concurrent caller couldn't be
		// deferred, only generate_draft()'s OWN inner lock caught that) and,
		// critically, never logged anything on failure. Confirmed live
		// 2026-08-24: this is why every "gen.declined_stale_error" trace from
		// the cron fallback found a real, fresh error with no explanation
		// anywhere - the loopback had already failed here, silently, every
		// single time, moments before cron ever checked.
		self::run_pending( $article_id, 'loopback' );
		exit;
	}

	/**
	 * Generate (or regenerate) the draft article for a brief's article id.
	 * Returns the post ID once actually finished, or WP_Error — including,
	 * routinely, a 'verlo_still_writing' WP_Error while the AI job it just
	 * submitted or checked on isn't done yet (see do_generate_draft(); this
	 * is never a blocking call, so it always returns fast regardless of how
	 * long the underlying article takes). Callers that need this driven to
	 * completion across repeated polls, not called directly once, should use
	 * queue_draft() (submit) + run_pending() (each subsequent check) instead.
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
		$lock_token = self::acquire_lock( $lock_key );
		if ( false === $lock_token ) {
			return new WP_Error(
				'verlo_in_progress',
				'This article is already being generated (started moments ago). Please wait for it to finish before trying again.'
			);
		}

		// try/finally guarantees this specific acquisition is always released
		// under its own token, however do_generate_draft() exits (normal
		// return, WP_Error, or an uncaught throwable) — so a stale-lock
		// reclaim by another worker can never have its lock deleted out from
		// under it by this call finishing late.
		try {
			$result = self::do_generate_draft( $article_id, $brief );
		} finally {
			self::release_lock( $lock_key, $lock_token );
		}
		return $result;
	}

	/** Lock TTL in seconds. Must comfortably exceed the true worst-case hold
	 * time for a SINGLE invocation — which, now that generation is
	 * submit-then-poll (do_generate_draft()) rather than one call that
	 * blocks until the AI write finishes, is much shorter than it used to
	 * be: either a single job-submission POST (~30s worst case), or a single
	 * status-poll GET followed — only once the AI is actually done — by the
	 * real build (block conversion, post creation, and apply_to_post()
	 * sideloading up to 4 images sequentially at up to 30s each,
	 * class-verlo-images.php). ~150s comfortably covers that build-phase
	 * worst case. A TTL shorter than the true worst case lets another
	 * dispatch path "steal" a lock from a worker still legitimately running,
	 * producing a duplicate paid API call and a duplicate draft post — this
	 * happened at a previous, too-low value (150s, back when a single
	 * invocation could hold the lock for the ENTIRE AI wait); it doesn't
	 * apply here since the AI wait itself no longer happens inside any one
	 * locked invocation at all. */
	const LOCK_TTL = 150;

	/**
	 * Acquire a short-lived generation lock. Returns a random owner token on
	 * success, or false if a lock is already held and fresh.
	 *
	 * Uses add_option() rather than get_transient()/set_transient() as the
	 * mutex primitive: the latter is a check-then-act race (get, then set, as
	 * two separate calls), so the loopback worker, the WP-Cron fallback, and
	 * the poll-driven self-heal could all observe "no lock" in the same
	 * instant and all call the paid API for the same article, producing
	 * duplicate draft posts. add_option() is atomic because wp_options has a
	 * UNIQUE key on option_name — a concurrent second INSERT for the same
	 * name simply fails, giving a real test-and-set.
	 *
	 * The stored value is "{timestamp}|{token}". The token lets release_lock()
	 * do a compare-and-delete instead of an unconditional delete, so a worker
	 * that started before the TTL expired and only finishes afterward can't
	 * delete the *next* worker's lock when it finally calls release_lock().
	 */
	protected static function acquire_lock( $key ) {
		$option = '_verlo_lock_' . $key;
		$token  = wp_generate_password( 12, false );

		if ( add_option( $option, time() . '|' . $token, '', 'no' ) ) {
			return $token;
		}

		// Lock exists — reclaim only if stale (holder died mid-generation, or
		// is still running well past a sane worst case). Losing the race to
		// reclaim a stale lock just means the other reclaimer wins; it doesn't
		// risk a duplicate post, since whichever process wins re-reads the
		// brief fresh before writing.
		$held_at = self::lock_held_at( $option );
		if ( $held_at && ( time() - $held_at ) > self::LOCK_TTL ) {
			update_option( $option, time() . '|' . $token, 'no' );
			return $token;
		}
		return false;
	}

	protected static function lock_held( $key ) {
		$held_at = self::lock_held_at( '_verlo_lock_' . $key );
		return $held_at && ( time() - $held_at ) <= self::LOCK_TTL;
	}

	/** Parses the "{timestamp}|{token}" option value; returns the timestamp
	 * (0 if unset) regardless of token. Shared by acquire_lock()/lock_held(). */
	private static function lock_held_at( $option ) {
		$raw = (string) get_option( $option, '' );
		if ( '' === $raw ) { return 0; }
		return (int) strtok( $raw, '|' );
	}

	/**
	 * Release a lock. When $token is given, only deletes if it still matches
	 * the token currently stored (compare-and-delete) — a worker that lost
	 * its lock to a stale-reclaim must not be able to delete the reclaimer's
	 * active lock out from under it. When $token is omitted, deletes
	 * unconditionally — used only as a last-resort defensive cleanup where no
	 * token is available (see the fatal-error catch in run_pending()).
	 */
	protected static function release_lock( $key, $token = null ) {
		$option = '_verlo_lock_' . $key;
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

	/**
	 * The actual generation work, run under the lock held by generate_draft().
	 * Each phase is timed server-side so the Logs tab shows exactly where the
	 * time goes (block conversion vs. images), and the true total duration for
	 * THIS invocation is recorded — but see set_gen_status()'s queued_at
	 * docblock for why that's not the same as true elapsed time overall.
	 *
	 * Submit-then-poll, not submit-and-block: this used to call write_article()
	 * once and block on it for however long the AI write took (up to 180s,
	 * Verlo_SaaS_Client::run_job()'s own timeout). That single long-lived
	 * request is exactly the kind background loopback dispatch is vulnerable
	 * to hosts silently killing (set_time_limit(0) only overrides PHP's own
	 * tracking, not a web-server-level execution ceiling some hosts enforce
	 * independently) — confirmed live 2026-08-24: two separate generations
	 * held their lock for 7+ and 11+ minutes with the underlying AI call
	 * having actually finished in under three, and no error, fatal, or
	 * completion ever logged for that stalled invocation, because it was
	 * killed somewhere a try/catch and even a finally block can't run.
	 *
	 * No single invocation now blocks: this function either submits the job
	 * (a single fast POST) and returns, or — once a job is already in
	 * flight — checks it ONCE (a single fast GET) and either hands off again
	 * or, only once the AI is actually done, does the real build. What drives
	 * repeated checks forward is the poll-driven self-heal already used
	 * elsewhere (admin/class-verlo-brief-admin.php's ajax_gen_status(), now
	 * triggered on every poll while a job is in flight, not just once
	 * "stalled") plus a self-rescheduling WP-Cron fallback (run_via_cron()).
	 */
	protected static function do_generate_draft( $article_id, $brief ) {
		$t_start = microtime( true );
		$timing  = array();

		$job_id = Verlo_Brief::get_saas_job( $article_id );

		if ( ! $job_id ) {
			$profile = Verlo_Profile::get();
			$payload = self::build_article_payload( $brief, $profile );
			$job_id  = Verlo_SaaS_Client::request_job( 'article', $payload );
			if ( is_wp_error( $job_id ) ) {
				// Only tolerate genuine transport-level failures (a network
				// blip reaching the SaaS at all) as "try again next cycle" -
				// business-logic errors (plan limit, billing, not connected,
				// a malformed response) are real and should surface right
				// away, not get silently retried for up to 10 minutes first.
				$transient = in_array( $job_id->get_error_code(), array( 'verlo_timeout', 'verlo_transport' ), true );
				$status    = Verlo_Brief::get_gen_status( $article_id );
				$age       = $status['queued_at'] ? ( time() - (int) $status['queued_at'] ) : 0;
				if ( $transient && $age <= 10 * MINUTE_IN_SECONDS ) {
					return new WP_Error( 'verlo_still_writing', 'Could not reach the Verlo server, will retry: ' . $job_id->get_error_message() );
				}
				return $job_id;
			}

			Verlo_Brief::set_saas_job( $article_id, $job_id );
			Verlo_Brief::set_gen_status( $article_id, 'running', 'Writing the article…' );
			return new WP_Error( 'verlo_still_writing', 'Article job submitted; waiting for the AI to finish.' );
		}

		// A job is already in flight for this cycle. Check it once — never a
		// blocking wait — and only proceed past here if it's genuinely done.
		$poll = Verlo_SaaS_Client::poll_job( $job_id );
		if ( is_wp_error( $poll ) ) {
			// A single failed status check (network blip, transient SaaS
			// hiccup) isn't fatal on its own — the old design tolerated up to
			// 3 in a row before giving up (Verlo_SaaS_Client::wait_for_result()),
			// and this is checked far more often now (every browser poll,
			// ~2.5s, plus WP-Cron every ~10s) so a transient failure just gets
			// retried moments later regardless. Only actually give up once the
			// WHOLE cycle has run unreasonably long — queued_at, not this one
			// poll — so a genuinely unreachable SaaS doesn't leave a job
			// stuck "running" forever.
			$status = Verlo_Brief::get_gen_status( $article_id );
			$age    = $status['queued_at'] ? ( time() - (int) $status['queued_at'] ) : 0;
			if ( $age > 10 * MINUTE_IN_SECONDS ) { return $poll; }
			return new WP_Error( 'verlo_still_writing', 'Status check failed, will retry: ' . $poll->get_error_message() );
		}

		$job_state = isset( $poll['status'] ) ? (string) $poll['status'] : 'unknown';
		if ( 'error' === $job_state ) {
			$msg = isset( $poll['message'] ) ? (string) $poll['message'] : 'Article generation failed.';
			return new WP_Error( 'verlo_job_error', $msg );
		}
		if ( 'done' !== $job_state ) {
			return new WP_Error( 'verlo_still_writing', 'Still waiting on the AI (status: ' . $job_state . ').' );
		}

		$parsed = self::parse_article_result( isset( $poll['result'] ) && is_array( $poll['result'] ) ? $poll['result'] : array() );
		if ( is_wp_error( $parsed ) ) { return $parsed; }

		$t = microtime( true );
		$content = self::sanitize_content( $parsed['content'], $brief );
		$content = Verlo_Text::scrub_stale_years( $content );
		$content = Verlo_Text::humanize( $content );
		// Extract the FAQ schema from the same clean HTML the reader will see
		// (before to_blocks() below rewrites it as Gutenberg block comments) so
		// the structured data can never drift from what's actually on the page.
		$faq_schema = class_exists( 'Verlo_Faq_Schema' ) ? Verlo_Faq_Schema::build( $content ) : '';
		$blocks  = self::to_blocks( $content ) . $faq_schema;
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

		// total_s is only this specific execution's own duration - accurate,
		// but not what "generated in Xs" should mean to a user, and actively
		// misleading whenever this ISN'T the execution that did the real
		// work (see set_gen_status()'s queued_at docblock for why that
		// happens: a stalled first attempt, then a second run that gets the
		// first one's already-finished result back near-instantly via the
		// SaaS side's idempotency key). wall_clock_s, timed from the user's
		// actual click, is what the UI displays; total_s stays purely for
		// the Logs tab's phase breakdown (images vs. block conversion,
		// $timing above), which IS about this specific execution — the AI
		// write itself no longer has a comparable single-execution timer,
		// since submitting and (on a later, separate invocation) collecting
		// it are now two different calls; wall_clock_s already covers it.
		$queued_at     = Verlo_Brief::get_gen_status( $article_id )['queued_at'];
		$wall_clock_s  = $queued_at ? round( microtime( true ) - $queued_at, 1 ) : $timing['total_s'];

		$run_id = Verlo_Brief::get_run_id( $article_id );

		// Persist the real server-side timing for the UI and diagnostics.
		Verlo_Log::info( 'gen.timing', 'Article generated in ' . $wall_clock_s . 's (this run took ' . $timing['total_s'] . 's)', array_merge( $timing, array(
			'wall_clock_s'  => $wall_clock_s,
			'run_id'        => $run_id,
			'article_id'    => $article_id,
			'keyword'       => $brief['keyword'],
			'word_target'   => (int) ( $brief['word_count'] ?? 0 ),
			'images'        => Verlo_Images::is_configured() ? 'on' : 'off',
		) ) );

		// Durable article-history record (survives map rebuilds and the event
		// log rolling over). Keyed by post_id so a regenerate updates in place.
		if ( class_exists( 'Verlo_Article_Log' ) ) {
			// Re-read the post rather than reuse $postarr['post_content']: the
			// optional image step above may have run a second wp_update_post()
			// with in-body images spliced in, and the version snapshot needs to
			// match what's actually live, not what was written before that.
			$final_post    = get_post( $post_id );
			$final_content = $final_post ? $final_post->post_content : $postarr['post_content'];

			Verlo_Article_Log::record( array(
				'post_id'     => (int) $post_id,
				'article_id'  => (int) $article_id,
				'keyword'     => (string) $brief['keyword'],
				'title'       => (string) $title,
				'pillar'      => (string) ( $brief['pillar'] ?? '' ),
				'word_target' => (int) ( $brief['word_count'] ?? 0 ),
				'gen_seconds' => (float) $wall_clock_s,
				'run_id'      => $run_id,
				'content'     => $final_content,
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
			'gen_seconds'=> $wall_clock_s,
		);
		Verlo_Brief::save( $article_id, $brief );

		// This cycle is genuinely finished — clear the in-flight job id so a
		// future fresh Generate click (a new cycle) submits its own new job
		// rather than finding this one still recorded.
		Verlo_Brief::set_saas_job( $article_id, '' );

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
	 * Build the article job payload. Pure — no I/O — split out of the old
	 * write_article() (which submitted-and-blocked in one call) so
	 * do_generate_draft() can submit and poll as two separate, both-fast
	 * steps instead.
	 */
	protected static function build_article_payload( $brief, $profile ) {
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

		// Send the server-side brief id back, if we have one, so the SaaS can
		// convert this brief out of the unconverted-cap pool atomically with
		// creating the article job (verlo-pricing-spec-v1.md 3.2/5.2). Briefs
		// generated before this field existed simply have none to send.
		if ( ! empty( $brief['brief_id'] ) ) {
			$payload['brief_id'] = (string) $brief['brief_id'];
		}

		return $payload;
	}

	/**
	 * Validate/sanitize a completed job's raw result. Pure — no I/O.
	 * Returns [ 'title', 'meta', 'excerpt', 'content' ] or WP_Error.
	 */
	protected static function parse_article_result( $result ) {
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

		$home            = wp_parse_url( home_url(), PHP_URL_HOST );
		$kept_outbound   = 0;
		$stripped_hosts  = array();
		$html = preg_replace_callback(
			'/<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
			function ( $m ) use ( $home, &$kept_outbound, &$stripped_hosts ) {
				$host = wp_parse_url( $m[1], PHP_URL_HOST );
				if ( ! $host || $host === $home ) {
					return $m[0]; // internal link -> keep as-is
				}
				if ( self::host_allowed( $host ) ) {
					// Authoritative outbound link: keep, dofollow, safe target attrs.
					$kept_outbound++;
					return '<a href="' . esc_url( $m[1] ) . '" target="_blank" rel="noopener">' . $m[2] . '</a>';
				}
				$stripped_hosts[] = $host; // unknown external link -> keep anchor text only
				return $m[2];
			},
			$html
		);

		// The brief asked for outbound links but none survived the allowlist -
		// either the model didn't add one, or it linked to a host not on this
		// site's allowlist (see outbound_allowlist()). Make this visible in the
		// Logs tab instead of a silent gap, and guarantee at least one real,
		// working outbound link via a verified Wikipedia fallback rather than
		// just hoping the next generation does better.
		if ( 0 === $kept_outbound ) {
			if ( class_exists( 'Verlo_Log' ) ) {
				Verlo_Log::warn(
					'outbound_link_missing',
					'No allowed outbound link survived sanitization for "' . $brief['keyword'] . '"; inserting a verified Wikipedia fallback.',
					array( 'stripped_hosts' => array_values( array_unique( $stripped_hosts ) ) )
				);
			}
			$fallback = self::wikipedia_fallback_link( $brief['keyword'] );
			if ( $fallback ) {
				$link = '<p>Learn more: <a href="' . esc_url( $fallback['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $fallback['title'] ) . '</a> on Wikipedia.</p>';
				// Insert just before the FAQ section if present, so it reads as
				// part of the body rather than a bolted-on afterthought.
				if ( preg_match( '/<h2[^>]*>\s*FAQ/i', $html, $fm, PREG_OFFSET_CAPTURE ) ) {
					$pos  = $fm[0][1];
					$html = substr( $html, 0, $pos ) . $link . "\n\n" . substr( $html, $pos );
				} else {
					$html .= "\n\n" . $link;
				}
			} elseif ( class_exists( 'Verlo_Log' ) ) {
				Verlo_Log::warn( 'outbound_link_fallback_failed', 'Wikipedia fallback lookup found no match for "' . $brief['keyword'] . '"; article published with zero outbound links.' );
			}
		}

		return $html;
	}

	/**
	 * Find a real, existing Wikipedia article for a keyword via the public
	 * opensearch API, so the guaranteed fallback link is never a 404. Returns
	 * [ 'title' => ..., 'url' => ... ] or null if no reasonable match exists
	 * or the lookup fails (never fatal - articles must still publish).
	 */
	protected static function wikipedia_fallback_link( $keyword ) {
		$keyword = trim( (string) $keyword );
		if ( '' === $keyword ) { return null; }

		$url = add_query_arg(
			array(
				'action' => 'opensearch',
				'search' => rawurlencode( $keyword ),
				'limit'  => 1,
				'format' => 'json',
			),
			'https://en.wikipedia.org/w/api.php'
		);

		$res = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		// opensearch shape: [ query, [titles], [descriptions], [urls] ]
		if ( empty( $body[1][0] ) || empty( $body[3][0] ) ) { return null; }

		return array( 'title' => (string) $body[1][0], 'url' => (string) $body[3][0] );
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
	 * Store SEO title / meta description / focus keyword under whichever SEO
	 * plugin(s) are actually active - detected, not assumed. Previously this
	 * wrote unconditionally to Yoast's and Rank Math's postmeta keys, which
	 * "worked" for those two specifically (writing meta an inactive plugin
	 * never reads is harmless) but silently did nothing on a site running
	 * SEOPress, The SEO Framework, or no SEO plugin at all - those meta
	 * title/descriptions never reached a theme or search engine anywhere.
	 *
	 * AIOSEO is deliberately not covered: its current major version stores
	 * this in its own custom DB table, not postmeta, and guessing at that
	 * schema without a real install to verify against risks silently writing
	 * nothing useful while looking like it worked - worse than the gap being
	 * visible in the log below.
	 */
	protected static function apply_seo_meta( $post_id, $brief, $parsed ) {
		$meta_desc = '' !== $parsed['meta'] ? $parsed['meta'] : $brief['angle'];
		$seo_title = '' !== $parsed['title'] ? $parsed['title'] : $brief['suggested_title'];
		$keyword   = $brief['keyword'];

		$seo_title = self::clamp_text( $seo_title, 60 );
		$meta_desc = self::clamp_text( $meta_desc, 155 );

		$applied = array();

		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
			$applied[] = 'yoast';
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			update_post_meta( $post_id, 'rank_math_title', $seo_title );
			update_post_meta( $post_id, 'rank_math_description', $meta_desc );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
			$applied[] = 'rank_math';
		}

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			update_post_meta( $post_id, '_seopress_titles_title', $seo_title );
			update_post_meta( $post_id, '_seopress_titles_desc', $meta_desc );
			update_post_meta( $post_id, '_seopress_analysis_target_kw', $keyword );
			$applied[] = 'seopress';
		}

		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || class_exists( 'The_SEO_Framework\Load' ) ) {
			// TSF reuses the old Genesis meta keys for backward compatibility
			// with themes/plugins built against Genesis - this is correct,
			// documented TSF behaviour, not a mistake.
			update_post_meta( $post_id, '_genesis_title', $seo_title );
			update_post_meta( $post_id, '_genesis_description', $meta_desc );
			$applied[] = 'the_seo_framework';
		}

		if ( empty( $applied ) && class_exists( 'Verlo_Log' ) ) {
			Verlo_Log::info( 'gen.no_seo_plugin', 'No supported SEO plugin detected - the generated SEO title and meta description were not written anywhere a theme or search engine would read them.', array(
				'post_id' => $post_id,
			) );
		}
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
			case 'table':
				return self::table_to_block( $dom, $node );
			case 'blockquote':
				return self::blockquote_to_block( $dom, $node );
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

	/**
	 * Wrap a <table> element in Gutenberg's table block markup so it renders
	 * as a native, editable table instead of falling through the default
	 * case, which previously wrapped it in a <p> tag - invalid HTML that
	 * Gutenberg flagged as content needing manual "attempt block recovery".
	 */
	protected static function table_to_block( $dom, $node ) {
		$inner = trim( preg_replace( '/\s+/', ' ', self::inner_html( $dom, $node ) ) );
		if ( '' === $inner ) { return ''; }
		return "<!-- wp:table -->\n"
			. "<figure class=\"wp-block-table\"><table class=\"wp-block-table\">{$inner}</table></figure>\n"
			. "<!-- /wp:table -->\n\n";
	}

	/**
	 * Wrap a <blockquote> in Gutenberg's quote block markup - the sparing,
	 * standalone-insight "pull quote" the article prompt is now allowed to
	 * use (see verlo-saas's buildArticleSystemPrompt() PULL QUOTE rule),
	 * kept distinct from a plain paragraph so it actually renders with the
	 * theme's real quote styling instead of silently degrading into
	 * unstyled text via the default case below.
	 */
	protected static function blockquote_to_block( $dom, $node ) {
		$inner = trim( preg_replace( '/\s+/', ' ', self::inner_html( $dom, $node ) ) );
		if ( '' === $inner ) { return ''; }
		// The AI is instructed to wrap the quote text in its own <p>, but
		// tolerate bare text too (Gutenberg's quote block still expects a
		// <p> child either way).
		if ( ! preg_match( '/<p[\s>]/i', $inner ) ) {
			$inner = "<p>{$inner}</p>";
		}
		return "<!-- wp:quote -->\n"
			. "<blockquote class=\"wp-block-quote\">{$inner}</blockquote>\n"
			. "<!-- /wp:quote -->\n\n";
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

