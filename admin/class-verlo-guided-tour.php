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

		self::render_step_banner( $stop, count( self::stops() ) );
	}

	protected static function render_step_banner( $stop, $total ) {
		$pct = round( ( $stop['index'] / $total ) * 100 );
		?>
		<div class="verlo-tour-banner">
			<div class="verlo-tour-progress"><div class="verlo-tour-progress-bar" style="width:<?php echo (int) $pct; ?>%;"></div></div>
			<div class="verlo-tour-body">
				<span class="verlo-tour-step-badge">Step <?php echo (int) ( $stop['index'] + 1 ); ?> of <?php echo (int) $total; ?></span>
				<div class="verlo-tour-copy">
					<p class="verlo-tour-title"><?php echo esc_html( $stop['title'] ); ?></p>
					<p class="verlo-tour-desc"><?php echo esc_html( $stop['body'] ); ?></p>
				</div>
				<a href="<?php echo esc_url( self::skip_url() ); ?>" class="verlo-tour-skip">Skip guided setup</a>
			</div>
		</div>
		<style>
			.verlo-tour-banner{background:linear-gradient(135deg,#15181a 0%,#2b3134 100%);border-radius:12px;padding:16px 20px;margin:16px 0 20px;color:#fff;}
			.verlo-tour-progress{height:4px;background:rgba(255,255,255,.15);border-radius:999px;margin-bottom:14px;overflow:hidden;}
			.verlo-tour-progress-bar{height:100%;background:#5fd68a;border-radius:999px;transition:width .3s ease;}
			.verlo-tour-body{display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
			.verlo-tour-step-badge{flex:none;background:rgba(255,255,255,.14);padding:4px 10px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:.02em;}
			.verlo-tour-copy{flex:1;min-width:200px;}
			.verlo-tour-title{margin:0;font-size:15px;font-weight:700;line-height:1.3;}
			.verlo-tour-desc{margin:2px 0 0;font-size:13px;color:rgba(255,255,255,.75);}
			.verlo-tour-skip{flex:none;font-size:12px;color:rgba(255,255,255,.6);text-decoration:underline;text-underline-offset:2px;}
			.verlo-tour-skip:hover{color:#fff;}
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

	protected static function render_complete_banner() {
		?>
		<div class="verlo-tour-banner" style="background:linear-gradient(135deg,#0e6b45 0%,#189a62 100%);">
			<p style="margin:0;font-size:15px;font-weight:700;">🎉 You're all set — your first article is generated.</p>
			<p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.85);">From here, keep working through your topical map at your own pace — new briefs and articles work exactly the same way.</p>
		</div>
		<?php
	}
}
