<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin page for Content Briefs: generate, review, and edit the spec for each
 * planned article before any generation happens.
 */
class Verlo_Brief_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 13 );
		add_action( 'admin_post_verlo_brief_generate', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_verlo_brief_generate_next', array( __CLASS__, 'handle_generate_next' ) );
		add_action( 'admin_post_verlo_brief_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_verlo_brief_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_verlo_brief_generate_article', array( __CLASS__, 'handle_generate_article' ) );
		add_action( 'admin_post_verlo_restore_version', array( __CLASS__, 'handle_restore_version' ) );

		// The background worker hooks (verlo_run_generation, verlo_cron_generate)
		// are registered in the bootstrap so they fire outside admin context.
		// Here we only need the authenticated status-polling endpoint.
		add_action( 'wp_ajax_verlo_gen_status', array( __CLASS__, 'ajax_gen_status' ) );
	}

	/**
	 * Poll endpoint for the async generation UI. Beyond reporting status, this
	 * SELF-HEALS: if the background worker and cron both failed to run (common on
	 * sites where a security plugin blocks loopback requests and/or WP-Cron is
	 * disabled), and the job has stalled, the polling request itself runs the
	 * generation synchronously. The open admin tab can always reach the server,
	 * so it becomes the reliable engine of last resort.
	 *
	 * Accepts an optional `force=1` to run immediately (used by the manual
	 * "Run now" recovery button).
	 */
	public static function ajax_gen_status() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array(), 403 ); }
		check_ajax_referer( 'verlo_gen_status', 'nonce' );

		$aid    = (int) ( $_GET['article_id'] ?? 0 );
		$force  = ! empty( $_GET['force'] );
		$status = Verlo_Brief::get_gen_status( $aid );

		$brief   = Verlo_Brief::get( $aid );
		$post_id = $brief && ! empty( $brief['draft']['post_id'] ) ? (int) $brief['draft']['post_id'] : 0;

		// Decide whether this poll should take over and run the job. We do this
		// when forced, when the job has stalled past the env-aware delay (on
		// sites where background dispatch is known-blocked, this is just a few
		// seconds, so the open tab finishes the work automatically and fast —
		// without the user needing to press "Run now"), OR when a SaaS job is
		// already submitted and in flight for this article — generation is
		// submit-then-poll now (see Verlo_Generator::do_generate_draft()), so
		// once a job id exists every check here is a single fast status GET,
		// never a blocking wait, and it's this condition — not $stalled, which
		// exists for the DIFFERENT case of nothing having picked the job up at
		// all — that actually drives queued -> submitted -> ... -> done forward
		// on every poll while the tab stays open.
		$age             = time() - (int) $status['updated_at'];
		$delay           = Verlo_Env::self_heal_delay();
		$stalled         = in_array( $status['state'], array( 'queued', 'running' ), true ) && $age >= $delay;
		$has_pending_job = ( 'running' === $status['state'] ) && '' !== Verlo_Brief::get_saas_job( $aid );
		$needs_run       = ! $post_id && 'done' !== $status['state'] && 'error' !== $status['state'];

		if ( $needs_run && ( $force || $stalled || $has_pending_job ) ) {
			// Run synchronously inside this AJAX request. WordPress admin-ajax is
			// not subject to the same short nginx proxy timeout as normal admin
			// page loads on most stacks, and we lift the PHP time limit; if it is
			// still cut off, the next poll simply retries, so we always converge.
			if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
			$result = Verlo_Generator::run_pending( $aid, 'browser' );

			$status  = Verlo_Brief::get_gen_status( $aid );
			$brief   = Verlo_Brief::get( $aid );
			$post_id = $brief && ! empty( $brief['draft']['post_id'] ) ? (int) $brief['draft']['post_id'] : 0;
		}

		$out = array(
			'state'   => $status['state'],
			'message' => $status['message'],
			'age'     => $age,
		);
		if ( $post_id && ( 'done' === $status['state'] || 'idle' === $status['state'] ) ) {
			$out['edit_url'] = get_edit_post_link( $post_id, 'raw' );
			$out['reload']   = true;
		}
		wp_send_json_success( $out );
	}

	public static function menu() {
		add_submenu_page( 'verlo', __( 'Content Briefs', 'verlo' ), __( 'Content Briefs', 'verlo' ), 'manage_options', 'verlo-briefs', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$notice       = isset( $_GET['verlo_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['verlo_notice'] ) ) : '';
		$is_error     = isset( $_GET['verlo_error'] );
		$link_billing = isset( $_GET['verlo_link_billing'] );
		?>
		<div class="wrap verlo-wrap">
			<h1><?php esc_html_e( 'Content Briefs', 'verlo' ); ?>
				<a href="<?php echo esc_url( VERLO_DOCS_URL ); ?>" target="_blank" rel="noopener noreferrer" class="page-title-action"><?php esc_html_e( 'Help & Docs', 'verlo' ); ?></a>
			</h1>
			<p style="margin-top:2px;color:#646970;"><?php esc_html_e( 'The spec for each planned article: title, angle, outline, internal links, and intent. Reviewed before anything is written.', 'verlo' ); ?></p>
			<?php if ( '__working__' === $notice ) : ?>
				<?php Verlo_Async_Job::render_poll_bootstrap( 'brief-next', 'brief', admin_url( 'admin.php?page=verlo-briefs' ) ); ?>
			<?php elseif ( $notice && '__generating__' !== $notice ) : ?>
				<div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p>
					<?php echo esc_html( $notice ); ?>
					<?php if ( $link_billing ) : ?>
						&nbsp;<a href="<?php echo esc_url( Verlo_SaaS_Client::dashboard_url() . '/dashboard/billing' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open billing →', 'verlo' ); ?></a>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>
			<?php Verlo_Guided_Tour::maybe_render_banner( 'verlo-briefs' ); ?>
			<?php
			if ( ! Verlo_Topical_Map::is_approved() ) {
				$map_url = admin_url( 'admin.php?page=verlo-map' );
				echo '<div class="verlo-card" style="margin-top:14px;"><h2>' . esc_html__( 'Topical Map not approved', 'verlo' ) . '</h2><p class="verlo-sub">'
					. sprintf(
						/* translators: %s: link to the Topical Map page */
						wp_kses_post( __( 'Briefs are generated from the approved map. %s', 'verlo' ) ),
						'<a href="' . esc_url( $map_url ) . '">' . esc_html__( 'Open the Topical Map →', 'verlo' ) . '</a>'
					)
					. '</p></div></div>';
				return;
			}

			$view_id = isset( $_GET['verlo_brief'] ) ? (int) $_GET['verlo_brief'] : 0;
			if ( $view_id && Verlo_Brief::exists( $view_id ) ) {
				self::render_brief_detail( $view_id );
			} else {
				self::render_list();
			}
			?>
		</div>
		<?php
	}

	protected static function render_list() {
		$url       = admin_url( 'admin-post.php' );
		$stats     = Verlo_Strategist::stats();
		$next      = Verlo_Strategist::pick_next();
		$connected = Verlo_Auth::is_connected();
		?>
		<div class="verlo-card" style="margin-top:14px;">
			<h2><?php esc_html_e( 'Overview', 'verlo' ); ?></h2>
			<p class="verlo-sub">
				<?php
				printf(
					/* translators: 1: planned article count, 2: briefed count, 3: awaiting-brief count */
					esc_html__( '%1$d planned articles · %2$d briefed · %3$d awaiting a brief', 'verlo' ),
					(int) $stats['planned'],
					(int) $stats['with_brief'],
					(int) $stats['without']
				);
				?>
			</p>
			<div class="verlo-actions">
				<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline">
					<input type="hidden" name="action" value="verlo_brief_generate_next" />
					<?php wp_nonce_field( 'verlo_brief_generate_next' ); ?>
					<button type="submit" class="button button-primary" data-verlo-tour-target="brief-generate"<?php echo Verlo_Guided_Tour::target_id_attr( 'brief-generate' ); ?> data-verlo-progress="<?php esc_attr_e( 'Writing brief with Verlo…', 'verlo' ); ?>" data-verlo-phases="brief" <?php disabled( ! $next || ! $connected ); ?>>
						<?php echo $next ? esc_html__( 'Generate next brief', 'verlo' ) : esc_html__( 'All planned articles briefed', 'verlo' ); ?>
					</button>
				</form>
				<?php if ( $next ) : ?><span class="description"><?php printf( /* translators: %s: next planned keyword */ esc_html__( 'Next: %s', 'verlo' ), '<strong>' . esc_html( $next['keyword'] ) . '</strong>' ); ?></span><?php endif; ?>
				<?php if ( ! $connected ) : ?><span class="description"><?php esc_html_e( 'Connect your Verlo license first.', 'verlo' ); ?></span><?php endif; ?>
				<?php Verlo_Guided_Tour::render_target_callout( 'brief-generate' ); ?>
			</div>
		</div>

		<?php
		$mismatches = Verlo_Strategist::audit_links();
		if ( ! empty( $mismatches ) ) : ?>
			<div class="verlo-card verlo-card-full" style="border-left:4px solid #d63638;">
				<h2 style="color:#d63638;">⚠
					<?php
					printf(
						/* translators: %d: number of mismatched briefs */
						esc_html( _n( '%d brief linked to the wrong post', '%d briefs linked to the wrong post', count( $mismatches ), 'verlo' ) ),
						count( $mismatches )
					);
					?>
				</h2>
				<p class="verlo-sub"><?php esc_html_e( 'These rows show a keyword from the current map, but the linked post was actually generated for a different keyword, almost always leftover from a map regeneration before article IDs were made stable. Nothing was changed automatically; review each and use "Regenerate article" on the affected brief once you\'ve decided what to do with the orphaned post below.', 'verlo' ); ?></p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Row currently shows', 'verlo' ); ?></th><th><?php esc_html_e( 'Linked post was actually generated for', 'verlo' ); ?></th><th><?php esc_html_e( 'Linked post', 'verlo' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $mismatches as $m ) : ?>
						<tr>
							<td><?php echo esc_html( $m['shown_keyword'] ); ?></td>
							<td><?php echo esc_html( $m['generated_for_keyword'] ); ?></td>
							<td><?php echo esc_html( $m['post_title'] ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( $m['edit_url'] ); ?>"><?php esc_html_e( 'Edit that post', 'verlo' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=verlo-briefs&verlo_brief=' . $m['article_id'] ) ); ?>"><?php esc_html_e( 'Open the brief', 'verlo' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
		// Group planned articles by pillar.
		$by_pillar = array();
		foreach ( Verlo_Strategist::planned_articles() as $a ) {
			$by_pillar[ $a['pillar'] ][] = $a;
		}
		foreach ( $by_pillar as $pillar => $articles ) : ?>
			<div class="verlo-card verlo-card-full">
				<h2><?php echo esc_html( $pillar ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th style="width:46%"><?php esc_html_e( 'Planned article', 'verlo' ); ?></th><th><?php esc_html_e( 'Intent', 'verlo' ); ?></th><th><?php esc_html_e( 'Status', 'verlo' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $articles as $a ) : ?>
						<tr>
							<td><?php echo esc_html( $a['keyword'] ); ?></td>
							<td><code><?php echo esc_html( $a['intent'] ); ?></code></td>
							<td><?php $st = Verlo_Strategist::pipeline_status( $a['id'] ); ?><span class="verlo-badge <?php echo esc_attr( $st['badge'] ); ?>"><?php echo esc_html( $st['label'] ); ?></span></td>
							<td>
								<?php if ( $a['has_brief'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=verlo-briefs&verlo_brief=' . $a['id'] ) ); ?>"><?php esc_html_e( 'Open', 'verlo' ); ?></a>
									<?php if ( ! empty( $st['post_id'] ) ) : ?>
										<a class="button button-small button-primary" href="<?php echo esc_url( get_edit_post_link( $st['post_id'] ) ); ?>"><?php echo 'published' === $st['state'] ? esc_html__( 'Edit post →', 'verlo' ) : esc_html__( 'Edit draft →', 'verlo' ); ?></a>
									<?php endif; ?>
								<?php else : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
										<input type="hidden" name="action" value="verlo_brief_generate" />
										<input type="hidden" name="article_id" value="<?php echo (int) $a['id']; ?>" />
										<?php wp_nonce_field( 'verlo_brief_generate' ); ?>
										<button type="submit" class="button button-small button-primary" data-verlo-progress="<?php esc_attr_e( 'Writing brief with Verlo…', 'verlo' ); ?>" data-verlo-phases="brief" <?php disabled( ! $connected ); ?>><?php esc_html_e( 'Generate brief', 'verlo' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach;

		self::render_article_history();
	}

	/**
	 * Read-only history of every article Verlo has generated on this site.
	 * Persists across topical-map rebuilds and the event-log rolling over, so
	 * past work is never lost from view. Status is computed live from the real
	 * post, so it is always accurate (Draft / Published / Trashed / Deleted).
	 */
	protected static function render_article_history() {
		if ( ! class_exists( 'Verlo_Article_Log' ) ) { return; }
		$rows = Verlo_Article_Log::recent( 200 );
		if ( empty( $rows ) ) { return; }

		// Which past version's diff (against the CURRENT live content) to
		// show inline, if any — a plain GET param, safe/idempotent like the
		// existing verlo_brief view-id param elsewhere on this page.
		$diff_post    = isset( $_GET['verlo_diff_post'] ) ? (int) $_GET['verlo_diff_post'] : 0;
		$diff_version = isset( $_GET['verlo_diff_version'] ) ? (int) $_GET['verlo_diff_version'] : 0;

		$labels = array(
			'published' => array( __( 'Published', 'verlo' ), '#1a7f37', '#dafbe1' ),
			'draft'     => array( __( 'Draft', 'verlo' ), '#9a6700', '#fff8c5' ),
			'pending'   => array( __( 'Pending', 'verlo' ), '#9a6700', '#fff8c5' ),
			'future'    => array( __( 'Scheduled', 'verlo' ), '#0969da', '#ddf4ff' ),
			'private'   => array( __( 'Private', 'verlo' ), '#57606a', '#eaeef2' ),
			'trashed'   => array( __( 'Trashed', 'verlo' ), '#cf222e', '#ffebe9' ),
			'deleted'   => array( __( 'Deleted', 'verlo' ), '#82071e', '#ffd7d5' ),
			'other'     => array( __( 'Other', 'verlo' ), '#57606a', '#eaeef2' ),
		);
		?>
		<div class="verlo-card verlo-card-full" style="margin-top:18px;">
			<h2>
				<?php esc_html_e( 'Generated articles', 'verlo' ); ?>
				<span style="font-weight:400;color:#646970;font-size:13px;">·
					<?php
					printf(
						/* translators: %d: number of generated articles */
						esc_html( _n( '%d on record', '%d on record', count( $rows ), 'verlo' ) ),
						(int) count( $rows )
					);
					?>
				</span>
			</h2>
			<p class="verlo-sub" style="margin-top:-4px;"><?php esc_html_e( 'Every article Verlo has written on this site. This list is preserved even if you rebuild the topical map, so completed work is never lost. Status is read live from WordPress.', 'verlo' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th style="width:34%"><?php esc_html_e( 'Article', 'verlo' ); ?></th>
					<th><?php esc_html_e( 'Pillar', 'verlo' ); ?></th>
					<th><?php esc_html_e( 'Generated', 'verlo' ); ?></th>
					<th><?php esc_html_e( 'Time', 'verlo' ); ?></th>
					<th><?php esc_html_e( 'Status', 'verlo' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) :
					$status = $r['status'];
					$meta   = isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['other'];
					$secs   = isset( $r['gen_seconds'] ) ? (float) $r['gen_seconds'] : 0;
					if ( $secs > 0 ) {
						$m = floor( $secs / 60 ); $s = (int) round( $secs - $m * 60 );
						$time_h = $m > 0 ? ( $m . 'm ' . $s . 's' ) : ( $s . 's' );
					} else { $time_h = '—'; }
					$title  = '' !== $r['title'] ? $r['title'] : $r['keyword'];
					$vcount = isset( $r['version_count'] ) ? (int) $r['version_count'] : 1;
					$prior  = $vcount > 1 ? array_slice( $r['versions'], 1 ) : array();
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $title ); ?></strong>
							<?php if ( '' !== $r['keyword'] && $r['keyword'] !== $title ) : ?>
								<br><span style="color:#646970;font-size:12px;"><?php echo esc_html( $r['keyword'] ); ?></span>
							<?php endif; ?>
							<?php if ( $vcount > 1 ) : ?>
								<br><details style="margin-top:4px;"<?php echo ( $diff_post === (int) $r['post_id'] ) ? ' open' : ''; ?>>
									<summary style="cursor:pointer;color:#646970;font-size:12px;">
										<?php
										printf(
											/* translators: %d: number of times regenerated */
											esc_html( _n( 'Regenerated %d× — view history', 'Regenerated %d× — view history', $vcount - 1, 'verlo' ) ),
											(int) ( $vcount - 1 )
										);
										?>
									</summary>
									<ul style="margin:6px 0 0 0;padding-left:16px;font-size:12px;color:#646970;">
										<?php foreach ( $prior as $v ) :
											$vtitle       = '' !== $v['title'] ? $v['title'] : $v['keyword'];
											$has_content  = null !== Verlo_Article_Log::get_version_content( $r['post_id'], $v['version'] );
											$is_shown     = $diff_post === (int) $r['post_id'] && $diff_version === (int) $v['version'];
											$diff_url     = add_query_arg(
												array(
													'page'                => 'verlo-briefs',
													'verlo_diff_post'     => (int) $r['post_id'],
													'verlo_diff_version'  => (int) $v['version'],
												),
												admin_url( 'admin.php' )
											) . '#verlo-diff-' . (int) $r['post_id'];
											?>
											<li style="margin-bottom:4px;">
												v<?php echo (int) $v['version']; ?> &middot;
												<?php echo esc_html( $vtitle ); ?> &middot;
												<span title="<?php echo esc_attr( wp_date( 'M j, Y H:i', (int) $v['generated_at'] ) ); ?>">
													<?php
													printf(
														/* translators: %s: human-readable elapsed time */
														esc_html__( '%s ago', 'verlo' ),
														esc_html( human_time_diff( (int) $v['generated_at'], time() ) )
													);
													?>
												</span>
												<?php if ( ! empty( $v['restored_from'] ) ) : ?>
													<span style="color:#0969da;">&middot;
														<?php
														printf(
															/* translators: %d: version number restored from */
															esc_html__( 'restored from v%d', 'verlo' ),
															(int) $v['restored_from']
														);
														?>
													</span>
												<?php endif; ?>
												<?php if ( $has_content ) : ?>
													&middot; <a href="<?php echo esc_url( $diff_url ); ?>"><?php echo $is_shown ? esc_html__( 'Hide diff', 'verlo' ) : esc_html__( 'View diff', 'verlo' ); ?></a>
													&middot;
													<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %d: version number */ __( 'Restore version %d? This replaces the current live draft content — the content that\'s live right now stays in the history too, one version back, so nothing is lost.', 'verlo' ), (int) $v['version'] ) ); ?>');">
														<input type="hidden" name="action" value="verlo_restore_version" />
														<input type="hidden" name="post_id" value="<?php echo (int) $r['post_id']; ?>" />
														<input type="hidden" name="version" value="<?php echo (int) $v['version']; ?>" />
														<?php wp_nonce_field( 'verlo_restore_version' ); ?>
														<button type="submit" class="button-link" style="color:#cf222e;font-size:12px;"><?php esc_html_e( 'Restore', 'verlo' ); ?></button>
													</form>
												<?php else : ?>
													<span style="color:#999;">&middot; <?php esc_html_e( 'no saved content to diff/restore', 'verlo' ); ?></span>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php endif; ?>
						</td>
						<td><?php echo $r['pillar'] ? esc_html( $r['pillar'] ) : '<span style="color:#999;">—</span>'; ?></td>
						<td><span title="<?php echo esc_attr( wp_date( 'M j, Y H:i', (int) $r['updated_at'] ) ); ?>">
							<?php
							printf(
								/* translators: %s: human-readable elapsed time */
								esc_html__( '%s ago', 'verlo' ),
								esc_html( human_time_diff( (int) $r['updated_at'], time() ) )
							);
							?>
						</span></td>
						<td><?php echo esc_html( $time_h ); ?></td>
						<td><span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;color:<?php echo esc_attr( $meta[1] ); ?>;background:<?php echo esc_attr( $meta[2] ); ?>;"><?php echo esc_html( $meta[0] ); ?></span></td>
						<td style="white-space:nowrap;">
							<?php if ( ! empty( $r['edit_url'] ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $r['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'verlo' ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $r['view_url'] ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $r['view_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View ↗', 'verlo' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $diff_post === (int) $r['post_id'] && $diff_version ) : ?>
						<tr id="verlo-diff-<?php echo (int) $r['post_id']; ?>">
							<td colspan="6" style="background:#f6f7f7;padding:16px;">
								<?php self::render_version_diff( (int) $r['post_id'], $diff_version ); ?>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders a diff between one past version's stored content and the
	 * CURRENT live post content, using WordPress's own wp_text_diff() — the
	 * same renderer core uses on the native Revisions screen, so the output
	 * is already safely escaped (raw HTML in post_content shows as literal
	 * text within the diff, never re-rendered) and needs no styling beyond
	 * what's inlined here, since this page doesn't enqueue wp-admin's
	 * revisions.css.
	 */
	protected static function render_version_diff( $post_id, $version ) {
		$old_content = Verlo_Article_Log::get_version_content( $post_id, $version );
		if ( null === $old_content ) {
			echo '<p style="color:#999;margin:0;">' . esc_html__( "That version's content is no longer available to diff.", 'verlo' ) . '</p>';
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			echo '<p style="color:#999;margin:0;">' . esc_html__( 'This article no longer exists.', 'verlo' ) . '</p>';
			return;
		}

		require_once ABSPATH . WPINC . '/wp-diff.php';
		$diff = wp_text_diff( $old_content, $post->post_content, array(
			'title'       => '',
			'title_left'  => sprintf( /* translators: %d: version number */ __( 'Version %d', 'verlo' ), (int) $version ),
			'title_right' => __( 'Current (live)', 'verlo' ),
		) );

		if ( ! $diff ) {
			printf(
				/* translators: %d: version number */
				'<p style="color:#646970;margin:0;">' . esc_html__( 'Version %d is identical to the current live content — no changes.', 'verlo' ) . '</p>',
				(int) $version
			);
			return;
		}
		?>
		<style>
			#verlo-diff-<?php echo (int) $post_id; ?> table.diff { width: 100%; border-collapse: collapse; font-size: 12px; font-family: ui-monospace, Consolas, monospace; }
			#verlo-diff-<?php echo (int) $post_id; ?> table.diff col.content { width: 50%; }
			#verlo-diff-<?php echo (int) $post_id; ?> table.diff td,
			#verlo-diff-<?php echo (int) $post_id; ?> table.diff th { padding: 4px 8px; vertical-align: top; }
			#verlo-diff-<?php echo (int) $post_id; ?> table.diff th { text-align: left; background: #eaeef2; }
			#verlo-diff-<?php echo (int) $post_id; ?> table.diff .diff-deletedline { background: #ffebe9; }
			#verlo-diff-<?php echo (int) $post_id; ?> table.diff .diff-addedline { background: #dafbe1; }
			#verlo-diff-<?php echo (int) $post_id; ?> table.diff del { background: #ffc1c0; text-decoration: none; }
			#verlo-diff-<?php echo (int) $post_id; ?> table.diff ins { background: #8ae6a3; text-decoration: none; }
		</style>
		<div style="overflow-x:auto;">
			<?php echo $diff; ?>
		</div>
		<?php
	}

	protected static function render_brief_detail( $aid ) {
		$b         = Verlo_Brief::get( $aid );
		$url       = admin_url( 'admin-post.php' );
		$back      = admin_url( 'admin.php?page=verlo-briefs' );
		$next      = Verlo_Strategist::pick_next();
		$connected = Verlo_Auth::is_connected();
		?>
		<p style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
			<a href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'All briefs', 'verlo' ); ?></a>
			<?php if ( $next ) : ?>
				<span style="display:inline-flex;align-items:center;gap:10px;">
					<span class="description" style="margin:0;"><?php printf( /* translators: %s: next planned keyword */ esc_html__( 'Next up: %s', 'verlo' ), '<strong>' . esc_html( $next['keyword'] ) . '</strong>' ); ?></span>
					<button type="submit" form="verlo-brief-form" name="then" value="next" class="button" data-verlo-progress="<?php esc_attr_e( 'Saving, then writing the next brief…', 'verlo' ); ?>" data-verlo-phases="brief"><?php esc_html_e( 'Save & next →', 'verlo' ); ?></button>
				</span>
			<?php endif; ?>
		</p>
		<div class="verlo-card verlo-card-full">
			<h2>
				<?php printf( /* translators: %s: target keyword */ esc_html__( 'Brief: %s', 'verlo' ), esc_html( $b['keyword'] ) ); ?>
				<span class="verlo-badge ok"><?php echo esc_html( $b['intent'] ); ?></span>
			</h2>
			<p class="verlo-sub">
				<?php
				printf( /* translators: %s: pillar name */ esc_html__( 'Pillar: %s', 'verlo' ), esc_html( $b['pillar'] ) );
				if ( ! empty( $b['meta']['generated_at'] ) ) {
					echo ' · ';
					printf(
						/* translators: %s: human-readable time since generated */
						esc_html__( 'generated %s ago', 'verlo' ),
						esc_html( human_time_diff( (int) $b['meta']['generated_at'], time() ) )
					);
				}
				?>
			</p>

			<?php
			$draft_post = null;
			if ( ! empty( $b['draft']['post_id'] ) ) {
				$dp = get_post( (int) $b['draft']['post_id'] );
				if ( $dp && 'trash' !== $dp->post_status ) { $draft_post = $dp; }
			}
			$st = Verlo_Strategist::pipeline_status( $aid );
			$gen = Verlo_Brief::get_gen_status( $aid );
			$generating = in_array( $gen['state'], array( 'queued', 'running' ), true ) && ( time() - (int) $gen['updated_at'] ) < 5 * MINUTE_IN_SECONDS;
			$just_started = ( '__generating__' === ( $_GET['verlo_notice'] ?? '' ) );

			// If the draft already exists, generation is finished: never show the
			// live/polling box (that caused an endless reload loop when the page
			// was reopened with the "__generating__" flag still in the URL). Also
			// retire a stale "done" status so it can't re-trigger anything.
			if ( $draft_post ) {
				$generating   = false;
				$just_started = false;
				if ( 'done' === $gen['state'] ) {
					Verlo_Brief::set_gen_status( $aid, 'idle', '' );
				}
			}
			?>
			<?php if ( $generating || $just_started ) : ?>
				<div id="verlo-gen-live" class="verlo-gen-live"
					data-article="<?php echo (int) $aid; ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'verlo_gen_status' ) ); ?>"
					data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<div class="verlo-gen-live-inner">
						<span class="verlo-spinner" aria-hidden="true"></span>
						<div>
							<div class="verlo-gen-title"><?php esc_html_e( 'Writing your article…', 'verlo' ); ?></div>
							<div class="verlo-gen-msg" id="verlo-gen-msg"><?php esc_html_e( 'Starting up…', 'verlo' ); ?></div>
						</div>
					</div>
					<div class="verlo-gen-note"><?php esc_html_e( "This runs in the background and can take a minute or two. You can safely stay on this page. It will update automatically when the draft is ready. No need to refresh, and please don't click generate again.", 'verlo' ); ?></div>
				</div>
			<?php elseif ( 'error' === $gen['state'] && ! $draft_post ) : ?>
				<div class="notice notice-error inline" style="margin:8px 0 16px;"><p><strong><?php esc_html_e( 'Generation failed:', 'verlo' ); ?></strong> <?php echo esc_html( $gen['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $draft_post ) : ?>
				<?php $is_pub = ( 'publish' === $draft_post->post_status ); ?>
				<div style="margin:8px 0 16px;padding:18px 20px;border:1px solid <?php echo $is_pub ? '#bfe3cf' : '#f4cf9b'; ?>;border-radius:10px;background:<?php echo $is_pub ? 'linear-gradient(180deg,#f1fbf5,#fff)' : 'linear-gradient(180deg,#fff8ee,#fff)'; ?>;">
					<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
						<div>
							<div style="font-size:13px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.03em;"><?php esc_html_e( 'Article', 'verlo' ); ?></div>
							<div style="margin-top:4px;"><span class="verlo-badge <?php echo esc_attr( $st['badge'] ); ?>" style="font-size:13px;padding:4px 12px;"><?php echo esc_html( $st['label'] ); ?></span>
							<?php
							$gen_secs = isset( $b['draft']['gen_seconds'] ) ? (float) $b['draft']['gen_seconds'] : 0;
							if ( $gen_secs > 0 ) {
								$mins = floor( $gen_secs / 60 );
								$secs = (int) round( $gen_secs - $mins * 60 );
								$human = $mins > 0 ? ( $mins . 'm ' . $secs . 's' ) : ( $secs . 's' );
								printf(
									/* translators: %s: human-readable generation duration */
									'<span style="margin-left:8px;color:#646970;font-size:12px;" title="' . esc_attr__( 'Time from clicking Generate to the draft being ready', 'verlo' ) . '">' . esc_html__( 'generated in %s', 'verlo' ) . '</span>',
									esc_html( $human )
								);
							}
							?>
							</div>
						</div>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
							<a class="button button-primary button-hero" href="<?php echo esc_url( get_edit_post_link( $draft_post->ID ) ); ?>"><?php esc_html_e( '✎ Edit article in WordPress', 'verlo' ); ?></a>
							<a class="button button-hero" href="<?php echo esc_url( $is_pub ? get_permalink( $draft_post->ID ) : get_preview_post_link( $draft_post ) ); ?>" target="_blank" rel="noopener"><?php echo $is_pub ? esc_html__( 'View live ↗', 'verlo' ) : esc_html__( 'Preview ↗', 'verlo' ); ?></a>
						</div>
					</div>
					<div class="verlo-actions" style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(0,0,0,.06);">
						<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" data-verlo-confirm-unsaved="1" onsubmit="return confirm('<?php echo esc_js( __( 'Regenerate the article? It will replace the current draft content — the current version stays in the history below and can be restored any time.', 'verlo' ) ); ?>');">
							<input type="hidden" name="action" value="verlo_brief_generate_article" />
							<input type="hidden" name="article_id" value="<?php echo (int) $aid; ?>" />
							<?php wp_nonce_field( 'verlo_brief_generate_article' ); ?>
							<button type="submit" class="button" <?php disabled( $generating || ! $connected ); ?> data-verlo-async="1"><?php esc_html_e( 'Regenerate article', 'verlo' ); ?></button>
						</form>
						<span class="description">
							<?php echo ! $connected ? esc_html__( 'Connect your Verlo license first.', 'verlo' ) : esc_html__( 'Nothing is published automatically. Edit and publish the draft in WordPress when you are happy with it.', 'verlo' ); ?>
						</span>
					</div>
				</div>
			<?php elseif ( ! $generating && ! $just_started ) : ?>
				<div style="margin:8px 0 16px;padding:18px 20px;border:1px solid #c3d9ec;border-radius:10px;background:linear-gradient(180deg,#f3f9ff,#fff);display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
					<div>
						<div style="font-size:15px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'Ready to write the article', 'verlo' ); ?></div>
						<div class="description" style="margin-top:2px;">
							<?php
							printf(
								/* translators: %s: pillar/category name */
								esc_html__( 'Generates a full draft from this brief into the "%s" category. Saved as a draft for your review. Never auto-published.', 'verlo' ),
								esc_html( $b['pillar'] )
							);
							?>
						</div>
					</div>
					<form method="post" action="<?php echo esc_url( $url ); ?>" style="margin:0;" data-verlo-confirm-unsaved="1">
						<input type="hidden" name="action" value="verlo_brief_generate_article" />
						<input type="hidden" name="article_id" value="<?php echo (int) $aid; ?>" />
						<?php wp_nonce_field( 'verlo_brief_generate_article' ); ?>
						<button type="submit" class="button button-primary button-hero" data-verlo-tour-target="article-generate"<?php echo Verlo_Guided_Tour::target_id_attr( 'article-generate' ); ?> <?php disabled( ! $connected ); ?> data-verlo-async="1"><?php esc_html_e( '✍ Generate draft article', 'verlo' ); ?></button>
					</form>
					<?php Verlo_Guided_Tour::render_target_callout( 'article-generate' ); ?>
					<?php if ( ! $connected ) : ?><span class="description"><?php esc_html_e( 'Connect your Verlo license first.', 'verlo' ); ?></span><?php endif; ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $url ); ?>" id="verlo-brief-form">
				<input type="hidden" name="action" value="verlo_brief_save" />
				<input type="hidden" name="article_id" value="<?php echo (int) $aid; ?>" />
				<?php wp_nonce_field( 'verlo_brief_save' ); ?>
				<table class="form-table" role="presentation">
					<tr class="verlo-field"><th><?php esc_html_e( 'Suggested title', 'verlo' ); ?></th><td><input type="text" name="suggested_title" value="<?php echo esc_attr( $b['suggested_title'] ); ?>" /></td></tr>
					<tr class="verlo-field"><th><?php esc_html_e( 'Angle', 'verlo' ); ?></th><td><textarea name="angle" rows="2"><?php echo esc_textarea( $b['angle'] ); ?></textarea></td></tr>
					<tr class="verlo-field"><th><?php esc_html_e( 'Search intent', 'verlo' ); ?></th><td><textarea name="search_intent" rows="2"><?php echo esc_textarea( $b['search_intent'] ); ?></textarea></td></tr>
					<tr class="verlo-field"><th><?php esc_html_e( 'Audience note', 'verlo' ); ?></th><td><textarea name="audience_note" rows="2"><?php echo esc_textarea( $b['audience_note'] ); ?></textarea></td></tr>
					<tr class="verlo-field"><th><?php esc_html_e( 'Outline (one H2 per line)', 'verlo' ); ?></th><td><textarea name="outline" rows="6"><?php echo esc_textarea( implode( "\n", $b['outline'] ) ); ?></textarea></td></tr>
					<tr class="verlo-field"><th><?php esc_html_e( 'Internal links (url | anchor)', 'verlo' ); ?></th><td>
						<textarea name="internal_links" rows="4"><?php
							$lines = array();
							foreach ( $b['internal_links'] as $l ) { $lines[] = $l['url'] . ' | ' . $l['anchor']; }
							echo esc_textarea( implode( "\n", $lines ) );
						?></textarea>
						<p class="description"><?php esc_html_e( 'Only URLs from your own site are kept.', 'verlo' ); ?></p>
					</td></tr>
					<tr class="verlo-field"><th><?php esc_html_e( 'External source ideas (one per line)', 'verlo' ); ?></th><td><textarea name="external_ideas" rows="3"><?php echo esc_textarea( implode( "\n", $b['external_ideas'] ) ); ?></textarea></td></tr>
					<tr class="verlo-field"><th><?php esc_html_e( 'FAQ questions (one per line)', 'verlo' ); ?></th><td><textarea name="faq" rows="4"><?php echo esc_textarea( implode( "\n", $b['faq'] ) ); ?></textarea></td></tr>
					<tr class="verlo-field"><th><?php esc_html_e( 'Target length', 'verlo' ); ?></th><td>
						<?php
						// Values are the band midpoints the SaaS classifier uses (see
						// verlo-saas's classifyWordCount), not the band edges — sending
						// these exact numbers keeps the payload shape identical to the
						// old free-text field, so nothing downstream needs to change.
						$verlo_wc_band = ( (int) $b['word_count'] < 1050 ) ? 750 : ( ( (int) $b['word_count'] < 1750 ) ? 1350 : 2250 );
						?>
						<select name="word_count" style="max-width:240px;">
							<option value="750" <?php selected( $verlo_wc_band, 750 ); ?>><?php esc_html_e( 'Small (600-900 words)', 'verlo' ); ?></option>
							<option value="1350" <?php selected( $verlo_wc_band, 1350 ); ?>><?php esc_html_e( 'Medium (1200-1500 words)', 'verlo' ); ?></option>
							<option value="2250" <?php selected( $verlo_wc_band, 2250 ); ?>><?php esc_html_e( 'Long (2000-2500 words)', 'verlo' ); ?></option>
						</select>
					</td></tr>
					<tr class="verlo-field"><th><?php esc_html_e( 'Voice note', 'verlo' ); ?></th><td><textarea name="voice_note" rows="2"><?php echo esc_textarea( $b['voice_note'] ); ?></textarea></td></tr>
				</table>
				<div class="verlo-actions">
					<?php submit_button( __( 'Save brief', 'verlo' ), 'primary', 'submit', false ); ?>
					<?php if ( $next ) : ?>
						<button type="submit" name="then" value="next" class="button" data-verlo-progress="<?php esc_attr_e( 'Saving, then writing the next brief…', 'verlo' ); ?>" data-verlo-phases="brief"><?php esc_html_e( 'Save & next →', 'verlo' ); ?></button>
						<span class="description"><?php printf( /* translators: %s: next planned keyword */ esc_html__( 'Saves this brief, then opens a fresh brief for %s.', 'verlo' ), '<strong>' . esc_html( $next['keyword'] ) . '</strong>' ); ?></span>
					<?php endif; ?>
				</div>
			</form>

			<hr />
			<div class="verlo-actions">
				<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Regenerate this brief with Verlo? Your edits will be replaced.', 'verlo' ) ); ?>');">
					<input type="hidden" name="action" value="verlo_brief_generate" />
					<input type="hidden" name="article_id" value="<?php echo (int) $aid; ?>" />
					<?php wp_nonce_field( 'verlo_brief_generate' ); ?>
					<button type="submit" class="button" <?php disabled( ! $connected ); ?> data-verlo-progress="<?php esc_attr_e( 'Rewriting brief with Verlo…', 'verlo' ); ?>" data-verlo-phases="brief"><?php esc_html_e( 'Regenerate with Verlo', 'verlo' ); ?></button>
				</form>
				<?php if ( ! $connected ) : ?><span class="description"><?php esc_html_e( 'Connect your Verlo license first.', 'verlo' ); ?></span><?php endif; ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this brief?', 'verlo' ) ); ?>');">
					<input type="hidden" name="action" value="verlo_brief_delete" />
					<input type="hidden" name="article_id" value="<?php echo (int) $aid; ?>" />
					<?php wp_nonce_field( 'verlo_brief_delete' ); ?>
					<button type="submit" class="button-link" style="color:#b32d2e;"><?php esc_html_e( 'Delete brief', 'verlo' ); ?></button>
				</form>
			</div>
		</div>

		<style>
			.verlo-gen-live{margin:8px 0 16px;padding:18px 20px;border:1px solid #c3d9ec;border-radius:10px;
				background:linear-gradient(180deg,#f3f9ff,#fff);}
			.verlo-gen-live-inner{display:flex;align-items:center;gap:14px;}
			.verlo-gen-title{font-size:15px;font-weight:600;color:#1d2327;}
			.verlo-gen-msg{margin-top:2px;color:#1b5e9e;font-size:13px;min-height:18px;transition:opacity .25s;}
			.verlo-gen-note{margin-top:12px;color:#646970;font-size:12px;line-height:1.5;}
			.verlo-spinner{width:26px;height:26px;flex:0 0 26px;border-radius:50%;
				border:3px solid #cfe0f3;border-top-color:#1b5e9e;animation:verlo-spin .9s linear infinite;}
			@keyframes verlo-spin{to{transform:rotate(360deg);}}
		</style>
		<script>
		(function(){
			var box = document.getElementById('verlo-gen-live');
			if(!box) return;
			var msgEl = document.getElementById('verlo-gen-msg');
			var aid   = box.getAttribute('data-article');
			var nonce = box.getAttribute('data-nonce');
			var ajax  = box.getAttribute('data-ajax');

			var I18N = <?php
				echo wp_json_encode( array(
					/* translators: strings shown in the article-generation progress box */
					'phases' => array(
						__( 'Reading your content brief…', 'verlo' ),
						__( 'Studying the outline and search intent…', 'verlo' ),
						__( 'Drafting the introduction…', 'verlo' ),
						__( 'Writing the main sections…', 'verlo' ),
						__( 'Weaving in your internal links…', 'verlo' ),
						__( 'Composing the FAQ…', 'verlo' ),
						__( 'Tightening the writing to sound human…', 'verlo' ),
						__( 'Optimising on-page SEO and meta…', 'verlo' ),
						__( 'Selecting and placing images…', 'verlo' ),
						__( 'Converting to clean editor blocks…', 'verlo' ),
					),
					/* translators: %s: elapsed time, e.g. "45s" or "2m 10s" */
					'finishingUp'     => __( 'Finishing up, working on it (%s elapsed)', 'verlo' ),
					'done'            => __( 'Done. Loading your draft…', 'verlo' ),
					'generationFailed' => __( 'Generation failed', 'verlo' ),
					'somethingWrong'  => __( 'Something went wrong.', 'verlo' ),
					'reload'          => __( 'Reload', 'verlo' ),
					'stillWorking'    => __( 'Still working. If it does not finish shortly you can run it directly:', 'verlo' ),
					'runNow'          => __( 'Run now', 'verlo' ),
					'running'         => __( 'Running… this can take a minute, please keep this tab open.', 'verlo' ),
				) );
			?>;

			// Stage messages that PROGRESS forward through the real pipeline once,
			// then settle into a calm "still working" state with elapsed time —
			// rather than looping the same list endlessly (which feels fake). The
			// final phase holds until the job actually completes.
			var phases = I18N.phases;
			var startedAt = Date.now();
			var pi = 0;
			function fmtElapsed(){
				var s = Math.floor((Date.now() - startedAt) / 1000);
				if(s < 60) return s + 's';
				return Math.floor(s/60) + 'm ' + (s%60) + 's';
			}
			function setMsg(text){
				if(!msgEl) return;
				msgEl.style.opacity = 0;
				setTimeout(function(){ msgEl.textContent = text; msgEl.style.opacity = 1; }, 180);
			}
			function nextMsg(){
				if(pi < phases.length){
					setMsg(phases[pi]);
					pi++;
				} else {
					// Reached the end of the real stages but the draft isn't back
					// yet: stop pretending, show an honest waiting state with a
					// live elapsed timer.
					clearInterval(roll);
					if(msgEl){ msgEl.textContent = I18N.finishingUp.replace('%s', fmtElapsed()); }
					elapsedTick = setInterval(function(){
						if(msgEl && !finished){ msgEl.textContent = I18N.finishingUp.replace('%s', fmtElapsed()); }
					}, 1000);
				}
			}
			var elapsedTick = null;
			nextMsg();
			// Advance through the real stages at a steady, believable pace.
			var roll = setInterval(nextMsg, 4000);
			var finished = false;
			var inflight = false;

			function finish(){
				if(finished) return;       // never reload more than once
				finished = true;
				clearInterval(roll); clearInterval(timer); if(elapsedTick) clearInterval(elapsedTick);
				if(msgEl){ msgEl.textContent = I18N.done; }
				// Reload WITHOUT the "__generating__" flag so the finished page
				// renders the draft panel cleanly and never re-enters polling.
				var u = window.location.href
					.replace(/([?&])verlo_notice=__generating__(&|$)/, function(_,a,b){ return b ? a : ''; })
					.replace(/[?&]$/,'');
				window.location.replace(u);
			}

			function showError(msg){
				finished = true;
				clearInterval(roll); clearInterval(timer); if(elapsedTick) clearInterval(elapsedTick);
				var t = box.querySelector('.verlo-gen-title');
				if(t){ t.textContent = I18N.generationFailed; }
				if(msgEl){
					// msg is a server-supplied string — set it as text, never HTML.
					msgEl.textContent = (msg || I18N.somethingWrong) + '  ';
					var rl = document.createElement('a');
					rl.href = '#'; rl.textContent = I18N.reload;
					rl.addEventListener('click', function(e){ e.preventDefault(); window.location.reload(); });
					msgEl.appendChild(rl);
				}
				var sp = box.querySelector('.verlo-spinner');
				if(sp){ sp.style.display = 'none'; }
			}

			// A poll's request can run the job synchronously and take well over a
			// minute — long enough that a reverse proxy in front of PHP can cut
			// the connection before PHP itself is done (PHP keeps running
			// server-side either way, orphaned from that one request). When that
			// happens the fetch below fails even though generation completes
			// moments later, and normally the NEXT poll would just pick up the
			// finished state — but on some hosts that hand-off doesn't happen
			// reliably. A handful of consecutive failures is a strong enough
			// signal to fall back to an actual page load, which always reads
			// the real current state fresh.
			var consecutiveFailures = 0;
			var MAX_CONSECUTIVE_FAILURES = 4;

			function poll(force){
				if(finished) return;
				if(inflight && !force) return;   // don't stack long self-heal runs
				inflight = true;
				var url = ajax + '?action=verlo_gen_status&article_id=' + encodeURIComponent(aid)
					+ '&nonce=' + encodeURIComponent(nonce) + (force ? '&force=1' : '');
				fetch(url, {credentials:'same-origin'})
					.then(function(r){ return r.json(); })
					.then(function(res){
						inflight = false;
						if(!res || !res.success){ return; }
						consecutiveFailures = 0;
						var d = res.data || {};
						if(d.reload || d.state === 'done'){ finish(); }
						else if(d.state === 'error'){ showError(d.message); }
					})
					.catch(function(){
						inflight = false;
						consecutiveFailures++;
						if(!finished && consecutiveFailures >= MAX_CONSECUTIVE_FAILURES){
							// Don't assume success like finish() does — just reload
							// the exact current page. The server re-checks real
							// status on every load regardless of this polling UI,
							// so this correctly shows the finished draft if the
							// orphaned generation completed, or re-enters polling
							// cleanly if it's genuinely still running.
							finished = true;
							clearInterval(roll); clearInterval(timer); if(elapsedTick) clearInterval(elapsedTick);
							window.location.reload();
						}
					});
			}
			// Poll frequently. A poll may itself run the job synchronously if the
			// background worker was blocked, so requests can be long — inflight
			// guard prevents overlap, and the interval keeps checking. The server
			// decides when to take over (fast on sites where background is known
			// to be blocked), so on those sites this completes automatically.
			var timer = setInterval(function(){ poll(false); }, 4000);
			setTimeout(function(){ poll(false); }, 1200);

			// Manual "Run now" is a debug/safety fallback only. It appears late
			// (the automatic in-tab takeover normally finishes first), so a
			// healthy or even a blocked-but-working site rarely shows it.
			setTimeout(function(){
				if(finished) return;
				var note = box.querySelector('.verlo-gen-note');
				if(note && !document.getElementById('verlo-run-now')){
					note.innerHTML = I18N.stillWorking + ' '
						+ '<button type="button" class="button button-small" id="verlo-run-now">' + I18N.runNow + '</button> '
						+ '<span id="verlo-run-now-msg" style="color:#646970;"></span>';
					document.getElementById('verlo-run-now').addEventListener('click', function(){
						this.disabled = true;
						var m = document.getElementById('verlo-run-now-msg');
						if(m){ m.textContent = ' ' + I18N.running; }
						poll(true);
					});
				}
			}, 90 * 1000);
		})();

		// Warn before generating from a brief with unsaved edits — the
		// generate/regenerate forms below are separate <form> elements from
		// the brief editor, so an edited-but-unsaved field (e.g. target
		// length) is otherwise silently discarded: the server only ever
		// reads the last SAVED brief, never anything sitting in the form.
		(function(){
			var briefForm = document.getElementById('verlo-brief-form');
			if(!briefForm) return;
			var initial = new URLSearchParams(new FormData(briefForm)).toString();
			function isDirty(){
				return new URLSearchParams(new FormData(briefForm)).toString() !== initial;
			}
			var confirmMsg = <?php echo wp_json_encode( __( 'This brief has unsaved changes. Generating now uses the last SAVED version, not your edits. Continue anyway, or cancel and save first?', 'verlo' ) ); ?>;
			document.querySelectorAll('form[data-verlo-confirm-unsaved]').forEach(function(f){
				f.addEventListener('submit', function(e){
					if(isDirty() && !confirm(confirmMsg)){
						e.preventDefault();
					}
				});
			});
		})();
		</script>
		<?php
	}

	/* ----- handlers ----- */

	/**
	 * Same 503 exposure as handle_generate_next() below (build_brief() calls
	 * the Verlo SaaS, up to ~60s) - same fix, queued through Verlo_Async_Job
	 * instead of blocking the request. Redirects straight to this article's
	 * detail view (we already know its id) so the poll overlay shows there
	 * rather than flashing the overview list first.
	 */
	public static function handle_generate() {
		self::guard( 'verlo_brief_generate' );
		$aid = (int) ( $_POST['article_id'] ?? 0 );
		Verlo_Async_Job::queue( 'brief-next', array( 'article_id' => $aid ) );
		self::redirect_to_brief( $aid, '__working__' );
	}

	/**
	 * Brief generation calls the Verlo SaaS and can take up to ~60s
	 * (Verlo_SaaS_Client::run_job()'s timeout) - long enough to 503 on hosts
	 * with a shorter proxy/PHP execution limit. pick_next() stays here,
	 * synchronous: it only reads already-stored briefs/map state, no AI call,
	 * so resolving the target article before queuing keeps the fast "nothing
	 * left to brief" case instant. The actual build_brief() AI call now runs
	 * in Verlo_Strategist::run_pending() via Verlo_Async_Job.
	 */
	public static function handle_generate_next() {
		self::guard( 'verlo_brief_generate_next' );
		$next = Verlo_Strategist::pick_next();
		if ( ! $next ) { self::redirect( __( 'Every planned article already has a brief.', 'verlo' ) ); }
		Verlo_Async_Job::queue( 'brief-next', array( 'article_id' => $next['id'] ) );
		self::redirect( '__working__' );
	}

	public static function handle_save() {
		self::guard( 'verlo_brief_save' );
		$aid = (int) ( $_POST['article_id'] ?? 0 );
		$b   = Verlo_Brief::get( $aid );
		if ( ! $b ) { self::redirect( __( 'Brief not found.', 'verlo' ), true ); }

		$lines = function ( $key ) {
			$raw = (string) wp_unslash( $_POST[ $key ] ?? '' );
			$out = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = sanitize_text_field( $line );
				if ( '' !== $line ) { $out[] = $line; }
			}
			return $out;
		};

		// Parse internal links "url | anchor", keep only own-site URLs.
		$links   = array();
		$dropped = array();
		$home    = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['internal_links'] ?? '' ) ) as $line ) {
			if ( '' === trim( $line ) ) { continue; }
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$u     = esc_url_raw( $parts[0] );
			if ( $u && wp_parse_url( $u, PHP_URL_HOST ) === $home ) {
				$links[] = array( 'url' => $u, 'anchor' => sanitize_text_field( $parts[1] ?? '' ) );
			} elseif ( '' !== trim( $parts[0] ) ) {
				$dropped[] = $parts[0];
			}
		}

		$b['suggested_title'] = sanitize_text_field( wp_unslash( $_POST['suggested_title'] ?? '' ) );
		$b['angle']           = sanitize_textarea_field( wp_unslash( $_POST['angle'] ?? '' ) );
		$b['search_intent']   = sanitize_textarea_field( wp_unslash( $_POST['search_intent'] ?? '' ) );
		$b['audience_note']   = sanitize_textarea_field( wp_unslash( $_POST['audience_note'] ?? '' ) );
		$b['outline']         = $lines( 'outline' );
		$b['internal_links']  = $links;
		$b['external_ideas']  = $lines( 'external_ideas' );
		$b['faq']             = $lines( 'faq' );
		// Defensively clamp to the closed enum — the dropdown only ever sends
		// one of these three values, but never trust POST data at face value.
		$verlo_wc_posted = (int) ( $_POST['word_count'] ?? 1350 );
		$b['word_count'] = in_array( $verlo_wc_posted, array( 750, 1350, 2250 ), true ) ? $verlo_wc_posted : 1350;
		$b['voice_note']      = sanitize_textarea_field( wp_unslash( $_POST['voice_note'] ?? '' ) );
		$b['meta']['updated_at'] = time();

		Verlo_Brief::save( $aid, $b );

		// "Save & next": save this brief, then generate the next one and open it.
		// The generate step is the same ~60s SaaS call as handle_generate_next()
		// - queued through Verlo_Async_Job for the same reason (see that
		// handler's docblock). One accepted fidelity trade-off: if the queued
		// generation itself errors, the error lands on the overview page
		// rather than back on this specific brief ($aid) the way the old
		// synchronous version did - a minor UX difference on an already-rare
		// failure path, not worth the extra plumbing to thread $aid through
		// the generic async status response just for the error branch.
		if ( 'next' === ( $_POST['then'] ?? '' ) ) {
			$prefix = empty( $dropped )
				? __( 'Brief saved. ', 'verlo' )
				: sprintf( /* translators: %d: number of removed links */ __( 'Brief saved (removed %d off-site link(s)). ', 'verlo' ), count( $dropped ) );
			$next   = Verlo_Strategist::pick_next();
			if ( ! $next ) {
				self::redirect_to_brief( $aid, $prefix . __( 'Every planned article now has a brief.', 'verlo' ) );
			}
			Verlo_Async_Job::queue( 'brief-next', array( 'article_id' => $next['id'], 'prefix' => $prefix ) );
			self::redirect( '__working__' );
		}

		if ( ! empty( $dropped ) ) {
			$shown = array_slice( $dropped, 0, 3 );
			$more  = count( $dropped ) > 3 ? ' ' . sprintf( /* translators: %d: number of additional dropped links not shown */ __( 'and %d more', 'verlo' ), count( $dropped ) - 3 ) : '';
			self::redirect_to_brief(
				$aid,
				sprintf(
					/* translators: 1: number of dropped links, 2: comma-separated list of dropped links, 3: "and N more" suffix or empty */
					__( 'Brief saved. Removed %1$d link(s) not on your site: %2$s%3$s. Internal links must point to your own domain.', 'verlo' ),
					count( $dropped ),
					implode( ', ', $shown ),
					$more
				),
				true
			);
		}
		self::redirect_to_brief( $aid, __( 'Brief saved.', 'verlo' ) );
	}

	public static function handle_delete() {
		self::guard( 'verlo_brief_delete' );
		$aid = (int) ( $_POST['article_id'] ?? 0 );
		Verlo_Brief::delete( $aid );
		self::redirect( __( 'Brief deleted.', 'verlo' ) );
	}

	/**
	 * Sets a post's live content back to a past generated version. The
	 * restore itself is recorded as a new version (see
	 * Verlo_Article_Log::restore()), so this can never actually lose
	 * anything — even what was live immediately before the restore stays
	 * in the history, one version back.
	 */
	public static function handle_restore_version() {
		self::guard( 'verlo_restore_version' );
		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$version = (int) ( $_POST['version'] ?? 0 );

		$result = Verlo_Article_Log::restore( $post_id, $version );
		if ( is_wp_error( $result ) ) {
			self::redirect( sprintf( /* translators: %s: error message */ __( 'Could not restore that version: %s', 'verlo' ), $result->get_error_message() ), true );
		}
		self::redirect( sprintf(
			/* translators: %d: version number restored */
			__( 'Restored version %d. The live draft now matches it — this is recorded as a new version, so nothing that was live before is lost either.', 'verlo' ),
			$version
		) );
	}

	public static function handle_generate_article() {
		self::guard( 'verlo_brief_generate_article' );
		$aid = (int) ( $_POST['article_id'] ?? 0 );
		$res = Verlo_Generator::queue_draft( $aid );
		if ( is_wp_error( $res ) ) {
			self::redirect_to_brief( $aid, sprintf( /* translators: %s: error message */ __( 'Could not start generation: %s', 'verlo' ), $res->get_error_message() ), true, Verlo_SaaS_Client::is_billing_error( $res ) );
		}
		// Return immediately; the brief page shows a live progress state and
		// polls for completion, so the browser never waits on the API call.
		self::redirect_to_brief( $aid, '__generating__' );
	}

	protected static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce ) ) {
			wp_die( esc_html__( 'Permission denied.', 'verlo' ) );
		}
	}

	protected static function redirect( $notice, $is_error = false, $link_billing = false ) {
		$args = array( 'page' => 'verlo-briefs', 'verlo_notice' => rawurlencode( $notice ) );
		if ( $is_error ) { $args['verlo_error'] = 1; }
		if ( $link_billing ) { $args['verlo_link_billing'] = 1; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	protected static function redirect_to_brief( $aid, $notice, $is_error = false, $link_billing = false ) {
		$args = array(
			'page'         => 'verlo-briefs',
			'verlo_brief'  => (int) $aid,
			'verlo_notice' => rawurlencode( $notice ),
		);
		if ( $is_error ) { $args['verlo_error'] = 1; }
		if ( $link_billing ) { $args['verlo_link_billing'] = 1; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
