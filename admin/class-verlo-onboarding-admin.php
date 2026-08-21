<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A single "Getting Started" checklist reflecting the real pipeline state —
 * connect -> knowledge graph -> strategy profile -> topical map -> content
 * briefs -> first article — end to end. Replaces the old activation
 * redirect's bare drop onto the Knowledge Graph page, which showed a
 * brand-new install the exact same view as a returning user with nothing
 * to distinguish "you just installed this" from "your graph happens to be
 * empty right now."
 *
 * Every step reads real, already-existing state (Verlo_Auth::is_connected(),
 * KG stats, Verlo_Profile::is_complete(), etc.) rather than tracking its own
 * "wizard progress" flag — so it can never drift out of sync with what's
 * actually true, and stays useful as a permanent status page a returning
 * user can check at a glance, not just a one-time first-run screen.
 */
class Verlo_Onboarding_Admin {

	const SLUG = 'verlo-getting-started';

	public static function init() {
		// Default priority (10), same as Verlo_Admin's own add_menu_page()
		// call for the 'verlo' top-level menu — deliberately NOT earlier.
		// This used to fire at priority 5, before add_menu_page('verlo', ...)
		// had run: WordPress's get_plugin_page_hookname() decides this
		// submenu's routing hook by checking $admin_page_hooks['verlo'],
		// which add_menu_page() is what populates — call add_submenu_page()
		// before that's happened and the hookname it computes silently comes
		// out wrong, breaking navigation to this page entirely (confirmed
		// live 2026-08-21: clicking the sidebar link landed on a raw 404,
		// https://.../wp-admin/verlo-getting-started, missing admin.php?page=
		// entirely). verlo.php calls Verlo_Admin::init() immediately before
		// Verlo_Onboarding_Admin::init(), and same-priority admin_menu
		// callbacks fire in registration order, so plain default priority
		// here is both correct and still lands this page second in the
		// sidebar, right after Verlo's own Knowledge Graph page.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu() {
		add_submenu_page(
			'verlo',
			__( 'Getting Started', 'verlo' ),
			__( 'Getting Started', 'verlo' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Ordered pipeline steps. Each 'done' value reads real plugin state —
	 * see the class docblock for why nothing here is its own tracked flag.
	 */
	protected static function steps() {
		$kg  = Verlo_Knowledge_Graph::stats();
		$map = Verlo_Topical_Map::stats();

		return array(
			array(
				'title' => __( 'Connect your Verlo license', 'verlo' ),
				'desc'  => __( 'Link this site to your Verlo account so it can talk to the Verlo service.', 'verlo' ),
				'done'  => Verlo_Auth::is_connected(),
				'url'   => admin_url( 'admin.php?page=verlo-profile' ),
				'cta'   => __( 'Connect', 'verlo' ),
			),
			array(
				'title' => __( 'Build your knowledge graph', 'verlo' ),
				'desc'  => __( 'Verlo indexes your existing content so it can plan around what you already have, and link new articles to it.', 'verlo' ),
				'done'  => $kg['objects'] > 0,
				'url'   => admin_url( 'admin.php?page=verlo' ),
				'cta'   => __( 'View progress', 'verlo' ),
			),
			array(
				'title' => __( 'Complete your Strategy Profile', 'verlo' ),
				'desc'  => __( 'Tell Verlo your niche, audience, and voice so what it writes actually sounds like you.', 'verlo' ),
				'done'  => Verlo_Profile::is_complete(),
				'url'   => admin_url( 'admin.php?page=verlo-profile' ),
				'cta'   => __( 'Fill in profile', 'verlo' ),
			),
			array(
				'title' => __( 'Generate and approve a Topical Map', 'verlo' ),
				'desc'  => __( 'A plan of content pillars and articles, built from your profile and knowledge graph.', 'verlo' ),
				'done'  => Verlo_Topical_Map::is_approved(),
				'url'   => admin_url( 'admin.php?page=verlo-map' ),
				'cta'   => $map['pillars'] > 0 ? __( 'Review map', 'verlo' ) : __( 'Generate map', 'verlo' ),
			),
			array(
				'title' => __( 'Generate your first content brief', 'verlo' ),
				'desc'  => __( 'A structured outline for one planned article, generated from the topical map.', 'verlo' ),
				'done'  => Verlo_Brief::count() > 0,
				'url'   => admin_url( 'admin.php?page=verlo-briefs' ),
				'cta'   => __( 'Generate a brief', 'verlo' ),
			),
			array(
				'title' => __( 'Generate your first article', 'verlo' ),
				'desc'  => __( 'A publish-ready draft, written from the brief, waiting in your Posts list for your review.', 'verlo' ),
				'done'  => Verlo_Article_Log::count() > 0,
				'url'   => admin_url( 'admin.php?page=verlo-briefs' ),
				'cta'   => __( 'Generate an article', 'verlo' ),
			),
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$steps      = self::steps();
		$done_count = count(
			array_filter(
				$steps,
				function ( $s ) {
					return $s['done'];
				}
			)
		);
		$total = count( $steps );
		?>
		<div class="wrap verlo-wrap">
			<h1><?php esc_html_e( 'Getting Started', 'verlo' ); ?>
				<a href="<?php echo esc_url( VERLO_DOCS_URL ); ?>" target="_blank" rel="noopener noreferrer" class="page-title-action"><?php esc_html_e( 'Help & Docs', 'verlo' ); ?></a>
			</h1>
			<p class="verlo-sub">
				<?php if ( $done_count === $total ) : ?>
					<?php esc_html_e( "You've completed the full Verlo pipeline at least once. This page always reflects where you stand — come back any time.", 'verlo' ); ?>
				<?php else : ?>
					<?php
					printf(
						/* translators: 1: steps completed, 2: total steps */
						esc_html__( '%1$d of %2$d steps done. Work through these in order — each one builds on the last.', 'verlo' ),
						(int) $done_count,
						(int) $total
					);
					?>
				<?php endif; ?>
			</p>

			<?php if ( $done_count < $total ) : ?>
				<div class="verlo-card" style="margin-top:14px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
					<div>
						<h2 style="margin-bottom:2px;">
							<?php esc_html_e( 'New here? Get a hands-on walkthrough', 'verlo' ); ?>
							<?php if ( Verlo_Guided_Tour::is_active() ) : ?>
								<span class="verlo-badge info"><?php esc_html_e( 'In progress', 'verlo' ); ?></span>
							<?php endif; ?>
						</h2>
						<p class="verlo-sub" style="margin-bottom:0;">
							<?php echo Verlo_Guided_Tour::is_active() ? esc_html__( 'Pick up where you left off.', 'verlo' ) : esc_html__( "We'll take you to each page in order and point at exactly what to click — nothing happens automatically, you're doing the real setup.", 'verlo' ); ?>
						</p>
					</div>
					<a href="<?php echo esc_url( Verlo_Guided_Tour::start_url() ); ?>" class="button button-primary button-hero" style="flex:none;">
						<?php echo Verlo_Guided_Tour::is_active() ? esc_html__( 'Resume guided setup →', 'verlo' ) : esc_html__( 'Start guided setup →', 'verlo' ); ?>
					</a>
				</div>
			<?php endif; ?>

			<div class="verlo-card verlo-card-full">
				<h2>
					<?php esc_html_e( 'Setup checklist', 'verlo' ); ?>
					<?php if ( $done_count === $total ) : ?>
						<span class="verlo-badge ok"><?php esc_html_e( 'Complete', 'verlo' ); ?></span>
					<?php else : ?>
						<span class="verlo-badge info">
							<?php
							printf(
								/* translators: 1: steps completed, 2: total steps */
								esc_html__( '%1$d of %2$d', 'verlo' ),
								(int) $done_count,
								(int) $total
							);
							?>
						</span>
					<?php endif; ?>
				</h2>
				<ol style="list-style:none;margin:6px 0 0;padding:0;">
					<?php foreach ( $steps as $i => $step ) : ?>
						<li style="display:flex;align-items:flex-start;gap:14px;padding:16px 0;<?php echo 0 === $i ? '' : 'border-top:1px solid #e3e5e8;'; ?>">
							<span class="verlo-badge <?php echo $step['done'] ? 'ok' : 'off'; ?>" style="flex:0 0 26px;height:26px;padding:0;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;">
								<?php echo $step['done'] ? '&#10003;' : (int) ( $i + 1 ); ?>
							</span>
							<div style="flex:1;">
								<p style="margin:0 0 2px;font-weight:600;"><?php echo esc_html( $step['title'] ); ?></p>
								<p class="verlo-sub" style="margin:0;">
									<?php echo esc_html( $step['desc'] ); ?>
								</p>
							</div>
							<div style="flex:0 0 auto;">
								<a href="<?php echo esc_url( $step['url'] ); ?>" class="button<?php echo ( ! $step['done'] ) ? ' button-primary' : ''; ?>">
									<?php echo esc_html( $step['done'] ? __( 'Review', 'verlo' ) : $step['cta'] ); ?>
								</a>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>
		</div>
		<?php
	}
}
