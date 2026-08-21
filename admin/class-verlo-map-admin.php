<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin page for the Topical Map: generate -> review/edit -> approve.
 */
class Verlo_Map_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
		add_action( 'admin_post_verlo_map_generate', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_verlo_map_approve', array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_verlo_map_reopen', array( __CLASS__, 'handle_reopen' ) );
		add_action( 'admin_post_verlo_map_del_pillar', array( __CLASS__, 'handle_del_pillar' ) );
		add_action( 'admin_post_verlo_map_del_article', array( __CLASS__, 'handle_del_article' ) );
		add_action( 'admin_post_verlo_map_add_article', array( __CLASS__, 'handle_add_article' ) );
		add_action( 'admin_post_verlo_map_add_pillar', array( __CLASS__, 'handle_add_pillar' ) );
		add_action( 'admin_post_verlo_map_recheck', array( __CLASS__, 'handle_recheck' ) );
	}

	public static function menu() {
		add_submenu_page(
			'verlo',
			__( 'Topical Map', 'verlo' ),
			__( 'Topical Map', 'verlo' ),
			'manage_options',
			'verlo-map',
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$connected = Verlo_Auth::is_connected();
		$map      = Verlo_Topical_Map::get();
		$stats    = Verlo_Topical_Map::stats();
		$notice       = isset( $_GET['verlo_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['verlo_notice'] ) ) : '';
		$is_error     = isset( $_GET['verlo_error'] );
		$link_billing = isset( $_GET['verlo_link_billing'] );
		$url      = admin_url( 'admin-post.php' );
		$draft    = ( 'draft' === $map['status'] );
		$approved = ( 'approved' === $map['status'] );
		?>
		<div class="wrap verlo-wrap">
			<h1><?php esc_html_e( 'Topical Map', 'verlo' ); ?>
				<a href="<?php echo esc_url( VERLO_DOCS_URL ); ?>" target="_blank" rel="noopener noreferrer" class="page-title-action"><?php esc_html_e( 'Help & Docs', 'verlo' ); ?></a>
			</h1>
			<p style="margin-top:2px;color:#646970;"><?php esc_html_e( 'Pillars become categories; the articles beneath them are the committed content roadmap. Nothing generates until this map is approved.', 'verlo' ); ?></p>

			<?php if ( '__working__' === $notice ) : ?>
				<?php Verlo_Async_Job::render_poll_bootstrap( 'topical-map', 'map', admin_url( 'admin.php?page=verlo-map' ) ); ?>
			<?php elseif ( $notice ) : ?>
				<div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p>
					<?php echo esc_html( $notice ); ?>
					<?php if ( $link_billing ) : ?>
						&nbsp;<a href="<?php echo esc_url( Verlo_SaaS_Client::dashboard_url() . '/dashboard/billing' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open billing →', 'verlo' ); ?></a>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>
			<?php Verlo_Guided_Tour::maybe_render_banner( 'verlo-map' ); ?>

			<div class="notice notice-warning inline" style="margin:14px 0;border-left-color:#dba617;"><p style="margin:.5em 0;">
				<?php
				printf(
					/* translators: %s: "existing post ↗" link text, kept as a placeholder so translators don't need to duplicate the arrow character */
					wp_kses_post( __( '<strong>Review coverage before approving.</strong> "Covered" badges are a lexical signal and can miss synonym or closely-related cases (for example a keyword matching a post that uses different wording, or one topic matching a related but distinct post). Click each %s link to confirm it genuinely covers the keyword, and re-mark anything that doesn\'t fit before you approve. Semantic (NLP) coverage is planned post-MVP.', 'verlo' ) ),
					'<em>' . esc_html__( 'existing post', 'verlo' ) . '&nbsp;↗</em>'
				);
				?>
			</p></div>

			<div class="verlo-card" style="margin-top:14px;">
				<h2>
					<?php esc_html_e( 'Status:', 'verlo' ); ?>
					<?php if ( $approved ) : ?>
						<span class="verlo-badge ok"><?php esc_html_e( 'Approved', 'verlo' ); ?></span>
					<?php elseif ( $draft ) : ?>
						<span class="verlo-badge warn"><?php esc_html_e( 'Draft: review & approve', 'verlo' ); ?></span>
					<?php else : ?>
						<span class="verlo-badge off"><?php esc_html_e( 'Not generated', 'verlo' ); ?></span>
					<?php endif; ?>
				</h2>
				<p class="verlo-sub">
					<?php
					printf(
						/* translators: 1: pillar count, 2: planned article count, 3: already-covered count */
						esc_html__( '%1$d pillars · %2$d planned articles · %3$d already covered by existing content', 'verlo' ),
						(int) $stats['pillars'],
						(int) $stats['planned'],
						(int) $stats['covered']
					);
					if ( $map['generated_at'] ) {
						echo ' · ';
						printf(
							/* translators: %s: human-readable time since the map was generated */
							esc_html__( 'generated %s ago', 'verlo' ),
							esc_html( human_time_diff( (int) $map['generated_at'], time() ) )
						);
					}
					?>
				</p>
				<?php if ( $draft && ! empty( $stats['thin'] ) ) : ?>
					<div class="notice notice-warning inline" style="margin:4px 0 12px;"><p>
						<?php
						printf(
							/* translators: 1: minimum articles required, 2: comma-separated list of thin pillar names */
							wp_kses_post( __( '<strong>Can\'t approve yet.</strong> These pillars are below the %1$d-article minimum: %2$s. Add articles or remove the pillar(s).', 'verlo' ) ),
							(int) Verlo_Topical_Map::MIN_CLUSTER,
							esc_html( implode( ', ', $stats['thin'] ) )
						);
						?>
					</p></div>
				<?php endif; ?>
				<div class="verlo-actions">
					<?php
					$gen_count = class_exists( 'Verlo_Article_Log' ) ? (int) Verlo_Article_Log::count() : 0;
					$confirm_msg = __( 'The map is approved. Regenerating will replace it with a new draft.', 'verlo' );
					if ( $gen_count > 0 ) {
						$confirm_msg .= ' ' . sprintf(
							/* translators: %d: number of already-generated articles that will be preserved */
							_n(
								'Your %d already-generated article will be preserved (the articles stay in WordPress and in the Generated articles list). Continue?',
								'Your %d already-generated articles will be preserved (the articles stay in WordPress and in the Generated articles list). Continue?',
								$gen_count,
								'verlo'
							),
							$gen_count
						);
					} else {
						$confirm_msg .= ' ' . __( 'Continue?', 'verlo' );
					}
					$onsubmit = $approved ? 'onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm_msg ) ) . ');"' : '';
					?>
					<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" <?php echo $onsubmit; ?>>
						<input type="hidden" name="action" value="verlo_map_generate" />
						<?php wp_nonce_field( 'verlo_map_generate' ); ?>
						<?php if ( $approved ) : ?><input type="hidden" name="force" value="1" /><?php endif; ?>
						<button type="submit" class="button <?php echo $map['pillars'] ? '' : 'button-primary'; ?>" data-verlo-tour-target="map-generate"<?php echo Verlo_Guided_Tour::target_id_attr( 'map-generate' ); ?> data-verlo-progress="<?php esc_attr_e( 'Designing your topical map with Verlo…', 'verlo' ); ?>" data-verlo-phases="map" <?php disabled( ! $connected ); ?>><?php echo $map['pillars'] ? esc_html__( 'Generate map with Verlo (replace draft)', 'verlo' ) : esc_html__( 'Generate map with Verlo', 'verlo' ); ?></button>
					</form>
					<?php Verlo_Guided_Tour::render_target_callout( 'map-generate' ); ?>
					<?php if ( ! $connected ) : ?>
						<span class="description"><?php esc_html_e( 'Connect your Verlo license first.', 'verlo' ); ?></span>
					<?php endif; ?>
					<?php if ( $draft ) : ?>
						<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline">
							<input type="hidden" name="action" value="verlo_map_recheck" />
							<?php wp_nonce_field( 'verlo_map_edit' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Re-check coverage', 'verlo' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline">
							<input type="hidden" name="action" value="verlo_map_approve" />
							<?php wp_nonce_field( 'verlo_map_approve' ); ?>
							<button type="submit" class="button button-primary" data-verlo-tour-target="map-approve"<?php echo Verlo_Guided_Tour::target_id_attr( 'map-approve' ); ?>><?php esc_html_e( 'Approve map', 'verlo' ); ?></button>
						</form>
						<?php Verlo_Guided_Tour::render_target_callout( 'map-approve' ); ?>
						<span class="description"><?php esc_html_e( 'Approval creates any missing categories (additive only) and unlocks content generation.', 'verlo' ); ?></span>
					<?php elseif ( $approved ) : ?>
						<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline">
							<input type="hidden" name="action" value="verlo_map_reopen" />
							<?php wp_nonce_field( 'verlo_map_reopen' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Reopen as draft', 'verlo' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $map['pillars'] ) ) : ?>
				<?php foreach ( $map['pillars'] as $p ) : ?>
					<div class="verlo-card verlo-card-full">
						<?php $thin = $draft && count( $p['articles'] ) < (int) Verlo_Topical_Map::MIN_CLUSTER; ?>
						<h2>
							<?php echo esc_html( $p['name'] ); ?>
							<?php if ( ! empty( $p['category_id'] ) ) : ?>
								<span class="verlo-badge ok"><?php esc_html_e( 'Category exists', 'verlo' ); ?></span>
							<?php else : ?>
								<span class="verlo-badge off"><?php esc_html_e( 'New category on approval', 'verlo' ); ?></span>
							<?php endif; ?>
							<?php if ( $thin ) : ?>
								<span class="verlo-badge warn">
									<?php
									printf(
										/* translators: %d: minimum article count required */
										esc_html__( 'Too thin: needs %d+ articles', 'verlo' ),
										(int) Verlo_Topical_Map::MIN_CLUSTER
									);
									?>
								</span>
							<?php endif; ?>
						</h2>
						<?php if ( $thin ) : ?>
							<div class="notice notice-warning inline" style="margin:8px 0;"><p>
								<?php
								printf(
									/* translators: 1: current planned article count, 2: minimum required, 3: how many more are needed */
									esc_html__( "This pillar has %1\$d planned article(s); the minimum is %2\$d. Add %3\$d more, or remove the pillar. The map can't be approved while it's below the minimum (no category should exist without a real content plan behind it).", 'verlo' ),
									count( $p['articles'] ),
									(int) Verlo_Topical_Map::MIN_CLUSTER,
									(int) Verlo_Topical_Map::MIN_CLUSTER - count( $p['articles'] )
								);
								?>
							</p></div>
						<?php endif; ?>
						<p class="verlo-sub"><?php echo esc_html( $p['description'] ); ?></p>

						<table class="widefat striped">
							<thead><tr>
								<th style="width:48%"><?php esc_html_e( 'Planned article', 'verlo' ); ?></th>
								<th><?php esc_html_e( 'Intent', 'verlo' ); ?></th>
								<th><?php esc_html_e( 'Status', 'verlo' ); ?></th>
								<th style="width:90px"></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $p['articles'] as $a ) : ?>
								<tr>
									<td><?php echo esc_html( $a['keyword'] ); ?></td>
									<td><code><?php echo esc_html( $a['intent'] ); ?></code></td>
									<td>
										<?php if ( 'covered' === $a['status'] && ! empty( $a['covered_by'] ) ) : ?>
											<span class="verlo-badge warn" title="<?php echo esc_attr( $a['cover_match'] ?? '' ); ?>"><?php esc_html_e( 'Covered', 'verlo' ); ?></span>
											<a href="<?php echo esc_url( $a['covered_by'] ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( ( $a['cover_title'] ?? '' ) . ( isset( $a['cover_match'] ) ? ' — ' . $a['cover_match'] : '' ) ); ?>"><?php esc_html_e( 'existing post ↗', 'verlo' ); ?></a>
										<?php else : ?>
											<span class="verlo-badge ok"><?php echo esc_html( ucfirst( $a['status'] ) ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $draft ) : ?>
											<form method="post" action="<?php echo esc_url( $url ); ?>">
												<input type="hidden" name="action" value="verlo_map_del_article" />
												<input type="hidden" name="article_id" value="<?php echo (int) $a['id']; ?>" />
												<?php wp_nonce_field( 'verlo_map_edit' ); ?>
												<button type="submit" class="button-link" style="color:#b32d2e;"><?php esc_html_e( 'Remove', 'verlo' ); ?></button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php if ( empty( $p['articles'] ) ) : ?>
								<tr><td colspan="4">
									<?php
									printf(
										/* translators: %d: minimum number of planned articles required */
										esc_html__( 'No planned articles yet. Add at least %d or remove this pillar.', 'verlo' ),
										(int) Verlo_Topical_Map::MIN_CLUSTER
									);
									?>
								</td></tr>
							<?php endif; ?>
							</tbody>
						</table>

						<?php if ( $draft ) : ?>
							<div class="verlo-actions" style="margin-top:10px;">
								<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
									<input type="hidden" name="action" value="verlo_map_add_article" />
									<input type="hidden" name="pillar_id" value="<?php echo (int) $p['id']; ?>" />
									<?php wp_nonce_field( 'verlo_map_edit' ); ?>
									<input type="text" name="keyword" placeholder="<?php esc_attr_e( 'add a planned keyword', 'verlo' ); ?>" style="min-width:280px;" />
									<select name="intent">
										<option value="informational"><?php esc_html_e( 'informational', 'verlo' ); ?></option>
										<option value="commercial"><?php esc_html_e( 'commercial', 'verlo' ); ?></option>
										<option value="transactional"><?php esc_html_e( 'transactional', 'verlo' ); ?></option>
										<option value="navigational"><?php esc_html_e( 'navigational', 'verlo' ); ?></option>
									</select>
									<button type="submit" class="button"><?php esc_html_e( 'Add article', 'verlo' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this pillar and its planned articles from the map?', 'verlo' ) ); ?>');">
									<input type="hidden" name="action" value="verlo_map_del_pillar" />
									<input type="hidden" name="pillar_id" value="<?php echo (int) $p['id']; ?>" />
									<?php wp_nonce_field( 'verlo_map_edit' ); ?>
									<button type="submit" class="button-link" style="color:#b32d2e;"><?php esc_html_e( 'Remove pillar', 'verlo' ); ?></button>
								</form>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php if ( $draft ) : ?>
					<div class="verlo-card verlo-card-full">
						<h2><?php esc_html_e( 'Add a pillar', 'verlo' ); ?></h2>
						<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
							<input type="hidden" name="action" value="verlo_map_add_pillar" />
							<?php wp_nonce_field( 'verlo_map_edit' ); ?>
							<input type="text" name="name" placeholder="<?php esc_attr_e( 'pillar / category name', 'verlo' ); ?>" style="min-width:240px;" />
							<input type="text" name="description" placeholder="<?php esc_attr_e( 'what it covers', 'verlo' ); ?>" style="min-width:320px;" />
							<button type="submit" class="button"><?php esc_html_e( 'Add pillar', 'verlo' ); ?></button>
							<span class="description">
								<?php
								printf(
									/* translators: %d: minimum number of planned articles required */
									esc_html__( 'Remember: it needs at least %d planned articles before the map can be approved.', 'verlo' ),
									(int) Verlo_Topical_Map::MIN_CLUSTER
								);
								?>
							</span>
						</form>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $map['audit'] ) ) : ?>
					<div class="verlo-card verlo-card-full">
						<h2><?php esc_html_e( 'Existing category audit', 'verlo' ); ?></h2>
						<p class="verlo-sub"><?php esc_html_e( 'Advisory only. Nothing here is changed automatically. Merging or retiring categories moves URLs and is a manual decision (with redirects).', 'verlo' ); ?></p>
						<table class="widefat striped">
							<thead><tr><th><?php esc_html_e( 'Category', 'verlo' ); ?></th><th><?php esc_html_e( 'Posts', 'verlo' ); ?></th><th><?php esc_html_e( 'Verdict', 'verlo' ); ?></th><th><?php esc_html_e( 'Note', 'verlo' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $map['audit'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td><?php echo (int) $row['count']; ?></td>
									<td>
										<?php if ( 'keep' === $row['verdict'] ) : ?>
											<span class="verlo-badge ok"><?php esc_html_e( 'Keep', 'verlo' ); ?></span>
										<?php else : ?>
											<span class="verlo-badge warn"><?php esc_html_e( 'Review', 'verlo' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $row['note'] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ----- handlers ----- */

	/**
	 * Map generation calls the Verlo SaaS and can take up to ~90s
	 * (Verlo_SaaS_Client::run_job()'s timeout, the longest of the plugin's
	 * three site-level AI calls) - long enough to 503 on hosts with a
	 * shorter proxy/PHP execution limit. Queuing through Verlo_Async_Job
	 * returns control to the browser immediately; the actual force+generate
	 * sequence (unchanged) now runs in Verlo_Topical_Map::run_pending().
	 */
	public static function handle_generate() {
		self::guard( 'verlo_map_generate' );
		if ( ! Verlo_Auth::is_connected() ) {
			self::redirect( __( 'Connect Verlo first under Strategy Profile → Verlo connection.', 'verlo' ), true );
		}
		$force = ! empty( $_POST['force'] );
		Verlo_Async_Job::queue( 'topical-map', array( 'force' => $force ) );
		self::redirect( '__working__' );
	}

	public static function handle_approve() {
		self::guard( 'verlo_map_approve' );
		$res = Verlo_Topical_Map::approve();
		if ( is_wp_error( $res ) ) {
			self::redirect( sprintf( /* translators: %s: error message */ __( 'Cannot approve: %s', 'verlo' ), $res->get_error_message() ), true );
		}
		$msg = __( 'Map approved.', 'verlo' );
		if ( ! empty( $res ) ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: comma-separated list of created category names */
				__( 'Created categories: %s.', 'verlo' ),
				implode( ', ', array_map( 'sanitize_text_field', $res ) )
			);
		}
		self::redirect( $msg );
	}

	public static function handle_reopen() {
		self::guard( 'verlo_map_reopen' );
		Verlo_Topical_Map::reopen();
		self::redirect( __( 'Map reopened as draft. Already-created categories were left in place.', 'verlo' ) );
	}

	public static function handle_del_pillar() {
		self::guard( 'verlo_map_edit' );
		Verlo_Topical_Map::delete_pillar( (int) ( $_POST['pillar_id'] ?? 0 ) );
		self::redirect( __( 'Pillar removed.', 'verlo' ) );
	}

	public static function handle_del_article() {
		self::guard( 'verlo_map_edit' );
		Verlo_Topical_Map::delete_article( (int) ( $_POST['article_id'] ?? 0 ) );
		self::redirect( __( 'Planned article removed.', 'verlo' ) );
	}

	public static function handle_add_article() {
		self::guard( 'verlo_map_edit' );
		$kw = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		if ( '' === $kw ) { self::redirect( __( 'Enter a keyword to add.', 'verlo' ), true ); }
		Verlo_Topical_Map::add_article( (int) ( $_POST['pillar_id'] ?? 0 ), $kw, sanitize_key( $_POST['intent'] ?? 'informational' ) );
		self::redirect( __( 'Planned article added.', 'verlo' ) );
	}

	public static function handle_add_pillar() {
		self::guard( 'verlo_map_edit' );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		if ( '' === $name ) { self::redirect( __( 'Enter a pillar name.', 'verlo' ), true ); }
		Verlo_Topical_Map::add_pillar( $name, sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ) );
		self::redirect( __( 'Pillar added. Now add its planned articles.', 'verlo' ) );
	}

	public static function handle_recheck() {
		self::guard( 'verlo_map_edit' );
		$map = Verlo_Topical_Map::get();
		// Reset previous coverage verdicts, then re-mark with current logic.
		foreach ( $map['pillars'] as &$p ) {
			foreach ( $p['articles'] as &$a ) {
				if ( 'covered' === $a['status'] ) {
					$a['status'] = 'planned';
					unset( $a['covered_by'], $a['cover_title'], $a['cover_match'] );
				}
			}
		}
		unset( $p, $a );
		$map['pillars'] = Verlo_Topical_Map::mark_coverage( $map['pillars'] );
		$map['audit']   = Verlo_Topical_Map::audit_categories( $map['pillars'] );
		Verlo_Topical_Map::save( $map );
		self::redirect( __( 'Coverage and category audit re-checked.', 'verlo' ) );
	}

	protected static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce ) ) {
			wp_die( esc_html__( 'Permission denied.', 'verlo' ) );
		}
	}

	protected static function redirect( $notice, $is_error = false, $link_billing = false ) {
		$args = array( 'page' => 'verlo-map', 'verlo_notice' => rawurlencode( $notice ) );
		if ( $is_error ) { $args['verlo_error'] = 1; }
		if ( $link_billing ) { $args['verlo_link_billing'] = 1; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
