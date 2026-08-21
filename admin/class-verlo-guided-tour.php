<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A hands-on, cross-page guided walkthrough of the real setup pipeline —
 * connect, strategy profile, topical map (generate + approve), first
 * brief, first article. Deliberately NOT automation: every stop points at
 * a real button on a real page and waits for the user to actually click
 * it themselves. This exists because the pipeline's per-step friction is
 * partly load-bearing (credit cost per generation, a review checkpoint
 * before anything is written, the free plan's monthly cap meaning
 * something) — batching or auto-running any of it would undermine that.
 * What was actually missing was orientation: which page to go to next and
 * what to click there. This adds that, nothing more.
 *
 * "Current stop" is computed fresh from real plugin state every render —
 * same principle as Verlo_Onboarding_Admin's checklist, and deliberately
 * not the same code (the checklist's shape is title/desc/cta for a status
 * list; this needs a page+CSS-target pair to know what to highlight where).
 * Never a separately tracked step counter that could drift from reality.
 */
class Verlo_Guided_Tour {

	const OPT_ACTIVE = 'verlo_tour_active';

	public static function init() {
		add_action( 'admin_post_verlo_tour_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_verlo_tour_skip', array( __CLASS__, 'handle_skip' ) );
	}

	public static function is_active() {
		return (bool) get_option( self::OPT_ACTIVE, false );
	}

