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
			wp_die( esc_html__( 'Permission denied.', 'verlo' ) );
		}
		update_option( self::OPT_ACTIVE, 1, 'no' );
		$url = self::current_stop_url();
		wp_safe_redirect( $url ? $url : admin_url( 'admin.php?page=' . Verlo_Onboarding_Admin::SLUG ) );
		exit;
	}

	public static function handle_skip() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'verlo_tour_skip' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'verlo' ) );
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
				'title'  => __( 'Connect your Verlo license', 'verlo' ),
				'body'   => __( 'Paste your license key below, or use the one-click "Connect with Verlo" button.', 'verlo' ),
				'done'   => $connected,
			),
			array(
				'page'   => 'verlo-profile',
				'target' => 'profile-analyze',
				'title'  => __( 'Complete your Strategy Profile', 'verlo' ),
				'body'   => __( 'Click "Analyze my site with Verlo" to auto-fill this from your existing content.', 'verlo' ),
				'done'   => Verlo_Profile::is_complete(),
			),
			array(
				'page'   => 'verlo-map',
				'target' => 'map-generate',
				'title'  => __( 'Generate your topical map', 'verlo' ),
				'body'   => __( 'Click "Generate map with Verlo" to plan your content pillars and articles.', 'verlo' ),
				'done'   => ! empty( $map['pillars'] ),
			),
			array(
				'page'   => 'verlo-map',
				'target' => 'map-approve',
				'title'  => __( 'Approve your topical map', 'verlo' ),
				'body'   => __( 'Review the plan below, then click "Approve map" to unlock content generation.', 'verlo' ),
				'done'   => Verlo_Topical_Map::is_approved(),
			),
			array(
				'page'   => 'verlo-briefs',
				'target' => 'brief-generate',
				'title'  => __( 'Generate your first content brief', 'verlo' ),
				'body'   => __( 'Click "Generate next brief" to outline your first planned article.', 'verlo' ),
				'done'   => class_exists( 'Verlo_Brief' ) && Verlo_Brief::count() > 0,
			),
			array(
				'page'   => 'verlo-briefs',
				'target' => 'article-generate',
				'title'  => __( 'Generate your first article', 'verlo' ),
				'body'   => __( 'Open the brief below, then click "Generate draft article".', 'verlo' ),
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
			<div class="verlo-tour-strip-top">
				<span class="verlo-tour-step-badge">
					<?php
					printf(
						/* translators: 1: current step number, 2: total steps */
						esc_html__( 'Step %1$d of %2$d', 'verlo' ),
						(int) ( $stop['index'] + 1 ),
						(int) $total
					);
					?>
				</span>
				<span class="verlo-tour-strip-title"><?php echo esc_html( $stop['title'] ); ?></span>
				<a href="#verlo-tour-target" class="verlo-tour-jump"><?php esc_html_e( 'Take me there ↓', 'verlo' ); ?></a>
				<a href="<?php echo esc_url( self::skip_url() ); ?>" class="verlo-tour-skip"><?php esc_html_e( 'Skip guided setup', 'verlo' ); ?></a>
			</div>
			<p class="verlo-tour-strip-desc"><?php echo esc_html( $stop['body'] ); ?></p>
		</div>
		<style>
			.verlo-tour-strip{background:linear-gradient(135deg,#15181a 0%,#2b3134 100%);border-radius:12px;padding:14px 18px;margin:16px 0 20px;color:#fff;}
			.verlo-tour-progress{height:4px;background:rgba(255,255,255,.15);border-radius:999px;margin-bottom:12px;overflow:hidden;}
			.verlo-tour-progress-bar{height:100%;background:#5fd68a;border-radius:999px;transition:width .3s ease;}
			.verlo-tour-strip-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;}
			.verlo-tour-strip .verlo-tour-step-badge{flex:none;background:rgba(255,255,255,.14);padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:.02em;}
			.verlo-tour-strip-title{font-weight:600;flex:1;min-width:140px;}
			.verlo-tour-jump{flex:none;color:#8ecbff;text-decoration:underline;text-underline-offset:2px;font-weight:600;}
			.verlo-tour-jump:hover{color:#fff;}
			.verlo-tour-strip .verlo-tour-skip{flex:none;color:rgba(255,255,255,.6);text-decoration:underline;text-underline-offset:2px;}
			.verlo-tour-strip .verlo-tour-skip:hover{color:#fff;}
			.verlo-tour-strip-desc{margin:8px 0 0;font-size:13px;color:rgba(255,255,255,.75);line-height:1.45;}

			/* Floating popup — pinned near the real element via JS, NOT part of
			   page flow (see render_target_callout()). Starts hidden/offset so
			   it never flashes at (0,0) before positioning runs. */
			.verlo-tour-popup{position:fixed;z-index:99999;background:#fff;border:1px solid #d8dade;border-radius:10px;padding:16px 18px;max-width:320px;box-shadow:0 10px 30px rgba(0,0,0,.18);opacity:0;transform:translateY(4px);transition:opacity .18s ease,transform .18s ease;pointer-events:none;}
			.verlo-tour-popup.is-visible{opacity:1;transform:translateY(0);pointer-events:auto;animation:verlo-tour-popup-pulse 1.8s ease-in-out infinite;}
			.verlo-tour-popup-arrow{position:absolute;left:20px;width:14px;height:14px;background:#fff;border-left:1px solid #d8dade;border-top:1px solid #d8dade;transform:rotate(45deg);}
			.verlo-tour-popup--below .verlo-tour-popup-arrow{top:-7px;}
			.verlo-tour-popup--above .verlo-tour-popup-arrow{bottom:-7px;top:auto;transform:rotate(225deg);}
			.verlo-tour-popup .verlo-tour-step-badge{display:inline-block;background:#eef4fb;color:#2271b1;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-bottom:6px;}
			.verlo-tour-popup .verlo-tour-title{margin:0 0 2px;font-size:14px;font-weight:700;color:#1d2327;}
			.verlo-tour-popup .verlo-tour-desc{margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.45;}
			.verlo-tour-popup .verlo-tour-skip{font-size:12px;color:#787c82;text-decoration:underline;text-underline-offset:2px;}
			.verlo-tour-popup .verlo-tour-skip:hover{color:#1d2327;}
			@keyframes verlo-tour-popup-pulse{
				0%,100%{box-shadow:0 10px 30px rgba(0,0,0,.18), 0 0 0 0 rgba(34,113,177,.55);}
				50%{box-shadow:0 10px 30px rgba(0,0,0,.18), 0 0 0 8px rgba(34,113,177,0);}
			}

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
	 * The actual instructional tooltip for the current stop — a floating
	 * popup positioned by JS against the real element (see
	 * target_id_attr() callers), deliberately NOT inserted into page flow
	 * so it never pushes surrounding content around. Rendered by the page
	 * immediately after the real element it refers to (that adjacency is
	 * just where the markup lives in the DOM; CSS/JS is what actually
	 * places it). A no-op unless $target is the current stop, so it's safe
	 * to call unconditionally next to every possible tour target on a page.
	 */
	public static function render_target_callout( $target ) {
		if ( ! self::is_current_target( $target ) ) { return; }
		$stop  = self::current_stop();
		$total = count( self::stops() );
		?>
		<div class="verlo-tour-popup" id="verlo-tour-popup">
			<div class="verlo-tour-popup-arrow"></div>
			<span class="verlo-tour-step-badge">
				<?php
				printf(
					/* translators: 1: current step number, 2: total steps */
					esc_html__( 'Step %1$d of %2$d', 'verlo' ),
					(int) ( $stop['index'] + 1 ),
					(int) $total
				);
				?>
			</span>
			<p class="verlo-tour-title"><?php echo esc_html( $stop['title'] ); ?></p>
			<p class="verlo-tour-desc"><?php echo esc_html( $stop['body'] ); ?></p>
			<a href="<?php echo esc_url( self::skip_url() ); ?>" class="verlo-tour-skip"><?php esc_html_e( 'Skip guided setup', 'verlo' ); ?></a>
		</div>
		<script>
		( function () {
			var target = document.getElementById( 'verlo-tour-target' );
			var popup  = document.getElementById( 'verlo-tour-popup' );
			if ( ! target || ! popup ) { return; }

			function position() {
				var r  = target.getBoundingClientRect();
				var pw = popup.offsetWidth;
				var ph = popup.offsetHeight;
				var above = ( r.bottom + 14 + ph > window.innerHeight - 16 ) && ( r.top - 14 - ph > 16 );
				var top   = above ? ( r.top - ph - 14 ) : ( r.bottom + 14 );
				var left  = Math.min( Math.max( 16, r.left ), window.innerWidth - pw - 16 );

				popup.style.top  = top + 'px';
				popup.style.left = left + 'px';
				popup.classList.toggle( 'verlo-tour-popup--above', above );
				popup.classList.toggle( 'verlo-tour-popup--below', ! above );
				popup.classList.add( 'is-visible' );
			}

			target.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			setTimeout( position, 350 ); // after the smooth scroll settles
			window.addEventListener( 'resize', position );
			window.addEventListener( 'scroll', position, { passive: true } );
		} )();
		</script>
		<?php
	}

	protected static function render_complete_banner() {
		?>
		<div class="verlo-tour-banner" style="background:linear-gradient(135deg,#0e6b45 0%,#189a62 100%);">
			<p style="margin:0;font-size:15px;font-weight:700;">🎉 <?php esc_html_e( "You're all set — your first article is generated.", 'verlo' ); ?></p>
			<p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.85);"><?php esc_html_e( 'From here, keep working through your topical map at your own pace — new briefs and articles work exactly the same way.', 'verlo' ); ?></p>
		</div>
		<?php
	}
}
