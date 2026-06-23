<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin page: Logs. Surfaces the technical event log (AI calls, generation
 * attempts, errors including Anthropic credit/rate-limit problems and host/
 * security-plugin blocks) so issues can be diagnosed rather than guessed at.
 */
class Verlo_Log_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_verlo_log_clear', array( __CLASS__, 'handle_clear' ) );
		add_action( 'admin_post_verlo_log_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function menu() {
		add_submenu_page( 'verlo', 'Logs', 'Logs', 'manage_options', 'verlo-logs', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$filter = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : '';
		$rows   = Verlo_Log::recent( 300 );
		if ( $filter && in_array( $filter, array( 'error', 'warn', 'info', 'debug' ), true ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $filter ) {
				return ( $r['level'] ?? '' ) === $filter;
			} ) );
		}

		$counts = array( 'error' => 0, 'warn' => 0, 'info' => 0, 'debug' => 0 );
		foreach ( Verlo_Log::all() as $r ) {
			$lvl = $r['level'] ?? 'info';
			if ( isset( $counts[ $lvl ] ) ) { $counts[ $lvl ]++; }
		}

		$base    = admin_url( 'admin.php?page=verlo-logs' );
		$tz      = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
		$notice  = isset( $_GET['verlo_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['verlo_notice'] ) ) : '';
		?>
		<div class="wrap verlo-wrap">
			<h1>Logs</h1>
			<p style="margin-top:2px;color:#646970;">Technical events from Verlo: generation attempts, calls, and errors (including API credit, rate limits, timeouts, and security-plugin blocks). The most recent <?php echo (int) Verlo_Log::MAX_ROWS; ?> events are kept.</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 0;">
				<a class="button <?php echo '' === $filter ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $base ); ?>">All (<?php echo (int) Verlo_Log::count(); ?>)</a>
				<a class="button <?php echo 'error' === $filter ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'level', 'error', $base ) ); ?>">Errors (<?php echo (int) $counts['error']; ?>)</a>
				<a class="button <?php echo 'warn' === $filter ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'level', 'warn', $base ) ); ?>">Warnings (<?php echo (int) $counts['warn']; ?>)</a>
				<a class="button <?php echo 'info' === $filter ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'level', 'info', $base ) ); ?>">Info (<?php echo (int) $counts['info']; ?>)</a>

				<span style="flex:1"></span>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="verlo_log_export" />
					<?php wp_nonce_field( 'verlo_log_export' ); ?>
					<button type="submit" class="button">Download as JSON</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Clear all logged events? This cannot be undone.');">
					<input type="hidden" name="action" value="verlo_log_clear" />
					<?php wp_nonce_field( 'verlo_log_clear' ); ?>
					<button type="submit" class="button" style="color:#b32d2e;">Clear log</button>
				</form>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<div class="verlo-card"><p class="verlo-sub" style="margin:0;">No events logged yet<?php echo $filter ? ' at this level' : ''; ?>. Events appear here as Verlo runs (e.g. when you generate a brief or an article).</p></div>
			<?php else : ?>
				<table class="widefat striped verlo-log-table">
					<thead>
						<tr>
							<th style="width:150px;">Time</th>
							<th style="width:70px;">Level</th>
							<th style="width:130px;">Event</th>
							<th>Message</th>
							<th style="width:90px;">Run</th>
							<th style="width:34px;"></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// Assign each generation run a stable colour so its rows are
						// visually grouped even in the flat chronological list.
						$run_palette = array( '#1b5e9e', '#1a7f37', '#8250df', '#bc4c00', '#0969da', '#a40e26', '#6e7781' );
						$run_colors  = array();
						$run_labels  = array();
						$next_color  = 0;
						$run_seq     = 0;
						foreach ( array_reverse( $rows ) as $r ) {
							$rid = $r['context']['run_id'] ?? '';
							if ( $rid && ! isset( $run_colors[ $rid ] ) ) {
								$run_colors[ $rid ] = $run_palette[ $next_color % count( $run_palette ) ];
								$next_color++;
								$run_seq++;
								$run_labels[ $rid ] = 'Gen #' . $run_seq;
							}
						}
						?>
						<?php foreach ( $rows as $i => $r ) : ?>
							<?php
							$lvl   = $r['level'] ?? 'info';
							$color = 'error' === $lvl ? '#b32d2e' : ( 'warn' === $lvl ? '#9a6700' : ( 'debug' === $lvl ? '#646970' : '#1b5e9e' ) );
							$when  = $r['time'] ?? 0;
							$local = $when ? ( function_exists( 'wp_date' ) ? wp_date( 'M j, H:i:s', $when ) : gmdate( 'M j, H:i:s', $when ) ) : '';
							$ctx   = ! empty( $r['context'] ) ? $r['context'] : array();
							$rid   = $ctx['run_id'] ?? '';
							$rcol  = $rid && isset( $run_colors[ $rid ] ) ? $run_colors[ $rid ] : '';
							$rlbl  = $rid && isset( $run_labels[ $rid ] ) ? $run_labels[ $rid ] : '';
							$bstyle = $rcol ? 'box-shadow: inset 4px 0 0 ' . $rcol . ';' : '';
							?>
							<tr style="<?php echo esc_attr( $bstyle ); ?>">
								<td><?php echo esc_html( $local ); ?><br><span style="color:#888;font-size:11px;"><?php echo $when ? esc_html( human_time_diff( $when, time() ) ) . ' ago' : ''; ?></span></td>
								<td><span style="display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( strtoupper( $lvl ) ); ?></span></td>
								<td><code style="font-size:11px;"><?php echo esc_html( $r['event'] ?? '' ); ?></code></td>
								<td><?php echo esc_html( $r['message'] ?? '' ); ?></td>
								<td><?php if ( $rlbl ) : ?><span title="<?php echo esc_attr( $rid ); ?>" style="display:inline-block;padding:1px 7px;border-radius:4px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $rcol ); ?>;"><?php echo esc_html( $rlbl ); ?></span><?php endif; ?></td>
								<td>
									<?php if ( ! empty( $ctx ) ) : ?>
										<button type="button" class="button-link verlo-log-toggle" data-row="<?php echo (int) $i; ?>" style="text-decoration:none;">▸</button>
									<?php endif; ?>
								</td>
							</tr>
							<?php if ( ! empty( $ctx ) ) : ?>
								<tr id="verlo-log-ctx-<?php echo (int) $i; ?>" class="verlo-log-ctx" style="display:none;background:#f6f8fa;<?php echo esc_attr( $bstyle ); ?>">
									<td colspan="6">
										<table style="width:100%;border-collapse:collapse;font-size:12px;">
											<?php foreach ( $ctx as $k => $v ) : ?>
												<tr>
													<td style="width:170px;vertical-align:top;padding:3px 8px;color:#646970;font-weight:600;"><?php echo esc_html( $k ); ?></td>
													<td style="padding:3px 8px;font-family:monospace;word-break:break-word;"><?php echo esc_html( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ); ?></td>
												</tr>
											<?php endforeach; ?>
										</table>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="verlo-sub" style="margin-top:8px;color:#646970;font-size:12px;">Rows from the same article generation share a coloured “Gen #” tag and left edge, so you can follow one run end-to-end.</p>
			<?php endif; ?>
		</div>

		<style>
			.verlo-log-table td{ vertical-align:top; }
			.verlo-log-table code{ background:transparent; padding:0; }
		</style>
		<script>
		(function(){
			document.querySelectorAll('.verlo-log-toggle').forEach(function(btn){
				btn.addEventListener('click', function(){
					var row = document.getElementById('verlo-log-ctx-' + this.getAttribute('data-row'));
					if(!row) return;
					var open = row.style.display !== 'none';
					row.style.display = open ? 'none' : 'table-row';
					this.textContent = open ? '▸' : '▾';
				});
			});
		})();
		</script>
		<?php
	}

	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'verlo_log_clear' ) ) {
			wp_die( 'Permission denied.' );
		}
		Verlo_Log::clear();
		wp_safe_redirect( add_query_arg( 'verlo_notice', rawurlencode( 'Log cleared.' ), admin_url( 'admin.php?page=verlo-logs' ) ) );
		exit;
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'verlo_log_export' ) ) {
			wp_die( 'Permission denied.' );
		}
		$json = Verlo_Log::export_json();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="verlo-logs-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo $json; // already JSON
		exit;
	}
}
