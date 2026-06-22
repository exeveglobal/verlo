<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin page for the Site Strategy Profile and the Verlo connection.
 * UX notes:
 *  - Connect form verifies the license key against the Verlo SaaS and stores
 *    the resulting JWT; the key itself is never re-displayed.
 *  - Failure notices carry action links instead of dead-ends.
 */
class Verlo_Profile_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_verlo_connection', array( __CLASS__, 'handle_connection' ) );
		add_action( 'admin_post_verlo_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_verlo_save_profile', array( __CLASS__, 'handle_save_profile' ) );
		add_action( 'admin_post_verlo_analyze', array( __CLASS__, 'handle_analyze' ) );
		add_action( 'admin_post_verlo_export_profile', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_verlo_import_profile', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'admin_footer', array( __CLASS__, 'progress_overlay' ) );
	}

	/**
	 * Plugin-wide progress overlay. Any form whose submit button carries
	 * data-verlo-progress="message" shows a full-card spinner + indeterminate
	 * bar on submit, so a synchronous AI request reads as "working" rather than
	 * a frozen page. The overlay clears automatically when the redirect lands.
	 */
	public static function progress_overlay() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'verlo' ) ) { return; }
		?>
		<div id="verlo-overlay" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(255,255,255,.82);backdrop-filter:saturate(1) blur(1px);">
			<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;min-width:300px;background:#fff;border:1px solid #e3e5e8;border-radius:12px;padding:26px 30px;box-shadow:0 8px 30px rgba(16,24,40,.12);">
				<div class="verlo-spinner" style="width:34px;height:34px;margin:0 auto 14px;border:3px solid #e3e5e8;border-top-color:#2271b1;border-radius:50%;animation:verlo-spin .8s linear infinite;"></div>
				<div id="verlo-overlay-msg" style="font-size:14px;font-weight:600;color:#1d2327;">Working…</div>
				<div style="margin-top:14px;height:6px;width:220px;border-radius:999px;background:#eef0f2;overflow:hidden;">
					<div style="height:100%;width:40%;border-radius:999px;background:#2271b1;animation:verlo-bar 1.1s ease-in-out infinite;"></div>
				</div>
				<div style="margin-top:10px;font-size:12px;color:#646970;">This can take a few seconds — please keep this tab open.</div>
			</div>
		</div>
		<style>
			@keyframes verlo-spin{to{transform:rotate(360deg);}}
			@keyframes verlo-bar{0%{margin-left:-40%;}100%{margin-left:100%;}}
		</style>
		<script>
		(function(){
			var overlay = document.getElementById('verlo-overlay');
			var msgEl   = document.getElementById('verlo-overlay-msg');
			if(!overlay) return;

			// Rolling sub-messages so the wait feels like the algorithm is working.
			var PHASES = {
				analyze: ['Reading your site content…','Spotting your core topics…','Profiling your audience…','Inferring tone and voice…','Summarising your niche…'],
				map: ['Reviewing your content profile…','Clustering topics into pillars…','Finding content gaps…','Drafting planned articles…','Checking what you already cover…','Organising the roadmap…'],
				brief: ['Reading the planned article…','Studying search intent…','Shaping the angle…','Outlining the sections…','Finding internal links…','Planning the FAQ…'],
				generic: ['Working…','Thinking it through…','Putting it together…','Almost there…']
			};
			var roll = null;
			function startRolling(kind){
				var list = PHASES[kind] || PHASES.generic;
				var i = 0;
				var started = Date.now();
				function elapsed(){
					var s = Math.floor((Date.now()-started)/1000);
					return s < 60 ? s+'s' : Math.floor(s/60)+'m '+(s%60)+'s';
				}
				msgEl.textContent = list[0];
				roll = setInterval(function(){
					if(i < list.length - 1){
						i++;
						msgEl.style.opacity = 0;
						setTimeout(function(){ msgEl.textContent = list[i]; msgEl.style.opacity = 1; }, 180);
					} else {
						// Hold on an honest waiting state with a live timer rather
						// than looping the same phrases.
						msgEl.textContent = 'Still working (' + elapsed() + ' elapsed)…';
					}
				}, 3000);
			}

			document.addEventListener('submit', function(e){
				var form = e.target;
				if(!form || form.nodeName !== 'FORM') return;
				var btn = form.querySelector('[data-verlo-progress]');
				if(!btn) {
					var active = document.activeElement;
					if(active && active.hasAttribute && active.hasAttribute('data-verlo-progress')) btn = active;
				}
				if(!btn) return;
				if(typeof form.checkValidity === 'function' && !form.checkValidity()) return;
				msgEl.style.transition = 'opacity .2s';
				msgEl.textContent = btn.getAttribute('data-verlo-progress') || 'Working…';
				startRolling(btn.getAttribute('data-verlo-phases') || 'generic');
				overlay.style.display = 'block';
			}, true);
			window.addEventListener('pageshow', function(){
				overlay.style.display = 'none';
				if(roll){ clearInterval(roll); roll = null; }
			});
		})();
		</script>
		<?php
	}

	public static function menu() {
		add_submenu_page(
			'verlo',
			'Strategy Profile',
			'Strategy Profile',
			'manage_options',
			'verlo-profile',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Lightweight modern styling for our pages only (no external assets).
	 */
	public static function styles( $hook ) {
		if ( false === strpos( (string) $hook, 'verlo' ) ) { return; }
		$css = '
		.verlo-wrap{max-width:980px;}
		.verlo-grid{display:grid;grid-template-columns:1fr;gap:18px;margin-top:14px;}
		@media(min-width:1100px){.verlo-grid{grid-template-columns:1fr 1fr;}}
		.verlo-card{background:#fff;border:1px solid #e3e5e8;border-radius:10px;padding:20px 22px;box-shadow:0 1px 2px rgba(16,24,40,.04);}
		.verlo-card h2{margin:0 0 4px;font-size:15px;display:flex;align-items:center;gap:8px;}
		.verlo-card .verlo-sub{color:#646970;margin:0 0 14px;font-size:12.5px;}
		.verlo-card .form-table th{width:160px;padding:10px 10px 10px 0;font-weight:500;}
		.verlo-card .form-table td{padding:8px 0;}
		.verlo-field input[type=text],.verlo-field input[type=password],.verlo-field textarea,.verlo-field select{width:100%;max-width:560px;border-radius:6px;}
		.verlo-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;}
		.verlo-badge.ok{background:#e7f6ee;color:#157347;}
		.verlo-badge.warn{background:#fdf3e1;color:#9a6700;}
		.verlo-badge.off{background:#f0f0f1;color:#646970;}
		.verlo-badge.info{background:#e6f0fb;color:#1b5e9e;}
		.verlo-badge.review{background:#fde8ec;color:#a3334b;}
		.verlo-badge.scheduled{background:#e9f3f1;color:#0f6e63;}
		.verlo-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px;}
		.verlo-meta{color:#646970;font-size:12px;margin-top:10px;}
		.verlo-card-full{margin-top:18px;}
		';
		wp_register_style( 'verlo-admin', false, array(), VERLO_VERSION );
		wp_enqueue_style( 'verlo-admin' );
		wp_add_inline_style( 'verlo-admin', $css );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s         = verlo_get_settings();
		$p         = Verlo_Profile::get();
		$models    = Verlo_Profile::monetization_models();
		$notice    = isset( $_GET['verlo_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['verlo_notice'] ) ) : '';
		$is_error  = isset( $_GET['verlo_error'] );
		$link_kg   = isset( $_GET['verlo_link_kg'] );
		$url       = admin_url( 'admin-post.php' );
		$kg_url    = admin_url( 'admin.php?page=verlo' );
		$connected = Verlo_Auth::is_connected();
		$complete  = Verlo_Profile::is_complete();
		?>
		<div class="wrap verlo-wrap">
			<h1>Site Strategy Profile</h1>
			<p style="margin-top:2px;color:#646970;">The one-time configuration that drives keyword, tone, intent, and structure decisions for this site.</p>

			<?php if ( $notice ) : ?>
				<div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible">
					<p>
						<?php echo esc_html( $notice ); ?>
						<?php if ( $link_kg ) : ?>
							&nbsp;<a href="<?php echo esc_url( $kg_url ); ?>">Open the Knowledge Graph page to build it now →</a>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="verlo-grid">

				<!-- Verlo connection card -->
				<div class="verlo-card">
					<h2>
						Verlo connection
						<?php if ( $connected ) : ?>
							<span class="verlo-badge ok">Connected</span>
							<span class="verlo-badge info" style="font-size:11px;"><?php echo esc_html( ucfirst( Verlo_Auth::plan() ) ); ?> plan</span>
						<?php else : ?>
							<span class="verlo-badge off">Not connected</span>
						<?php endif; ?>
					</h2>

					<?php if ( $connected ) : ?>
						<p class="verlo-sub">Your license is active. Disconnect to enter a different license key.</p>
						<p class="verlo-meta" style="margin-bottom:12px;">
							Site ID: <code><?php echo esc_html( substr( Verlo_Auth::site_id(), 0, 8 ) . '…' ); ?></code>
						</p>
						<form method="post" action="<?php echo esc_url( $url ); ?>">
							<input type="hidden" name="action" value="verlo_disconnect" />
							<?php wp_nonce_field( 'verlo_disconnect' ); ?>
							<button type="submit" class="button button-secondary" onclick="return confirm('Disconnect Verlo? AI features will stop until you reconnect.');">Disconnect</button>
						</form>
					<?php else : ?>
						<p class="verlo-sub">Enter your Verlo license key to activate AI-powered site analysis and article generation.</p>
						<form method="post" action="<?php echo esc_url( $url ); ?>">
							<input type="hidden" name="action" value="verlo_connection" />
							<?php wp_nonce_field( 'verlo_connection' ); ?>
							<table class="form-table" role="presentation">
								<tr class="verlo-field"><th>License key</th><td>
									<input type="password" name="license_key" value="" autocomplete="off" placeholder="verlo-…" style="max-width:360px;" />
								</td></tr>
								<tr class="verlo-field"><th>Server URL <span style="font-weight:400;color:#646970;">(dev)</span></th><td>
									<input type="text" name="saas_url" value="<?php echo esc_attr( $s['saas_url'] ); ?>" placeholder="leave blank for production" style="max-width:360px;" />
									<p class="description">Leave blank in production. For local development: <code>http://localhost:3000</code></p>
								</td></tr>
							</table>
							<div class="verlo-actions">
								<button type="submit" class="button button-primary" data-verlo-progress="Connecting to Verlo…">Connect</button>
							</div>
						</form>
					<?php endif; ?>
				</div>

				<!-- Settings card -->
				<div class="verlo-card">
					<h2>Settings</h2>
					<p class="verlo-sub">Image and link settings for generated articles.</p>
					<form method="post" action="<?php echo esc_url( $url ); ?>">
						<input type="hidden" name="action" value="verlo_connection" />
						<input type="hidden" name="verlo_settings_only" value="1" />
						<?php wp_nonce_field( 'verlo_connection' ); ?>
						<table class="form-table" role="presentation">
							<tr class="verlo-field"><th>Trusted outbound domains</th><td>
								<textarea name="outbound_domains" rows="3" placeholder="one domain per line, e.g.&#10;akc.org&#10;mayoclinic.org"><?php echo esc_textarea( $s['outbound_domains'] ?? '' ); ?></textarea>
								<p class="description">Niche-specific sites generated articles may link out to (in addition to Wikipedia and .gov/.edu). Leave blank for universal authorities only.</p>
							</td></tr>
							<tr class="verlo-field"><th>Pexels API key</th><td>
								<input type="password" name="pexels_api_key" value="<?php echo esc_attr( $s['pexels_api_key'] ?? '' ); ?>" autocomplete="off" placeholder="free key from pexels.com/api" />
								<p class="description">Enables a featured image and in-body stock images. Leave blank to skip images.</p>
							</td></tr>
							<tr class="verlo-field"><th>In-body images (max)</th><td>
								<select name="inline_images">
									<?php $ic = (int) ( $s['inline_images'] ?? 1 ); ?>
									<?php foreach ( array( 0, 1, 2, 3 ) as $opt ) : ?>
										<option value="<?php echo $opt; ?>" <?php selected( $ic, $opt ); ?>><?php echo 0 === $opt ? 'None (featured image only)' : 'Up to ' . $opt . ( 1 === $opt ? ' image' : ' images' ); ?></option>
									<?php endforeach; ?>
								</select>
							</td></tr>
						</table>
						<div class="verlo-actions">
							<button type="submit" class="button">Save settings</button>
						</div>
					</form>
				</div>

				<!-- AI analysis card -->
				<div class="verlo-card">
					<h2>AI site analysis</h2>
					<p class="verlo-sub">Reads a low-token snapshot of your knowledge graph (sample titles + top vocabulary, never full posts) and proposes profile values for your review. One cheap call — nothing is final until you save.</p>
					<form method="post" action="<?php echo esc_url( $url ); ?>">
						<input type="hidden" name="action" value="verlo_analyze" />
						<?php wp_nonce_field( 'verlo_analyze' ); ?>
						<div class="verlo-actions">
							<button type="submit" class="button button-primary" data-verlo-progress="Analyzing your site with AI…" data-verlo-phases="analyze" <?php disabled( ! $connected ); ?>>Analyze my site with AI</button>
							<?php if ( ! $connected ) : ?>
								<span class="description">Connect your Verlo license first.</span>
							<?php endif; ?>
						</div>
					</form>
					<p class="verlo-meta">
						<?php
						if ( $p['meta']['inferred_at'] ) {
							echo 'Last AI analysis: ' . esc_html( human_time_diff( (int) $p['meta']['inferred_at'], time() ) ) . ' ago.';
						} else {
							echo 'No AI analysis run yet.';
						}
						?>
					</p>
				</div>
			</div>

			<!-- Profile card -->
			<div class="verlo-card verlo-card-full">
				<h2>
					Profile
					<?php if ( $complete ) : ?>
						<span class="verlo-badge ok">Complete</span>
					<?php else : ?>
						<span class="verlo-badge warn">Incomplete — niche, audience &amp; voice required</span>
					<?php endif; ?>
				</h2>
				<p class="verlo-sub">Fill manually or accept/edit the AI proposal, then save.</p>
				<form method="post" action="<?php echo esc_url( $url ); ?>">
					<input type="hidden" name="action" value="verlo_save_profile" />
					<?php wp_nonce_field( 'verlo_save_profile' ); ?>
					<table class="form-table" role="presentation">
						<tr class="verlo-field"><th>Site name</th><td><input type="text" name="site_name" value="<?php echo esc_attr( $p['site_name'] ); ?>" /></td></tr>
						<tr class="verlo-field"><th>Tagline</th><td><input type="text" name="tagline" value="<?php echo esc_attr( $p['tagline'] ); ?>" /></td></tr>
						<tr class="verlo-field"><th>Niche</th><td><input type="text" name="niche" value="<?php echo esc_attr( $p['niche'] ); ?>" placeholder="what this site is about" /></td></tr>
						<tr class="verlo-field"><th>Monetization model</th><td>
							<select name="monetization_model">
								<?php foreach ( $models as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $p['monetization_model'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">The master switch — it changes keyword, tone, and volume strategy.</p>
						</td></tr>
						<tr class="verlo-field"><th>Audience</th><td><textarea name="audience" rows="3" placeholder="who they are and what they need"><?php echo esc_textarea( $p['audience'] ); ?></textarea></td></tr>
						<tr class="verlo-field"><th>Voice</th><td><textarea name="voice" rows="2" placeholder="tone and style"><?php echo esc_textarea( $p['voice'] ); ?></textarea></td></tr>
						<tr class="verlo-field"><th>Language</th><td><input type="text" name="language" value="<?php echo esc_attr( $p['language'] ); ?>" style="max-width:120px;" /></td></tr>
						<tr class="verlo-field"><th>Geo target</th><td><input type="text" name="geo" value="<?php echo esc_attr( $p['geo'] ); ?>" placeholder="e.g. US, UK, global" style="max-width:240px;" /></td></tr>
						<tr class="verlo-field"><th>Constraints</th><td><textarea name="constraints" rows="2" placeholder="topics to avoid, compliance notes"><?php echo esc_textarea( $p['constraints'] ); ?></textarea></td></tr>
					</table>
					<div class="verlo-actions"><?php submit_button( 'Save profile', 'primary', 'submit', false ); ?></div>
				</form>
				<p class="verlo-meta">
					<?php
					if ( $p['meta']['updated_at'] ) {
						echo 'Last saved ' . esc_html( human_time_diff( (int) $p['meta']['updated_at'], time() ) ) . ' ago.';
					}
					?>
				</p>
			</div>

			<!-- Export / import card -->
			<div class="verlo-card verlo-card-full">
				<h2>Export / Import</h2>
				<p class="verlo-sub">Reuse this profile as a template on your other sites.</p>
				<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline;">
					<input type="hidden" name="action" value="verlo_export_profile" />
					<?php wp_nonce_field( 'verlo_export_profile' ); ?>
					<?php submit_button( 'Download profile JSON', 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( $url ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="verlo_import_profile" />
					<?php wp_nonce_field( 'verlo_import_profile' ); ?>
					<textarea name="profile_json" rows="5" class="large-text code" placeholder="paste profile JSON here"></textarea>
					<div class="verlo-actions"><?php submit_button( 'Import profile JSON', 'secondary', 'submit', false ); ?></div>
				</form>
			</div>
		</div>
		<?php
	}

	/* ----- handlers ----- */

	/**
	 * Handles both the license-key connect form and the settings-only save form.
	 * When verlo_settings_only=1, only saves outbound_domains/pexels/inline_images.
	 */
	public static function handle_connection() {
		self::guard( 'verlo_connection' );

		$domains = sanitize_textarea_field( wp_unslash( $_POST['outbound_domains'] ?? '' ) );
		$pexels  = sanitize_text_field( wp_unslash( $_POST['pexels_api_key'] ?? '' ) );
		$inline  = max( 0, min( 3, (int) ( $_POST['inline_images'] ?? 1 ) ) );
		$saas_url = esc_url_raw( wp_unslash( $_POST['saas_url'] ?? '' ) );

		// Settings-only save (from the Settings card — no license key involved).
		if ( ! empty( $_POST['verlo_settings_only'] ) ) {
			$s = verlo_get_settings();
			$s['outbound_domains'] = $domains;
			$s['pexels_api_key']   = $pexels;
			$s['inline_images']    = $inline;
			update_option( VERLO_OPT_SETTINGS, $s, 'no' );
			self::redirect( 'Settings saved.' );
		}

		// License-key connect.
		$license_key = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		if ( '' === $license_key ) {
			self::redirect( 'Enter a license key first.', true );
		}

		// Persist the saas_url override first so Verlo_Auth::verify() uses it.
		$s = verlo_get_settings();
		$s['saas_url']         = $saas_url;
		$s['outbound_domains'] = $domains;
		$s['pexels_api_key']   = $pexels;
		$s['inline_images']    = $inline;
		update_option( VERLO_OPT_SETTINGS, $s, 'no' );

		$res = Verlo_Auth::verify( $license_key );
		if ( is_wp_error( $res ) ) {
			self::redirect( 'Connection failed: ' . $res->get_error_message(), true );
		}

		$plan = isset( $res['plan'] ) ? ucfirst( (string) $res['plan'] ) : 'active';
		self::redirect( 'Connected! Verlo is active (' . $plan . ' plan).' );
	}

	/** Disconnect and clear all stored auth data. */
	public static function handle_disconnect() {
		self::guard( 'verlo_disconnect' );
		Verlo_Auth::disconnect();
		self::redirect( 'Verlo disconnected.' );
	}

	public static function handle_save_profile() {
		self::guard( 'verlo_save_profile' );
		Verlo_Profile::save( wp_unslash( $_POST ), 'manual' );
		self::redirect( 'Profile saved.' );
	}

	public static function handle_analyze() {
		self::guard( 'verlo_analyze' );
		$proposed = Verlo_Profile::infer();
		if ( is_wp_error( $proposed ) ) {
			$msg     = 'Analysis failed: ' . $proposed->get_error_message();
			$link_kg = ( 'verlo_no_content' === $proposed->get_error_code() );
			self::redirect( $msg, true, $link_kg );
		}
		Verlo_Profile::save( $proposed, 'inferred' );
		self::redirect( 'AI proposed values from your content — review the fields below and Save profile.' );
	}

	public static function handle_export() {
		self::guard( 'verlo_export_profile' );
		$json = Verlo_Profile::export_json();
		$name = sanitize_file_name( 'verlo-profile-' . wp_parse_url( home_url(), PHP_URL_HOST ) . '.json' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo $json; // already JSON
		exit;
	}

	public static function handle_import() {
		self::guard( 'verlo_import_profile' );
		$res = Verlo_Profile::import_json( wp_unslash( $_POST['profile_json'] ?? '' ) );
		if ( is_wp_error( $res ) ) {
			self::redirect( 'Import failed: ' . $res->get_error_message(), true );
		}
		self::redirect( 'Profile imported.' );
	}

	protected static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce ) ) {
			wp_die( 'Permission denied.' );
		}
	}

	protected static function redirect( $notice, $is_error = false, $link_kg = false ) {
		$args = array( 'page' => 'verlo-profile', 'verlo_notice' => rawurlencode( $notice ) );
		if ( $is_error ) { $args['verlo_error'] = 1; }
		if ( $link_kg ) { $args['verlo_link_kg'] = 1; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
