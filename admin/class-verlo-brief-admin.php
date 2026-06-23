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
		// when forced, or when the job has stalled past the env-aware delay (on
		// sites where background dispatch is known-blocked, this is just a few
		// seconds, so the open tab finishes the work automatically and fast —
		// without the user needing to press "Run now").
		$age       = time() - (int) $status['updated_at'];
		$delay     = Verlo_Env::self_heal_delay();
		$stalled   = in_array( $status['state'], array( 'queued', 'running' ), true ) && $age >= $delay;
		$needs_run = ! $post_id && 'done' !== $status['state'] && 'error' !== $status['state'];

		if ( $needs_run && ( $force || $stalled ) ) {
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
		add_submenu_page( 'verlo', 'Content Briefs', 'Content Briefs', 'manage_options', 'verlo-briefs', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$notice   = isset( $_GET['verlo_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['verlo_notice'] ) ) : '';
		$is_error = isset( $_GET['verlo_error'] );
		?>
		<div class="wrap verlo-wrap">
			<h1>Content Briefs</h1>
			<p style="margin-top:2px;color:#646970;">The spec for each planned article: title, angle, outline, internal links, and intent. Reviewed before anything is written.</p>
			<?php if ( $notice && '__generating__' !== $notice ) : ?>
				<div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php
			if ( ! Verlo_Topical_Map::is_approved() ) {
				$map_url = admin_url( 'admin.php?page=verlo-map' );
				echo '<div class="verlo-card" style="margin-top:14px;"><h2>Topical Map not approved</h2><p class="verlo-sub">Briefs are generated from the approved map. <a href="' . esc_url( $map_url ) . '">Open the Topical Map →</a></p></div></div>';
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
		$url   = admin_url( 'admin-post.php' );
		$stats = Verlo_Strategist::stats();
		$next  = Verlo_Strategist::pick_next();
		?>
		<div class="verlo-card" style="margin-top:14px;">
			<h2>Overview</h2>
			<p class="verlo-sub">
				<?php echo (int) $stats['planned']; ?> planned articles ·
				<?php echo (int) $stats['with_brief']; ?> briefed ·
				<?php echo (int) $stats['without']; ?> awaiting a brief
			</p>
			<div class="verlo-actions">
				<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline">
					<input type="hidden" name="action" value="verlo_brief_generate_next" />
					<?php wp_nonce_field( 'verlo_brief_generate_next' ); ?>
					<button type="submit" class="button button-primary" data-verlo-progress="Writing brief with Verlo…" data-verlo-phases="brief" <?php disabled( ! $next ); ?>>
						<?php echo $next ? 'Generate next brief' : 'All planned articles briefed'; ?>
					</button>
				</form>
				<?php if ( $next ) : ?><span class="description">Next: <strong><?php echo esc_html( $next['keyword'] ); ?></strong></span><?php endif; ?>
			</div>
		</div>

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
					<thead><tr><th style="width:46%">Planned article</th><th>Intent</th><th>Status</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $articles as $a ) : ?>
						<tr>
							<td><?php echo esc_html( $a['keyword'] ); ?></td>
							<td><code><?php echo esc_html( $a['intent'] ); ?></code></td>
							<td><?php $st = Verlo_Strategist::pipeline_status( $a['id'] ); ?><span class="verlo-badge <?php echo esc_attr( $st['badge'] ); ?>"><?php echo esc_html( $st['label'] ); ?></span></td>
							<td>
								<?php if ( $a['has_brief'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=verlo-briefs&verlo_brief=' . $a['id'] ) ); ?>">Open</a>
									<?php if ( ! empty( $st['post_id'] ) ) : ?>
										<a class="button button-small button-primary" href="<?php echo esc_url( get_edit_post_link( $st['post_id'] ) ); ?>"><?php echo 'published' === $st['state'] ? 'Edit post' : 'Edit draft'; ?> →</a>
									<?php endif; ?>
								<?php else : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
										<input type="hidden" name="action" value="verlo_brief_generate" />
										<input type="hidden" name="article_id" value="<?php echo (int) $a['id']; ?>" />
										<?php wp_nonce_field( 'verlo_brief_generate' ); ?>
										<button type="submit" class="button button-small button-primary" data-verlo-progress="Writing brief with Verlo…" data-verlo-phases="brief">Generate brief</button>
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

		$labels = array(
			'published' => array( 'Published', '#1a7f37', '#dafbe1' ),
			'draft'     => array( 'Draft', '#9a6700', '#fff8c5' ),
			'pending'   => array( 'Pending', '#9a6700', '#fff8c5' ),
			'future'    => array( 'Scheduled', '#0969da', '#ddf4ff' ),
			'private'   => array( 'Private', '#57606a', '#eaeef2' ),
			'trashed'   => array( 'Trashed', '#cf222e', '#ffebe9' ),
			'deleted'   => array( 'Deleted', '#82071e', '#ffd7d5' ),
			'other'     => array( 'Other', '#57606a', '#eaeef2' ),
		);
		?>
		<div class="verlo-card verlo-card-full" style="margin-top:18px;">
			<h2>Generated articles <span style="font-weight:400;color:#646970;font-size:13px;">· <?php echo (int) count( $rows ); ?> on record</span></h2>
			<p class="verlo-sub" style="margin-top:-4px;">Every article Verlo has written on this site. This list is preserved even if you rebuild the topical map, so completed work is never lost. Status is read live from WordPress.</p>
			<table class="widefat striped">
				<thead><tr>
					<th style="width:34%">Article</th>
					<th>Pillar</th>
					<th>Generated</th>
					<th>Time</th>
					<th>Status</th>
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
					$title = '' !== $r['title'] ? $r['title'] : $r['keyword'];
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $title ); ?></strong>
							<?php if ( '' !== $r['keyword'] && $r['keyword'] !== $title ) : ?>
								<br><span style="color:#646970;font-size:12px;"><?php echo esc_html( $r['keyword'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $r['pillar'] ? esc_html( $r['pillar'] ) : '<span style="color:#999;">—</span>'; ?></td>
						<td><span title="<?php echo esc_attr( wp_date( 'M j, Y H:i', (int) $r['updated_at'] ) ); ?>"><?php echo esc_html( human_time_diff( (int) $r['updated_at'], time() ) ); ?> ago</span></td>
						<td><?php echo esc_html( $time_h ); ?></td>
						<td><span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;color:<?php echo esc_attr( $meta[1] ); ?>;background:<?php echo esc_attr( $meta[2] ); ?>;"><?php echo esc_html( $meta[0] ); ?></span></td>
						<td style="white-space:nowrap;">
							<?php if ( ! empty( $r['edit_url'] ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $r['edit_url'] ); ?>">Edit</a>
							<?php endif; ?>
							<?php if ( ! empty( $r['view_url'] ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $r['view_url'] ); ?>" target="_blank" rel="noopener">View ↗</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	protected static function render_brief_detail( $aid ) {
		$b    = Verlo_Brief::get( $aid );
		$url  = admin_url( 'admin-post.php' );
		$back = admin_url( 'admin.php?page=verlo-briefs' );
		$next = Verlo_Strategist::pick_next();
		?>
		<p style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
			<a href="<?php echo esc_url( $back ); ?>">← All briefs</a>
			<?php if ( $next ) : ?>
				<span style="display:inline-flex;align-items:center;gap:10px;">
					<span class="description" style="margin:0;">Next up: <strong><?php echo esc_html( $next['keyword'] ); ?></strong></span>
					<button type="submit" form="verlo-brief-form" name="then" value="next" class="button" data-verlo-progress="Saving, then writing the next brief…" data-verlo-phases="brief">Save &amp; next →</button>
				</span>
			<?php endif; ?>
		</p>
		<div class="verlo-card verlo-card-full">
			<h2>Brief: <?php echo esc_html( $b['keyword'] ); ?> <span class="verlo-badge ok"><?php echo esc_html( $b['intent'] ); ?></span></h2>
			<p class="verlo-sub">Pillar: <?php echo esc_html( $b['pillar'] ); ?><?php echo ! empty( $b['meta']['generated_at'] ) ? ' · generated ' . esc_html( human_time_diff( (int) $b['meta']['generated_at'], time() ) ) . ' ago' : ''; ?></p>

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
							<div class="verlo-gen-title">Writing your article…</div>
							<div class="verlo-gen-msg" id="verlo-gen-msg">Starting up…</div>
						</div>
					</div>
					<div class="verlo-gen-note">This runs in the background and can take a minute or two. You can safely stay on this page. It will update automatically when the draft is ready. No need to refresh, and please don't click generate again.</div>
				</div>
			<?php elseif ( 'error' === $gen['state'] && ! $draft_post ) : ?>
				<div class="notice notice-error inline" style="margin:8px 0 16px;"><p><strong>Generation failed:</strong> <?php echo esc_html( $gen['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $draft_post ) : ?>
				<?php $is_pub = ( 'publish' === $draft_post->post_status ); ?>
				<div style="margin:8px 0 16px;padding:18px 20px;border:1px solid <?php echo $is_pub ? '#bfe3cf' : '#f4cf9b'; ?>;border-radius:10px;background:<?php echo $is_pub ? 'linear-gradient(180deg,#f1fbf5,#fff)' : 'linear-gradient(180deg,#fff8ee,#fff)'; ?>;">
					<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
						<div>
							<div style="font-size:13px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.03em;">Article</div>
							<div style="margin-top:4px;"><span class="verlo-badge <?php echo esc_attr( $st['badge'] ); ?>" style="font-size:13px;padding:4px 12px;"><?php echo esc_html( $st['label'] ); ?></span>
							<?php
							$gen_secs = isset( $b['draft']['gen_seconds'] ) ? (float) $b['draft']['gen_seconds'] : 0;
							if ( $gen_secs > 0 ) {
								$mins = floor( $gen_secs / 60 );
								$secs = (int) round( $gen_secs - $mins * 60 );
								$human = $mins > 0 ? ( $mins . 'm ' . $secs . 's' ) : ( $secs . 's' );
								echo '<span style="margin-left:8px;color:#646970;font-size:12px;" title="Actual server-side generation time">generated in ' . esc_html( $human ) . '</span>';
							}
							?>
							</div>
						</div>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
							<a class="button button-primary button-hero" href="<?php echo esc_url( get_edit_post_link( $draft_post->ID ) ); ?>">✎ Edit article in WordPress</a>
							<a class="button button-hero" href="<?php echo esc_url( $is_pub ? get_permalink( $draft_post->ID ) : get_preview_post_link( $draft_post ) ); ?>" target="_blank" rel="noopener"><?php echo $is_pub ? 'View live ↗' : 'Preview ↗'; ?></a>
						</div>
					</div>
					<div class="verlo-actions" style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(0,0,0,.06);">
						<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" onsubmit="return confirm('Regenerate the article? It will overwrite the current draft content.');">
							<input type="hidden" name="action" value="verlo_brief_generate_article" />
							<input type="hidden" name="article_id" value="<?php echo (int) $aid; ?>" />
							<?php wp_nonce_field( 'verlo_brief_generate_article' ); ?>
							<button type="submit" class="button" <?php disabled( $generating ); ?> data-verlo-async="1">Regenerate article</button>
						</form>
						<span class="description">Nothing is published automatically. Edit and publish the draft in WordPress when you are happy with it.</span>
					</div>
				</div>
			<?php elseif ( ! $generating && ! $just_started ) : ?>
				<div style="margin:8px 0 16px;padding:18px 20px;border:1px solid #c3d9ec;border-radius:10px;background:linear-gradient(180deg,#f3f9ff,#fff);display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
					<div>
						<div style="font-size:15px;font-weight:600;color:#1d2327;">Ready to write the article</div>
						<div class=”description” style=”margin-top:2px;”>Generates a full draft from this brief into the “<?php echo esc_html( $b['pillar'] ); ?>” category. Saved as a draft for your review. Never auto-published.</div>
					</div>
					<form method="post" action="<?php echo esc_url( $url ); ?>" style="margin:0;">
						<input type="hidden" name="action" value="verlo_brief_generate_article" />
						<input type="hidden" name="article_id" value="<?php echo (int) $aid; ?>" />
						<?php wp_nonce_field( 'verlo_brief_generate_article' ); ?>
						<button type="submit" class="button button-primary button-hero" data-verlo-async="1">✍ Generate draft article</button>
					</form>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $url ); ?>" id="verlo-brief-form">
				<input type="hidden" name="action" value="verlo_brief_save" />
				<input type="hidden" name="article_id" value="<?php echo (int) $aid; ?>" />
				<?php wp_nonce_field( 'verlo_brief_save' ); ?>
				<table class="form-table" role="presentation">
					<tr class="verlo-field"><th>Suggested title</th><td><input type="text" name="suggested_title" value="<?php echo esc_attr( $b['suggested_title'] ); ?>" /></td></tr>
					<tr class="verlo-field"><th>Angle</th><td><textarea name="angle" rows="2"><?php echo esc_textarea( $b['angle'] ); ?></textarea></td></tr>
					<tr class="verlo-field"><th>Search intent</th><td><textarea name="search_intent" rows="2"><?php echo esc_textarea( $b['search_intent'] ); ?></textarea></td></tr>
					<tr class="verlo-field"><th>Audience note</th><td><textarea name="audience_note" rows="2"><?php echo esc_textarea( $b['audience_note'] ); ?></textarea></td></tr>
					<tr class="verlo-field"><th>Outline (one H2 per line)</th><td><textarea name="outline" rows="6"><?php echo esc_textarea( implode( "\n", $b['outline'] ) ); ?></textarea></td></tr>
					<tr class="verlo-field"><th>Internal links (url | anchor)</th><td>
						<textarea name="internal_links" rows="4"><?php
							$lines = array();
							foreach ( $b['internal_links'] as $l ) { $lines[] = $l['url'] . ' | ' . $l['anchor']; }
							echo esc_textarea( implode( "\n", $lines ) );
						?></textarea>
						<p class="description">Only URLs from your own site are kept.</p>
					</td></tr>
					<tr class="verlo-field"><th>External source ideas (one per line)</th><td><textarea name="external_ideas" rows="3"><?php echo esc_textarea( implode( "\n", $b['external_ideas'] ) ); ?></textarea></td></tr>
					<tr class="verlo-field"><th>FAQ questions (one per line)</th><td><textarea name="faq" rows="4"><?php echo esc_textarea( implode( "\n", $b['faq'] ) ); ?></textarea></td></tr>
					<tr class="verlo-field"><th>Target word count</th><td><input type="number" name="word_count" value="<?php echo (int) $b['word_count']; ?>" min="300" step="100" style="max-width:140px;" /></td></tr>
					<tr class="verlo-field"><th>Voice note</th><td><textarea name="voice_note" rows="2"><?php echo esc_textarea( $b['voice_note'] ); ?></textarea></td></tr>
				</table>
				<div class="verlo-actions">
					<?php submit_button( 'Save brief', 'primary', 'submit', false ); ?>
					<?php if ( $next ) : ?>
						<button type="submit" name="then" value="next" class="button" data-verlo-progress="Saving, then writing the next brief…" data-verlo-phases="brief">Save &amp; next →</button>
						<span class="description">Saves this brief, then opens a fresh brief for <strong><?php echo esc_html( $next['keyword'] ); ?></strong>.</span>
					<?php endif; ?>
				</div>
			</form>

			<hr />
			<div class="verlo-actions">
				<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" onsubmit="return confirm('Regenerate this brief with Verlo? Your edits will be replaced.');">
					<input type="hidden" name="action" value="verlo_brief_generate" />
					<input type="hidden" name="article_id" value="<?php echo (int) $aid; ?>" />
					<?php wp_nonce_field( 'verlo_brief_generate' ); ?>
					<button type="submit" class="button" data-verlo-progress="Rewriting brief with Verlo…" data-verlo-phases="brief">Regenerate with Verlo</button>
				</form>
				<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline" onsubmit="return confirm('Delete this brief?');">
					<input type="hidden" name="action" value="verlo_brief_delete" />
					<input type="hidden" name="article_id" value="<?php echo (int) $aid; ?>" />
					<?php wp_nonce_field( 'verlo_brief_delete' ); ?>
					<button type="submit" class="button-link" style="color:#b32d2e;">Delete brief</button>
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

			// Stage messages that PROGRESS forward through the real pipeline once,
			// then settle into a calm "still working" state with elapsed time —
			// rather than looping the same list endlessly (which feels fake). The
			// final phase holds until the job actually completes.
			var phases = [
				'Reading your content brief…',
				'Studying the outline and search intent…',
				'Drafting the introduction…',
				'Writing the main sections…',
				'Weaving in your internal links…',
				'Composing the FAQ…',
				'Tightening the writing to sound human…',
				'Optimising on-page SEO and meta…',
				'Selecting and placing images…',
				'Converting to clean editor blocks…'
			];
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
					if(msgEl){ msgEl.textContent = 'Finishing up, working on it (' + fmtElapsed() + ' elapsed)'; }
					elapsedTick = setInterval(function(){
						if(msgEl && !finished){ msgEl.textContent = 'Finishing up, working on it (' + fmtElapsed() + ' elapsed)'; }
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
				if(msgEl){ msgEl.textContent = 'Done. Loading your draft…'; }
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
				if(t){ t.textContent = 'Generation failed'; }
				if(msgEl){ msgEl.innerHTML = (msg || 'Something went wrong.') + ' &nbsp;<a href="javascript:window.location.reload()">Reload</a>'; }
				var sp = box.querySelector('.verlo-spinner');
				if(sp){ sp.style.display = 'none'; }
			}

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
						var d = res.data || {};
						if(d.reload || d.state === 'done'){ finish(); }
						else if(d.state === 'error'){ showError(d.message); }
					})
					.catch(function(){ inflight = false; /* transient; keep polling */ });
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
					note.innerHTML = 'Still working. If it does not finish shortly you can run it directly: '
						+ '<button type="button" class="button button-small" id="verlo-run-now">Run now</button> '
						+ '<span id="verlo-run-now-msg" style="color:#646970;"></span>';
					document.getElementById('verlo-run-now').addEventListener('click', function(){
						this.disabled = true;
						var m = document.getElementById('verlo-run-now-msg');
						if(m){ m.textContent = ' Running… this can take a minute, please keep this tab open.'; }
						poll(true);
					});
				}
			}, 90 * 1000);
		})();
		</script>
		<?php
	}

	/* ----- handlers ----- */

	public static function handle_generate() {
		self::guard( 'verlo_brief_generate' );
		$aid = (int) ( $_POST['article_id'] ?? 0 );
		$res = Verlo_Strategist::build_brief( $aid );
		if ( is_wp_error( $res ) ) { self::redirect( 'Brief failed: ' . $res->get_error_message(), true ); }
		self::redirect_to_brief( $aid, 'Brief generated. Review and edit below.' );
	}

	public static function handle_generate_next() {
		self::guard( 'verlo_brief_generate_next' );
		$next = Verlo_Strategist::pick_next();
		if ( ! $next ) { self::redirect( 'Every planned article already has a brief.' ); }
		$res = Verlo_Strategist::build_brief( $next['id'] );
		if ( is_wp_error( $res ) ) { self::redirect( 'Brief failed: ' . $res->get_error_message(), true ); }
		self::redirect_to_brief( $next['id'], 'Brief generated for "' . $next['keyword'] . '". Review below.' );
	}

	public static function handle_save() {
		self::guard( 'verlo_brief_save' );
		$aid = (int) ( $_POST['article_id'] ?? 0 );
		$b   = Verlo_Brief::get( $aid );
		if ( ! $b ) { self::redirect( 'Brief not found.', true ); }

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
		$b['word_count']      = max( 300, (int) ( $_POST['word_count'] ?? 1500 ) );
		$b['voice_note']      = sanitize_textarea_field( wp_unslash( $_POST['voice_note'] ?? '' ) );
		$b['meta']['updated_at'] = time();

		Verlo_Brief::save( $aid, $b );

		// "Save & next": save this brief, then generate the next one and open it.
		if ( 'next' === ( $_POST['then'] ?? '' ) ) {
			$prefix = empty( $dropped ) ? 'Brief saved. ' : 'Brief saved (removed ' . count( $dropped ) . ' off-site link(s)). ';
			$next   = Verlo_Strategist::pick_next();
			if ( ! $next ) {
				self::redirect_to_brief( $aid, $prefix . 'Every planned article now has a brief.' );
			}
			$res = Verlo_Strategist::build_brief( $next['id'] );
			if ( is_wp_error( $res ) ) {
				self::redirect_to_brief( $aid, $prefix . 'Could not start the next brief: ' . $res->get_error_message(), true );
			}
			self::redirect_to_brief( $next['id'], 'Brief generated for "' . $next['keyword'] . '". Review below.' );
		}

		if ( ! empty( $dropped ) ) {
			$shown = array_slice( $dropped, 0, 3 );
			$more  = count( $dropped ) > 3 ? ' and ' . ( count( $dropped ) - 3 ) . ' more' : '';
			self::redirect_to_brief( $aid, 'Brief saved. Removed ' . count( $dropped ) . ' link(s) not on your site: ' . implode( ', ', $shown ) . $more . '. Internal links must point to your own domain.', true );
		}
		self::redirect_to_brief( $aid, 'Brief saved.' );
	}

	public static function handle_delete() {
		self::guard( 'verlo_brief_delete' );
		$aid = (int) ( $_POST['article_id'] ?? 0 );
		Verlo_Brief::delete( $aid );
		self::redirect( 'Brief deleted.' );
	}

	public static function handle_generate_article() {
		self::guard( 'verlo_brief_generate_article' );
		$aid = (int) ( $_POST['article_id'] ?? 0 );
		$res = Verlo_Generator::queue_draft( $aid );
		if ( is_wp_error( $res ) ) {
			self::redirect_to_brief( $aid, 'Could not start generation: ' . $res->get_error_message(), true );
		}
		// Return immediately; the brief page shows a live progress state and
		// polls for completion, so the browser never waits on the API call.
		self::redirect_to_brief( $aid, '__generating__' );
	}

	protected static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce ) ) {
			wp_die( 'Permission denied.' );
		}
	}

	protected static function redirect( $notice, $is_error = false ) {
		$args = array( 'page' => 'verlo-briefs', 'verlo_notice' => rawurlencode( $notice ) );
		if ( $is_error ) { $args['verlo_error'] = 1; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	protected static function redirect_to_brief( $aid, $notice, $is_error = false ) {
		$args = array(
			'page'         => 'verlo-briefs',
			'verlo_brief'  => (int) $aid,
			'verlo_notice' => rawurlencode( $notice ),
		);
		if ( $is_error ) { $args['verlo_error'] = 1; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
