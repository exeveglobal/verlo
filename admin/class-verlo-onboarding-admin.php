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
			'Getting Started',
			'Getting Started',
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
				'title' => 'Connect your Verlo license',
				'desc'  => 'Link this site to your Verlo account so it can talk to the Verlo service.',
				'done'  => Verlo_Auth::is_connected(),
				'url'   => admin_url( 'admin.php?page=verlo-profile' ),
				'cta'   => 'Connect',
			),
			array(
				'title' => 'Build your knowledge graph',
				'desc'  => 'Verlo indexes your existing content so it can plan around what you already have, and link new articles to it.',
				'done'  => $kg['objects'] > 0,
				'url'   => admin_url( 'admin.php?page=verlo' ),
				'cta'   => 'View progress',
			),
			array(
				'title' => 'Complete your Strategy Profile',
				'desc'  => 'Tell Verlo your niche, audience, and voice so what it writes actually sounds like you.',
				'done'  => Verlo_Profile::is_complete(),
				'url'   => admin_url( 'admin.php?page=verlo-profile' ),
				'cta'   => 'Fill in profile',
			),
			array(
				'title' => 'Generate and approve a Topical Map',
				'desc'  => 'A plan of content pillars and articles, built from your profile and knowledge graph.',
				'done'  => Verlo_Topical_Map::is_approved(),
				'url'   => admin_url( 'admin.php?page=verlo-map' ),
				'cta'   => $map['pillars'] > 0 ? 'Review map' : 'Generate map',
			),
			array(
				'title' => 'Generate your first content brief',
				'desc'  => 'A structured outline for one planned article, generated from the topical map.',
				'done'  => Verlo_Brief::count() > 0,
				'url'   => admin_url( 'admin.php?page=verlo-briefs' ),
				'cta'   => 'Generate a brief',
			),
			array(
				'title' => 'Generate your first article',
				'desc'  => 'A publish-ready draft, written from the brief, waiting in your Posts list for your review.',
				'done'  => Verlo_Article_Log::count() > 0,
				'url'   => admin_url( 'admin.php?page=verlo-briefs' ),
				'cta'   => 'Generate an article',
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
			<h1>Getting Started
				<a href="<?php echo esc_url( VERLO_DOCS_URL ); ?>" target="_blank" rel="noopener noreferrer" class="page-title-action">Help &amp; Docs</a>
			</h1>
			<p style="margin-top:2px;color:#646970;">
				<?php if ( $done_count === $total ) : ?>
					You've completed the full Verlo pipeline at least once. This page always reflects where you stand — come back any time.
				<?php else : ?>
					<?php echo (int) $done_count; ?> of <?php echo (int) $total; ?> steps done. Work through these in order — each one builds on the last.
				<?php endif; ?>
			</p>

			<ol style="list-style:none;margin:24px 0 0;padding:0;max-width:640px;">
				<?php foreach ( $steps as $i => $step ) : ?>
					<li style="display:flex;align-items:flex-start;gap:14px;padding:16px 0;border-top:1px solid #dcdcde;">
						<span style="flex:0 0 28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px;<?php echo $step['done'] ? 'background:#00a32a;color:#fff;' : 'background:#f0f0f1;color:#50575e;border:1px solid #c3c4c7;'; ?>">
							<?php echo $step['done'] ? '&#10003;' : (int) ( $i + 1 ); ?>
						</span>
						<div style="flex:1;">
							<p style="margin:0 0 2px;font-weight:600;"><?php echo esc_html( $step['title'] ); ?></p>
							<p style="margin:0;color:#646970;font-size:13px;"><?php echo esc_html( $step['desc'] ); ?></p>
						</div>
						<div style="flex:0 0 auto;">
							<a href="<?php echo esc_url( $step['url'] ); ?>" class="button<?php echo ( ! $step['done'] ) ? ' button-primary' : ''; ?>">
								<?php echo esc_html( $step['done'] ? 'Review' : $step['cta'] ); ?>
							</a>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}
}
