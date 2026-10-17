<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Settings > Verlo: paste the connection ID and key, see whether it works, send a test, check for articles now. */
class Verlo_Admin {

	const SLUG = 'verlo';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_verlo_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_verlo_test', array( __CLASS__, 'test' ) );
		add_action( 'admin_post_verlo_check', array( __CLASS__, 'check' ) );
		add_action( 'admin_post_verlo_disconnect', array( __CLASS__, 'disconnect' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VERLO_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">Settings</a>' );
		return $links;
	}

	public static function menu() {
		add_options_page( 'Verlo', 'Verlo', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	protected static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'You do not have permission to do that.', 403 ); }
		check_admin_referer( $action );
	}

	protected static function back( $notice, $ok ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'verlo_notice' => rawurlencode( $notice ), 'verlo_ok' => $ok ? '1' : '0' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function save() {
		self::guard( 'verlo_save' );
		$id  = isset( $_POST['verlo_destination_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['verlo_destination_id'] ) ) ) : '';
		$key = isset( $_POST['verlo_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['verlo_key'] ) ) ) : '';
		if ( ! Verlo_Settings::valid_destination_id( $id ) ) { self::back( 'That connection ID does not look right. Copy it from the Connection tab in Verlo.', false ); }
		$current = Verlo_Settings::get();
		if ( '' === $key ) { $key = $current['key']; } // blank keeps the saved key
		if ( ! preg_match( '/^vlo_[A-Za-z0-9_-]{20,80}$/', $key ) ) { self::back( 'That connection key does not look right. It starts with vlo_.', false ); }
		Verlo_Settings::update( array( 'destination_id' => $id, 'key' => $key, 'last_error' => '' ) );
		Verlo_Poller::activate();
		$r = Verlo_Poller::test();
		self::back( $r['ok'] ? 'Saved. ' . $r['message'] : 'Saved, but: ' . $r['message'], $r['ok'] );
	}

	public static function test() {
		self::guard( 'verlo_test' );
		$r = Verlo_Poller::test();
		self::back( $r['message'], $r['ok'] );
	}

	public static function check() {
		self::guard( 'verlo_check' );
		$r = Verlo_Poller::run();
		self::back( $r['message'], $r['ok'] );
	}

	public static function disconnect() {
		self::guard( 'verlo_disconnect' );
		delete_option( VERLO_OPT_CONNECTION );
		Verlo_Poller::deactivate();
		self::back( 'Disconnected. Posts already published stay on your site.', true );
	}

	protected static function ago( $ts ) {
		return $ts ? sprintf( '%s ago', human_time_diff( (int) $ts, time() ) ) : 'never';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s         = Verlo_Settings::get();
		$connected = Verlo_Settings::is_connected();
		$notice    = isset( $_GET['verlo_notice'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['verlo_notice'] ) ) ) : '';
		$notice_ok = isset( $_GET['verlo_ok'] ) && '1' === $_GET['verlo_ok'];
		?>
		<div class="wrap">
			<h1>Verlo</h1>
			<p>Verlo plans, writes and edits your articles at <a href="https://app.verlohub.com" target="_blank" rel="noopener">app.verlohub.com</a>. This plugin receives the finished, approved articles and creates or updates the post here.</p>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice <?php echo $notice_ok ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<h2>Connection</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="verlo_save">
				<?php wp_nonce_field( 'verlo_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="verlo_destination_id">Connection ID</label></th>
						<td><input type="text" id="verlo_destination_id" name="verlo_destination_id" class="regular-text code" value="<?php echo esc_attr( $s['destination_id'] ); ?>" autocomplete="off" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="verlo_key">Connection key</label></th>
						<td>
							<input type="password" id="verlo_key" name="verlo_key" class="regular-text code" value="" autocomplete="new-password" placeholder="<?php echo $connected ? esc_attr( 'Saved, ending ' . Verlo_Settings::key_hint() . '. Leave blank to keep it.' ) : 'vlo_...'; ?>" <?php echo $connected ? '' : 'required'; ?>>
							<p class="description">Find both in Verlo, on your project's Connection tab. The key is shown once there; make a new one any time.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( $connected ? 'Save and test' : 'Connect', 'primary', 'submit', false ); ?>
			</form>

			<?php if ( $connected ) : ?>
				<h2 style="margin-top:2em">Status</h2>
				<table class="widefat striped" style="max-width:720px">
					<tbody>
						<tr><td>Last contact with Verlo</td><td><?php echo esc_html( self::ago( $s['last_contact'] ) ); ?></td></tr>
						<tr><td>Last successful contact</td><td><?php echo esc_html( self::ago( $s['last_ok'] ) ); ?></td></tr>
						<tr><td>Last article received</td><td><?php echo ! empty( $s['last_article']['title'] ) ? esc_html( $s['last_article']['title'] . ' (' . $s['last_article']['status'] . ', ' . self::ago( $s['last_article']['at'] ) . ')' ) : 'none yet'; ?></td></tr>
						<tr><td>Last problem</td><td><?php echo '' !== $s['last_error'] ? esc_html( $s['last_error'] ) : 'none'; ?></td></tr>
						<tr><td>Address Verlo sends to</td><td><code><?php echo esc_html( rest_url( 'verlo/v1/publish' ) ); ?></code></td></tr>
						<tr><td>SEO plugin found</td><td><?php echo esc_html( Verlo_Rest::seo_plugin() ); ?></td></tr>
						<tr><td>Next automatic check</td><td><?php $n = wp_next_scheduled( VERLO_HOOK_POLL ); echo $n ? esc_html( 'in ' . human_time_diff( time(), $n ) ) : 'not scheduled'; ?></td></tr>
					</tbody>
				</table>
				<p style="margin-top:1em">
					<?php foreach ( array( 'verlo_test' => 'Send test', 'verlo_check' => 'Check for articles now' ) as $action => $label ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
							<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
							<?php wp_nonce_field( $action ); ?>
							<?php submit_button( $label, 'secondary', 'submit', false ); ?>
						</form>
					<?php endforeach; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block" onsubmit="return confirm('Disconnect this site from Verlo? Posts already published stay.');">
						<input type="hidden" name="action" value="verlo_disconnect">
						<?php wp_nonce_field( 'verlo_disconnect' ); ?>
						<?php submit_button( 'Disconnect', 'delete', 'submit', false ); ?>
					</form>
				</p>
				<p class="description">WordPress runs its schedule when the site gets visitors. For exact timing, ask your host to run WP-Cron on a real schedule.</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