	public static function start_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=verlo_tour_start' ), 'verlo_tour_start' );
	}

	protected static function skip_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=verlo_tour_skip' ), 'verlo_tour_skip' );
	}

	public static function handle_start() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'verlo_tour_start' ) ) {
			wp_die( 'Permission denied.' );
		}
		update_option( self::OPT_ACTIVE, 1, 'no' );
		$url = self::current_stop_url();
		wp_safe_redirect( $url ? $url : admin_url( 'admin.php?page=' . Verlo_Onboarding_Admin::SLUG ) );
		exit;
	}

	public static function handle_skip() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'verlo_tour_skip' ) ) {
			wp_die( 'Permission denied.' );
		}
		update_option( self::OPT_ACTIVE, 0, 'no' );
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=verlo' ) );
		exit;
	}

	/**
	 * Ordered tour stops. Each maps to one real, clickable action on one
	 * real page. 'done' is read live from the same plugin state
	 * Verlo_Onboarding_Admin's checklist uses — never a tracked flag.
	 */
	protected static function stops() {
		$connected = Verlo_Auth::is_connected();
		$map       = Verlo_Topical_Map::get();

		return array(
			array(
				'page'   => 'verlo-profile',
				'target' => 'connect',
				'title'  => 'Connect your Verlo license',
				'body'   => 'Paste your license key below, or use the one-click "Connect with Verlo" button.',
				'done'   => $connected,
			),
			array(
				'page'   => 'verlo-profile',
				'target' => 'profile-analyze',
				'title'  => 'Complete your Strategy Profile',
				'body'   => 'Click "Analyze my site with Verlo" to auto-fill this from your existing content.',
				'done'   => Verlo_Profile::is_complete(),
			),
			array(
				'page'   => 'verlo-map',
				'target' => 'map-generate',
				'title'  => 'Generate your topical map',
				'body'   => 'Click "Generate map with Verlo" to plan your content pillars and articles.',
				'done'   => ! empty( $map['pillars'] ),
			),
			array(
				'page'   => 'verlo-map',
				'target' => 'map-approve',
				'title'  => 'Approve your topical map',
				'body'   => 'Review the plan below, then click "Approve map" to unlock content generation.',
				'done'   => Verlo_Topical_Map::is_approved(),
			),
			array(
				'page'   => 'verlo-briefs',
				'target' => 'brief-generate',
				'title'  => 'Generate your first content brief',
				'body'   => 'Click "Generate next brief" to outline your first planned article.',
				'done'   => class_exists( 'Verlo_Brief' ) && Verlo_Brief::count() > 0,
			),
			array(
				'page'   => 'verlo-briefs',
				'target' => 'article-generate',
				'title'  => 'Generate your first article',
				'body'   => 'Open the brief below, then click "Generate draft article".',
				'done'   => class_exists( 'Verlo_Article_Log' ) && Verlo_Article_Log::count() > 0,
			),
		);
	}

	protected static function current_stop() {
		foreach ( self::stops() as $i => $stop ) {
			if ( ! $stop['done'] ) {
				$stop['index'] = $i;
				return $stop;
			}
		}
		return null;
	}

	protected static function current_stop_url() {
		$stop = self::current_stop();
		return $stop ? admin_url( 'admin.php?page=' . $stop['page'] ) : null;
	}

	/**
	 * True when $target is the single real UI element the tour currently
	 * wants the user to click. Pages use this to decide whether to anchor
	 * an id onto that element and render a callout next to it — see
	 * target_id_attr() and render_target_callout() below.
	 */
	public static function is_current_target( $target ) {
		if ( ! self::is_active() ) { return false; }
		$stop = self::current_stop();
		return $stop && $stop['target'] === $target;
	}

	/**
	 * Echo `id="verlo-tour-target"` on the current stop's real element, and
	 * only that one — this is what the progress strip's "Take me there"
	 * link (a plain #verlo-tour-target anchor, no JS) actually jumps to.
	 * Safe against duplicate-id collisions since exactly one target is ever
	 * "current" at a time across the whole page.
	 */
	public static function target_id_attr( $target ) {
		return self::is_current_target( $target ) ? ' id="verlo-tour-target"' : '';
	}

	/**
	 * Call from the top of a page's render(), passing that page's own menu
	 * slug. Renders nothing unless the tour is active AND this page is the
	 * current stop. Renders a one-time completion banner (and turns the
	 * tour off) the first time every real step turns out already done.
	 */
	public static function maybe_render_banner( $page_slug ) {
		if ( ! self::is_active() ) { return; }

		$stop = self::current_stop();

		if ( ! $stop ) {
			update_option( self::OPT_ACTIVE, 0, 'no' );
			self::render_complete_banner();
			return;
		}

		if ( $stop['page'] !== $page_slug ) { return; }

		self::render_progress_strip( $stop, count( self::stops() ) );
	}

	/**
	 * Slim, page-level strip: which step you're on and a real link to it.
	 * The actual instructions live in render_target_callout(), pinned next
	 * to the real button — this strip is orientation, not a second copy of
	 * the same instructions.
	 */
	protected static function render_progress_strip( $stop, $total ) {
		$pct = round( ( $stop['index'] / $total ) * 100 );
		?>
		<div class="verlo-tour-strip">
			<div class="verlo-tour-progress"><div class="verlo-tour-progress-bar" style="width:<?php echo (int) $pct; ?>%;"></div></div>
			<div class="verlo-tour-strip-body">
				<span class="verlo-tour-step-badge">Step <?php echo (int) ( $stop['index'] + 1 ); ?> of <?php echo (int) $total; ?></span>
				<span class="verlo-tour-strip-title"><?php echo esc_html( $stop['title'] ); ?></span>
				<a href="#verlo-tour-target" class="verlo-tour-jump">Take me there &darr;</a>
				<a href="<?php echo esc_url( self::skip_url() ); ?>" class="verlo-tour-skip">Skip guided setup</a>
			</div>
		</div>
		<style>
			.verlo-tour-strip{background:linear-gradient(135deg,#15181a 0%,#2b3134 100%);border-radius:12px;padding:12px 18px;margin:16px 0 20px;color:#fff;}
			.verlo-tour-progress{height:4px;background:rgba(255,255,255,.15);border-radius:999px;margin-bottom:10px;overflow:hidden;}
			.verlo-tour-progress-bar{height:100%;background:#5fd68a;border-radius:999px;transition:width .3s ease;}
			.verlo-tour-strip-body{display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;}
			.verlo-tour-strip .verlo-tour-step-badge{flex:none;background:rgba(255,255,255,.14);padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:.02em;}
			.verlo-tour-strip-title{font-weight:600;flex:1;min-width:140px;}
			.verlo-tour-jump{flex:none;color:#8ecbff;text-decoration:underline;text-underline-offset:2px;font-weight:600;}
			.verlo-tour-jump:hover{color:#fff;}
			.verlo-tour-strip .verlo-tour-skip{flex:none;color:rgba(255,255,255,.6);text-decoration:underline;text-underline-offset:2px;}
			.verlo-tour-strip .verlo-tour-skip:hover{color:#fff;}

			/* Callout pinned next to the real element — see render_target_callout(). */
			.verlo-tour-callout{position:relative;background:#fff;border:1px solid #d8dade;border-left:3px solid #2271b1;border-radius:8px;padding:14px 16px;margin:12px 0 4px;max-width:420px;box-shadow:0 4px 14px rgba(0,0,0,.08);}
			.verlo-tour-callout-arrow{position:absolute;top:-7px;left:20px;width:12px;height:12px;background:#fff;border-left:1px solid #d8dade;border-top:1px solid #d8dade;transform:rotate(45deg);}
			.verlo-tour-callout .verlo-tour-step-badge{display:inline-block;background:#eef4fb;color:#2271b1;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-bottom:6px;}
			.verlo-tour-callout .verlo-tour-title{margin:0 0 2px;font-size:14px;font-weight:700;color:#1d2327;}
			.verlo-tour-callout .verlo-tour-desc{margin:0 0 8px;font-size:13px;color:#50575e;line-height:1.4;}
			.verlo-tour-callout .verlo-tour-skip{font-size:12px;color:#787c82;text-decoration:underline;text-underline-offset:2px;}
			.verlo-tour-callout .verlo-tour-skip:hover{color:#1d2327;}

			[data-verlo-tour-target="<?php echo esc_attr( $stop['target'] ); ?>"]{
				position:relative;
				border-radius:6px;
				animation:verlo-tour-pulse 1.7s ease-in-out infinite;
			}
			@keyframes verlo-tour-pulse{
				0%,100%{box-shadow:0 0 0 0 rgba(34,113,177,.5);}
				50%{box-shadow:0 0 0 7px rgba(34,113,177,0);}
			}
		</style>
		<?php
	}

	/**
	 * The actual instructional tooltip, rendered by the page immediately
	 * after the real element it refers to (see target_id_attr() callers).
	 * A no-op unless $target is the current stop, so it's safe to call
	 * unconditionally next to every possible tour target on a page.
	 */
	public static function render_target_callout( $target ) {
		if ( ! self::is_current_target( $target ) ) { return; }
		$stop  = self::current_stop();
		$total = count( self::stops() );
		?>
		<div class="verlo-tour-callout">
			<div class="verlo-tour-callout-arrow"></div>
			<span class="verlo-tour-step-badge">Step <?php echo (int) ( $stop['index'] + 1 ); ?> of <?php echo (int) $total; ?></span>
			<p class="verlo-tour-title"><?php echo esc_html( $stop['title'] ); ?></p>
			<p class="verlo-tour-desc"><?php echo esc_html( $stop['body'] ); ?></p>
			<a href="<?php echo esc_url( self::skip_url() ); ?>" class="verlo-tour-skip">Skip guided setup</a>
		</div>
		<?php
	}

	protected static function render_complete_banner() {
		?>
		<div class="verlo-tour-banner" style="background:linear-gradient(135deg,#0e6b45 0%,#189a62 100%);">
			<p style="margin:0;font-size:15px;font-weight:700;">🎉 You're all set — your first article is generated.</p>
			<p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.85);">From here, keep working through your topical map at your own pace — new briefs and articles work exactly the same way.</p>
		</div>
		<?php
	}
}
