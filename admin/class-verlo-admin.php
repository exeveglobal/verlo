<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin UI for inspecting and rebuilding the knowledge graph, plus a tester so
 * you can eyeball match quality before any content layer is built on top.
 */
class Verlo_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_verlo_rebuild', array( __CLASS__, 'handle_rebuild' ) );
		add_action( 'admin_post_verlo_save_types', array( __CLASS__, 'handle_save_types' ) );
		add_action( 'admin_post_verlo_clear_graph', array( __CLASS__, 'handle_clear_graph' ) );
		add_action( 'admin_post_verlo_clear_history', array( __CLASS__, 'handle_clear_history' ) );
	}

	public static function menu() {
		add_menu_page(
			'Verlo',
			'Verlo',
			'manage_options',
			'verlo',
			array( __CLASS__, 'render' ),
			self::menu_icon(),
			81
		);
	}

	/**
	 * Verlo's sidebar icon: a simple checkmark mark (the human-approval step is
	 * core to the product), rendered as a monochrome SVG data URI so it inherits
	 * the standard WordPress admin-menu icon styling and color treatment.
	 */
	public static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
			. '<path fill="#a7aaad" d="M2.2 3.6a1 1 0 0 1 1.39-.26l.12.1L10 9.86l6.29-6.42a1 1 0 0 1 1.5 1.32l-.08.1-7 7.14a1 1 0 0 1-1.34.08l-.1-.08-7-7.14a1 1 0 0 1-.07-1.3Z"/>'
			. '<circle cx="10" cy="16.6" r="1.3" fill="#a7aaad"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$stats     = Verlo_Knowledge_Graph::stats();
		$progress  = Verlo_Rebuild::progress();
		$selected  = verlo_target_post_types();
		$available = get_post_types( array( 'public' => true ), 'objects' );
		unset( $available['attachment'] );
		$notice    = isset( $_GET['verlo_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['verlo_notice'] ) ) : '';
		$test_q    = isset( $_GET['verlo_test'] ) ? sanitize_text_field( wp_unslash( $_GET['verlo_test'] ) ) : '';
		$running   = ! empty( $progress['running'] );
		$pct       = ( $progress['total'] > 0 ) ? round( ( $progress['done'] / $progress['total'] ) * 100 ) : 0;
		?>
		<div class="wrap verlo-wrap">
			<h1>Verlo — Knowledge Graph</h1>
			<p style="margin-top:2px;color:#646970;">The site's content index: powers internal linking, topic-gap analysis, and AI site analysis.</p>
			<?php if ( $running ) : ?>
				<script>setTimeout(function(){ location.reload(); }, 3000);</script>
			<?php endif; ?>
			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div class="verlo-card" style="margin-top:14px;">
				<h2>
					Status
					<?php if ( $running ) : ?>
						<span class="verlo-badge warn">Building</span>
					<?php elseif ( (int) $stats['objects'] > 0 ) : ?>
						<span class="verlo-badge ok">Ready</span>
					<?php else : ?>
						<span class="verlo-badge off">Empty</span>
					<?php endif; ?>
				</h2>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr><td><strong>Objects indexed</strong></td><td><?php echo (int) $stats['objects']; ?></td></tr>
					<tr><td><strong>Terms stored</strong></td><td><?php echo (int) $stats['terms']; ?></td></tr>
					<tr><td><strong>By type</strong></td><td>
						<?php
						if ( empty( $stats['by_type'] ) ) {
							echo '—';
						} else {
							$parts = array();
							foreach ( $stats['by_type'] as $row ) {
								$parts[] = esc_html( $row['type'] ) . ': ' . (int) $row['n'];
							}
							echo esc_html( implode( ', ', $parts ) );
						}
						?>
					</td></tr>
					<tr><td><strong>Rebuild</strong></td><td>
						<?php if ( $running ) : ?>
							<div style="background:#e0e0e0;border-radius:4px;height:18px;width:300px;max-width:100%;overflow:hidden;">
								<div style="background:#2271b1;height:18px;width:<?php echo (int) $pct; ?>%;"></div>
							</div>
							<p style="margin:6px 0 0;">Indexing <?php echo (int) $progress['done']; ?> / <?php echo (int) $progress['total']; ?> (<?php echo (int) $pct; ?>%) — this page updates automatically.</p>
						<?php else : ?>
							Idle<?php echo ( $progress['total'] > 0 ) ? ' — last build indexed ' . (int) $progress['total'] . ' objects.' : ' — no published content found yet.'; ?>
						<?php endif; ?>
					</td></tr>
					<tr><td><strong>Current data</strong></td><td>
						<?php
						if ( (int) $stats['objects'] === 0 && ! $running ) {
							echo 'Graph is empty — run a rebuild.';
						} else {
							$current = Verlo_Build_Log::current_build();
							if ( $current ) {
								printf(
									'From build #%d (%s), finished %s ago.',
									(int) $current['id'],
									wp_kses_post( self::status_badge( $current['status'] ) ),
									esc_html( human_time_diff( (int) $current['finished_at'], time() ) )
								);
								$last_edit = (int) $stats['last_edit'];
								if ( $last_edit && $last_edit > (int) $current['finished_at'] ) {
									printf(
										' <strong>Edited since</strong> — last content change %s ago, so the graph has drifted from this build.',
										esc_html( human_time_diff( $last_edit, time() ) )
									);
								}
							} else {
								echo '—';
							}
						}
						?>
					</td></tr>
				</tbody>
			</table>
			</div>

			<div class="verlo-card verlo-card-full">
			<h2>Indexed post types</h2>
			<p class="verlo-sub">Which content the graph reads — the full site (posts, pages, custom types) is recommended.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="verlo_save_types" />
				<?php wp_nonce_field( 'verlo_save_types' ); ?>
				<?php foreach ( $available as $type => $obj ) : ?>
					<label style="display:inline-block;margin-right:14px;">
						<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $selected, true ) ); ?> />
						<?php echo esc_html( $obj->labels->name ); ?> <code><?php echo esc_html( $type ); ?></code>
					</label>
				<?php endforeach; ?>
				<p><?php submit_button( 'Save post types', 'secondary', 'submit', false ); ?></p>
			</form>
			</div>

			<div class="verlo-card verlo-card-full">
			<h2>Rebuild &amp; maintenance</h2>
			<p class="verlo-sub">The graph is derived data — rebuilding from current content is always safe. Large sites continue in background batches.</p>
			<div class="verlo-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="verlo_rebuild" />
				<?php wp_nonce_field( 'verlo_rebuild' ); ?>
				<?php submit_button( 'Rebuild knowledge graph', 'primary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Clear all knowledge-graph data? The build history is kept. You can rebuild afterwards.');">
				<input type="hidden" name="action" value="verlo_clear_graph" />
				<?php wp_nonce_field( 'verlo_clear_graph' ); ?>
				<?php submit_button( 'Clear graph data', 'delete', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('Clear the build history log?');">
				<input type="hidden" name="action" value="verlo_clear_history" />
				<?php wp_nonce_field( 'verlo_clear_history' ); ?>
				<?php submit_button( 'Clear build history', 'secondary', 'submit', false ); ?>
			</form>
			</div>
			</div>

			<div class="verlo-card verlo-card-full">
			<h2>Build history</h2>
			<?php
			$runs = Verlo_Build_Log::all();
			if ( empty( $runs ) ) {
				echo '<p class="description">No builds recorded yet.</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:980px">';
				echo '<thead><tr><th>#</th><th>Trigger</th><th>Status</th><th>Indexed</th><th>OK / Failed</th><th>Started</th><th>Duration</th><th>Env</th><th>Details</th></tr></thead><tbody>';
				foreach ( $runs as $r ) {
					$dur = isset( $r['duration'] ) && null !== $r['duration'] ? (int) $r['duration'] . 's' : ( 'running' === $r['status'] ? '…' : '—' );
					echo '<tr>';
					echo '<td>' . (int) $r['id'] . '</td>';
					echo '<td><code>' . esc_html( $r['trigger'] ) . '</code></td>';
					echo '<td>' . wp_kses_post( self::status_badge( $r['status'] ) ) . '</td>';
					echo '<td>' . (int) $r['processed'] . ' / ' . (int) $r['total'] . '</td>';
					echo '<td>' . (int) $r['succeeded'] . ' / <strong>' . (int) $r['failed'] . '</strong></td>';
					echo '<td>' . esc_html( wp_date( 'M j, H:i', (int) $r['started_at'] ) ) . '</td>';
					echo '<td>' . esc_html( $dur ) . '</td>';
					$env = isset( $r['env'] ) ? $r['env'] : array();
					echo '<td><span title="' . esc_attr( 'plugin ' . ( $env['plugin'] ?? '?' ) ) . '">PHP ' . esc_html( $env['php'] ?? '?' ) . ' · WP ' . esc_html( $env['wp'] ?? '?' ) . ( isset( $r['peak_memory'] ) && $r['peak_memory'] ? ' · ' . esc_html( $r['peak_memory'] ) : '' ) . '</span></td>';
					echo '<td>';
					if ( ! empty( $r['errors'] ) ) {
						echo '<details><summary>' . count( $r['errors'] ) . ' error(s)</summary><div style="max-height:180px;overflow:auto;font-family:monospace;font-size:11px;line-height:1.5;margin-top:6px;">';
						foreach ( $r['errors'] as $e ) {
							echo 'object #' . (int) $e['object_id'] . ': ' . esc_html( $e['message'] ) . '<br>';
						}
						echo '</div></details>';
					} else {
						echo '—';
					}
					echo '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
			?>
			</div>

			<div class="verlo-card verlo-card-full">
			<h2>Test related-content matching</h2>
			<p class="verlo-sub">Enter a topic to see which existing content the graph offers as internal-link candidates — the matching the content strategist relies on.</p>
			<form method="get" action="">
				<input type="hidden" name="page" value="verlo" />
				<input type="text" name="verlo_test" value="<?php echo esc_attr( $test_q ); ?>" class="regular-text" placeholder="e.g. best running shoes for flat feet" />
				<?php submit_button( 'Find related', 'secondary', 'submit', false ); ?>
			</form>

			<?php
			if ( '' !== $test_q ) {
				$results = Verlo_Knowledge_Graph::related_objects( $test_q, 10 );
				echo '<table class="widefat striped" style="max-width:760px;margin-top:10px">';
				echo '<thead><tr><th>Score</th><th>Title</th><th>Type</th><th>URL</th></tr></thead><tbody>';
				if ( empty( $results ) ) {
					echo '<tr><td colspan="4">No related content found. (Empty graph, or no term overlap — try a topic closer to existing content.)</td></tr>';
				} else {
					foreach ( $results as $r ) {
						printf(
							'<tr><td>%d</td><td>%s</td><td><code>%s</code></td><td><a href="%s" target="_blank" rel="noopener">%s</a></td></tr>',
							(int) $r['score'],
							esc_html( $r['title'] ),
							esc_html( $r['type'] ),
							esc_url( $r['url'] ),
							esc_html( wp_parse_url( $r['url'], PHP_URL_PATH ) )
						);
					}
				}
				echo '</tbody></table>';
			}
			?>
			</div>
		</div>
		<?php
	}

	public static function handle_rebuild() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'verlo_rebuild' ) ) {
			wp_die( 'Permission denied.' );
		}
		$progress = Verlo_Rebuild::trigger_from_admin( verlo_target_post_types() );
		$msg = ! empty( $progress['running'] )
			? sprintf( 'Rebuild started: %d / %d done, continuing in background.', (int) $progress['done'], (int) $progress['total'] )
			: sprintf( 'Rebuild complete: %d objects indexed.', (int) $progress['done'] );
		self::redirect( $msg );
	}

	public static function handle_save_types() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'verlo_save_types' ) ) {
			wp_die( 'Permission denied.' );
		}
		$types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
		// Keep only real public types.
		$valid = get_post_types( array( 'public' => true ), 'names' );
		$types = array_values( array_intersect( $types, $valid ) );
		if ( empty( $types ) ) { $types = verlo_default_post_types(); }
		update_option( VERLO_OPT_POST_TYPES, $types, 'no' );
		self::redirect( 'Post types saved. Rebuild to apply to the whole graph.' );
	}

	public static function handle_clear_graph() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'verlo_clear_graph' ) ) {
			wp_die( 'Permission denied.' );
		}
		Verlo_Rebuild::clear_graph();
		self::redirect( 'Knowledge graph data cleared. Rebuild when ready.' );
	}

	public static function handle_clear_history() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'verlo_clear_history' ) ) {
			wp_die( 'Permission denied.' );
		}
		Verlo_Build_Log::clear();
		self::redirect( 'Build history cleared.' );
	}

	/**
	 * Coloured status label for the history table.
	 */
	protected static function status_badge( $status ) {
		$map = array(
			'completed'             => array( 'Completed', '#198754' ),
			'completed_with_errors' => array( 'Completed with errors', '#b8860b' ),
			'failed'                => array( 'Failed', '#d63638' ),
			'running'               => array( 'Running', '#2271b1' ),
			'aborted'               => array( 'Aborted (superseded)', '#888' ),
		);
		$info = isset( $map[ $status ] ) ? $map[ $status ] : array( ucfirst( (string) $status ), '#555' );
		return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:11px;background:' . esc_attr( $info[1] ) . ';">' . esc_html( $info[0] ) . '</span>';
	}

	protected static function redirect( $notice ) {
		wp_safe_redirect( add_query_arg( 'verlo_notice', rawurlencode( $notice ), admin_url( 'admin.php?page=verlo' ) ) );
		exit;
	}
}
