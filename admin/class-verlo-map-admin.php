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
			'Topical Map',
			'Topical Map',
			'manage_options',
			'verlo-map',
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$map      = Verlo_Topical_Map::get();
		$stats    = Verlo_Topical_Map::stats();
		$notice   = isset( $_GET['verlo_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['verlo_notice'] ) ) : '';
		$is_error = isset( $_GET['verlo_error'] );
		$url      = admin_url( 'admin-post.php' );
		$draft    = ( 'draft' === $map['status'] );
		$approved = ( 'approved' === $map['status'] );
		?>
		<div class="wrap verlo-wrap">
			<h1>Topical Map</h1>
			<p style="margin-top:2px;color:#646970;">Pillars become categories; the articles beneath them are the committed content roadmap. Nothing generates until this map is approved.</p>

			<?php if ( $notice ) : ?>
				<div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-warning inline" style="margin:14px 0;border-left-color:#dba617;"><p style="margin:.5em 0;">
				<strong>Review coverage before approving.</strong> "Covered" badges are a lexical signal and can miss synonym or closely-related cases
				(for example a keyword matching a post that uses different wording, or one topic matching a related but distinct post). Click each
				<em>existing post&nbsp;↗</em> link to confirm it genuinely covers the keyword, and re-mark anything that doesn't fit before you approve.
				Semantic (NLP) coverage is planned post-MVP.
			</p></div>

			<div class="verlo-card" style="margin-top:14px;">
				<h2>
					Status:
					<?php if ( $approved ) : ?>
						<span class="verlo-badge ok">Approved</span>
					<?php elseif ( $draft ) : ?>
						<span class="verlo-badge warn">Draft — review &amp; approve</span>
					<?php else : ?>
						<span class="verlo-badge off">Not generated</span>
					<?php endif; ?>
				</h2>
				<p class="verlo-sub">
					<?php echo (int) $stats['pillars']; ?> pillars ·
					<?php echo (int) $stats['planned']; ?> planned articles ·
					<?php echo (int) $stats['covered']; ?> already covered by existing content
					<?php if ( $map['generated_at'] ) { echo ' · generated ' . esc_html( human_time_diff( (int) $map['generated_at'], time() ) ) . ' ago'; } ?>
				</p>
				<?php if ( $draft && ! empty( $stats['thin'] ) ) : ?>
					<div class="notice notice-warning inline" style="margin:4px 0 12px;"><p>
						<strong>Can't approve yet</strong> — these pillars are below the <?php echo (int) Verlo_Topical_Map::MIN_CLUSTER; ?>-article minimum:
						<?php echo esc_html( implode( ', ', $stats['thin'] ) ); ?>. Add articles or remove the pillar(s).
					</p></div>
				<?php endif; ?>
				<div class="verlo-actions">
					<?php
					$gen_count = class_exists( 'Verlo_Article_Log' ) ? (int) Verlo_Article_Log::count() : 0;
					$confirm_msg = 'The map is approved. Regenerating will replace it with a new draft.';
					if ( $gen_count > 0 ) {
						$confirm_msg .= ' Your ' . $gen_count . ' already-generated article' . ( 1 === $gen_count ? '' : 's' )
							. ' will be preserved (the articles stay in WordPress and in the Generated articles list). Continue?';
					} else {
						$confirm_msg .= ' Continue?';
					}
					$onsubmit = $approved ? 'onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm_msg ) ) . ');"' : '';
					?>
					<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" <?php echo $onsubmit; ?>>
						<input type="hidden" name="action" value="verlo_map_generate" />
						<?php wp_nonce_field( 'verlo_map_generate' ); ?>
						<?php if ( $approved ) : ?><input type="hidden" name="force" value="1" /><?php endif; ?>
						<button type="submit" class="button <?php echo $map['pillars'] ? '' : 'button-primary'; ?>" data-verlo-progress="Designing your topical map with AI…" data-verlo-phases="map">Generate map with AI<?php echo $map['pillars'] ? ' (replace draft)' : ''; ?></button>
					</form>
					<?php if ( $draft ) : ?>
						<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline">
							<input type="hidden" name="action" value="verlo_map_recheck" />
							<?php wp_nonce_field( 'verlo_map_edit' ); ?>
							<button type="submit" class="button">Re-check coverage</button>
						</form>
						<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline">
							<input type="hidden" name="action" value="verlo_map_approve" />
							<?php wp_nonce_field( 'verlo_map_approve' ); ?>
							<button type="submit" class="button button-primary">Approve map</button>
						</form>
						<span class="description">Approval creates any missing categories (additive only) and unlocks content generation.</span>
					<?php elseif ( $approved ) : ?>
						<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline">
							<input type="hidden" name="action" value="verlo_map_reopen" />
							<?php wp_nonce_field( 'verlo_map_reopen' ); ?>
							<button type="submit" class="button">Reopen as draft</button>
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
								<span class="verlo-badge ok">Category exists</span>
							<?php else : ?>
								<span class="verlo-badge off">New category on approval</span>
							<?php endif; ?>
							<?php if ( $thin ) : ?>
								<span class="verlo-badge warn">Too thin — needs <?php echo (int) Verlo_Topical_Map::MIN_CLUSTER; ?>+ articles</span>
							<?php endif; ?>
						</h2>
						<?php if ( $thin ) : ?>
							<div class="notice notice-warning inline" style="margin:8px 0;"><p>
								This pillar has <?php echo count( $p['articles'] ); ?> planned article(s); the minimum is <?php echo (int) Verlo_Topical_Map::MIN_CLUSTER; ?>.
								Add <?php echo (int) Verlo_Topical_Map::MIN_CLUSTER - count( $p['articles'] ); ?> more, or remove the pillar — the map can't be approved while it's below the minimum (no category should exist without a real content plan behind it).
							</p></div>
						<?php endif; ?>
						<p class="verlo-sub"><?php echo esc_html( $p['description'] ); ?></p>

						<table class="widefat striped">
							<thead><tr><th style="width:48%">Planned article (keyword)</th><th>Intent</th><th>Status</th><th style="width:90px"></th></tr></thead>
							<tbody>
							<?php foreach ( $p['articles'] as $a ) : ?>
								<tr>
									<td><?php echo esc_html( $a['keyword'] ); ?></td>
									<td><code><?php echo esc_html( $a['intent'] ); ?></code></td>
									<td>
										<?php if ( 'covered' === $a['status'] && ! empty( $a['covered_by'] ) ) : ?>
											<span class="verlo-badge warn" title="<?php echo esc_attr( $a['cover_match'] ?? '' ); ?>">Covered</span>
											<a href="<?php echo esc_url( $a['covered_by'] ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( ( $a['cover_title'] ?? '' ) . ( isset( $a['cover_match'] ) ? ' — ' . $a['cover_match'] : '' ) ); ?>">existing post ↗</a>
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
												<button type="submit" class="button-link" style="color:#b32d2e;">Remove</button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php if ( empty( $p['articles'] ) ) : ?>
								<tr><td colspan="4">No planned articles yet — add at least <?php echo (int) Verlo_Topical_Map::MIN_CLUSTER; ?> or remove this pillar.</td></tr>
							<?php endif; ?>
							</tbody>
						</table>

						<?php if ( $draft ) : ?>
							<div class="verlo-actions" style="margin-top:10px;">
								<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
									<input type="hidden" name="action" value="verlo_map_add_article" />
									<input type="hidden" name="pillar_id" value="<?php echo (int) $p['id']; ?>" />
									<?php wp_nonce_field( 'verlo_map_edit' ); ?>
									<input type="text" name="keyword" placeholder="add a planned keyword" style="min-width:280px;" />
									<select name="intent">
										<option value="informational">informational</option>
										<option value="commercial">commercial</option>
										<option value="transactional">transactional</option>
										<option value="navigational">navigational</option>
									</select>
									<button type="submit" class="button">Add article</button>
								</form>
								<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" onsubmit="return confirm('Remove this pillar and its planned articles from the map?');">
									<input type="hidden" name="action" value="verlo_map_del_pillar" />
									<input type="hidden" name="pillar_id" value="<?php echo (int) $p['id']; ?>" />
									<?php wp_nonce_field( 'verlo_map_edit' ); ?>
									<button type="submit" class="button-link" style="color:#b32d2e;">Remove pillar</button>
								</form>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php if ( $draft ) : ?>
					<div class="verlo-card verlo-card-full">
						<h2>Add a pillar</h2>
						<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
							<input type="hidden" name="action" value="verlo_map_add_pillar" />
							<?php wp_nonce_field( 'verlo_map_edit' ); ?>
							<input type="text" name="name" placeholder="pillar / category name" style="min-width:240px;" />
							<input type="text" name="description" placeholder="what it covers" style="min-width:320px;" />
							<button type="submit" class="button">Add pillar</button>
							<span class="description">Remember: it needs at least <?php echo (int) Verlo_Topical_Map::MIN_CLUSTER; ?> planned articles before the map can be approved.</span>
						</form>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $map['audit'] ) ) : ?>
					<div class="verlo-card verlo-card-full">
						<h2>Existing category audit</h2>
						<p class="verlo-sub">Advisory only — nothing here is changed automatically. Merging or retiring categories moves URLs and is a manual decision (with redirects).</p>
						<table class="widefat striped">
							<thead><tr><th>Category</th><th>Posts</th><th>Verdict</th><th>Note</th></tr></thead>
							<tbody>
							<?php foreach ( $map['audit'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td><?php echo (int) $row['count']; ?></td>
									<td>
										<?php if ( 'keep' === $row['verdict'] ) : ?>
											<span class="verlo-badge ok">Keep</span>
										<?php else : ?>
											<span class="verlo-badge warn">Review</span>
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

	public static function handle_generate() {
		self::guard( 'verlo_map_generate' );
		$force = ! empty( $_POST['force'] );
		if ( $force ) { Verlo_Topical_Map::reopen(); }
		$res = Verlo_Topical_Map::generate();
		if ( is_wp_error( $res ) ) {
			self::redirect( 'Map generation failed: ' . $res->get_error_message(), true );
		}
		self::redirect( 'Draft map generated — review the pillars below, edit as needed, then Approve.' );
	}

	public static function handle_approve() {
		self::guard( 'verlo_map_approve' );
		$res = Verlo_Topical_Map::approve();
		if ( is_wp_error( $res ) ) {
			self::redirect( 'Cannot approve: ' . $res->get_error_message(), true );
		}
		$msg = 'Map approved.';
		if ( ! empty( $res ) ) {
			$msg .= ' Created categories: ' . implode( ', ', array_map( 'sanitize_text_field', $res ) ) . '.';
		}
		self::redirect( $msg );
	}

	public static function handle_reopen() {
		self::guard( 'verlo_map_reopen' );
		Verlo_Topical_Map::reopen();
		self::redirect( 'Map reopened as draft. Already-created categories were left in place.' );
	}

	public static function handle_del_pillar() {
		self::guard( 'verlo_map_edit' );
		Verlo_Topical_Map::delete_pillar( (int) ( $_POST['pillar_id'] ?? 0 ) );
		self::redirect( 'Pillar removed.' );
	}

	public static function handle_del_article() {
		self::guard( 'verlo_map_edit' );
		Verlo_Topical_Map::delete_article( (int) ( $_POST['article_id'] ?? 0 ) );
		self::redirect( 'Planned article removed.' );
	}

	public static function handle_add_article() {
		self::guard( 'verlo_map_edit' );
		$kw = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		if ( '' === $kw ) { self::redirect( 'Enter a keyword to add.', true ); }
		Verlo_Topical_Map::add_article( (int) ( $_POST['pillar_id'] ?? 0 ), $kw, sanitize_key( $_POST['intent'] ?? 'informational' ) );
		self::redirect( 'Planned article added.' );
	}

	public static function handle_add_pillar() {
		self::guard( 'verlo_map_edit' );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		if ( '' === $name ) { self::redirect( 'Enter a pillar name.', true ); }
		Verlo_Topical_Map::add_pillar( $name, sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ) );
		self::redirect( 'Pillar added — now add its planned articles.' );
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
		self::redirect( 'Coverage and category audit re-checked.' );
	}

	protected static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce ) ) {
			wp_die( 'Permission denied.' );
		}
	}

	protected static function redirect( $notice, $is_error = false ) {
		$args = array( 'page' => 'verlo-map', 'verlo_notice' => rawurlencode( $notice ) );
		if ( $is_error ) { $args['verlo_error'] = 1; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
