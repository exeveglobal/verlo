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
		add_action( 'admin_post_verlo_connect_start', array( __CLASS__, 'handle_connect_start' ) );
		add_action( 'admin_post_verlo_connect_complete', array( __CLASS__, 'handle_connect_complete' ) );
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

		// All strings the inline script below needs, translated server-side and
		// handed over as one JSON blob — __()/esc_html__() are PHP-only, so a
		// raw JS string literal can't be wrapped directly; this is the
		// equivalent of wp_localize_script() for a script that's templated
		// inline rather than loaded from a registered handle.
		$phases_js = wp_json_encode( array(
			'analyze' => array(
				__( 'Reading your site content…', 'verlo' ),
				__( 'Spotting your core topics…', 'verlo' ),
				__( 'Profiling your audience…', 'verlo' ),
				__( 'Inferring tone and voice…', 'verlo' ),
				__( 'Summarising your niche…', 'verlo' ),
			),
			'map' => array(
				__( 'Reviewing your content profile…', 'verlo' ),
				__( 'Clustering topics into pillars…', 'verlo' ),
				__( 'Finding content gaps…', 'verlo' ),
				__( 'Drafting planned articles…', 'verlo' ),
				__( 'Checking what you already cover…', 'verlo' ),
				__( 'Organising the roadmap…', 'verlo' ),
			),
			'brief' => array(
				__( 'Reading the planned article…', 'verlo' ),
				__( 'Studying search intent…', 'verlo' ),
				__( 'Shaping the angle…', 'verlo' ),
				__( 'Outlining the sections…', 'verlo' ),
				__( 'Finding internal links…', 'verlo' ),
				__( 'Planning the FAQ…', 'verlo' ),
			),
			'generic' => array(
				__( 'Working…', 'verlo' ),
				__( 'Thinking it through…', 'verlo' ),
				__( 'Putting it together…', 'verlo' ),
				__( 'Almost there…', 'verlo' ),
			),
		) );
		$i18n_js = wp_json_encode( array(
			'working'        => __( 'Working…', 'verlo' ),
			/* translators: %s: elapsed time, e.g. "45s" or "2m 10s" */
			'stillWorking'   => __( 'Still working (%s elapsed)…', 'verlo' ),
			'done'           => __( 'Done.', 'verlo' ),
			'somethingWrong' => __( 'Something went wrong.', 'verlo' ),
		) );
		?>
		<div id="verlo-overlay" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(255,255,255,.82);backdrop-filter:saturate(1) blur(1px);">
			<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;min-width:300px;background:#fff;border:1px solid #e3e5e8;border-radius:12px;padding:26px 30px;box-shadow:0 8px 30px rgba(16,24,40,.12);">
				<div class="verlo-spinner" style="width:34px;height:34px;margin:0 auto 14px;border:3px solid #e3e5e8;border-top-color:#2271b1;border-radius:50%;animation:verlo-spin .8s linear infinite;"></div>
				<div id="verlo-overlay-msg" style="font-size:14px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'Working…', 'verlo' ); ?></div>
				<div style="margin-top:14px;height:6px;width:220px;border-radius:999px;background:#eef0f2;overflow:hidden;">
					<div style="height:100%;width:40%;border-radius:999px;background:#2271b1;animation:verlo-bar 1.1s ease-in-out infinite;"></div>
				</div>
				<div style="margin-top:10px;font-size:12px;color:#646970;"><?php esc_html_e( 'This can take a few seconds. Please keep this tab open.', 'verlo' ); ?></div>
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

			var I18N = <?php echo $i18n_js; ?>;

			// Rolling sub-messages so the wait feels like the algorithm is working.
			var PHASES = <?php echo $phases_js; ?>;
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
						msgEl.textContent = I18N.stillWorking.replace('%s', elapsed());
					}
				}, 3000);
			}

			document.addEventListener('submit', function(e){
				var form = e.target;
				if(!form || form.nodeName !== 'FORM') return;
				// Must be the button that was actually clicked (e.submitter), not
				// just any element with the attribute somewhere in the form — a
				// form can hold multiple submit buttons with different meanings
				// (e.g. "Save brief" next to "Save & next"), and scanning the
				// whole form previously showed the WRONG button's progress
				// message (and rolling phase copy) whenever the plain, synchronous
				// "Save brief" button was clicked instead of its async sibling.
				var btn = (e.submitter && e.submitter.hasAttribute && e.submitter.hasAttribute('data-verlo-progress'))
					? e.submitter
					: null;
				if(!btn && document.activeElement && document.activeElement.hasAttribute
					&& document.activeElement.hasAttribute('data-verlo-progress')) {
					// Fallback only for browsers without SubmitEvent.submitter support.
					btn = document.activeElement;
				}
				if(!btn) return;
				if(typeof form.checkValidity === 'function' && !form.checkValidity()) return;
				msgEl.style.transition = 'opacity .2s';
				msgEl.textContent = btn.getAttribute('data-verlo-progress') || I18N.working;
				startRolling(btn.getAttribute('data-verlo-phases') || 'generic');
				overlay.style.display = 'block';
			}, true);
			window.addEventListener('pageshow', function(){
				overlay.style.display = 'none';
				if(roll){ clearInterval(roll); roll = null; }
			});

			// Resume-on-load: a redirect landed us here with a background job
			// already queued/running (see Verlo_Async_Job) - the submit that
			// started it happened on the PREVIOUS page load, so there is no
			// submit event to catch here. Show the overlay immediately and poll
			// for completion instead.
			if (window.verloAsyncPoll) {
				(function(){
					var cfg = window.verloAsyncPoll;
					msgEl.style.transition = 'opacity .2s';
					msgEl.textContent = I18N.working;
					startRolling(cfg.kind);
					overlay.style.display = 'block';

					var inflight = false, consecutiveFailures = 0, pollTimer = null;

					function stripAndReload(extra){
						var u = new URL(cfg.baseUrl, window.location.href);
						Object.keys(extra || {}).forEach(function(k){ u.searchParams.set(k, extra[k]); });
						window.location.replace(u.toString());
					}

					function poll(force){
						if (inflight && !force) return;
						inflight = true;
						var url = cfg.ajaxUrl + '?action=verlo_async_status&job_key=' + encodeURIComponent(cfg.jobKey)
							+ '&nonce=' + encodeURIComponent(cfg.nonce) + (force ? '&force=1' : '');
						fetch(url, {credentials:'same-origin'})
							.then(function(r){ return r.json(); })
							.then(function(res){
								inflight = false;
								if (!res || !res.success) return;
								consecutiveFailures = 0;
								var d = res.data || {};
								if (d.state === 'done') {
									clearInterval(pollTimer);
									var done = { verlo_notice: d.message || I18N.done };
									// brief-next's runner reports which article got the
									// brief so we can land on its detail view, same as the
									// old synchronous redirect_to_brief() did.
									if (d.meta && d.meta.article_id) { done.verlo_brief = d.meta.article_id; }
									stripAndReload(done);
								} else if (d.state === 'error') {
									clearInterval(pollTimer);
									var extra = { verlo_notice: d.message || I18N.somethingWrong, verlo_error: 1 };
									if (d.meta && d.meta.error_code === 'verlo_no_content') { extra.verlo_link_kg = 1; }
									if (d.meta && d.meta.is_billing_error) { extra.verlo_link_billing = 1; }
									stripAndReload(extra);
								}
							})
							.catch(function(){
								inflight = false;
								consecutiveFailures++;
								// A poll's self-heal run can itself take a while and get cut
								// off by a proxy before responding - the job keeps running
								// server-side either way, so just force the next poll to
								// check fresh rather than treating this as fatal.
								if (consecutiveFailures >= 4) { poll(true); }
							});
					}
					poll(false);
					pollTimer = setInterval(function(){ poll(false); }, 2500);
					// If nothing's moved shortly after landing here, force this open
					// tab to self-heal (run the job itself) rather than wait out the
					// full loopback/cron window passively.
					setTimeout(function(){ poll(true); }, 8000);
				})();
			}
		})();
		</script>
		<?php
	}

	public static function menu() {
		add_submenu_page(
			'verlo',
			__( 'Strategy Profile', 'verlo' ),
			__( 'Strategy Profile', 'verlo' ),
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
		.verlo-alert{display:flex;align-items:flex-start;gap:14px;padding:18px 20px;border-radius:10px;margin:16px 0 18px;font-size:14px;line-height:1.55;}
		.verlo-alert-error{background:#fdf1f1;border:1.5px solid #e6a3a3;box-shadow:0 1px 3px rgba(180,40,40,.08);}
		.verlo-alert-icon{font-size:22px;line-height:1;flex-shrink:0;}
		.verlo-alert-body{flex:1;}
		.verlo-alert-title{display:block;font-weight:700;color:#8a1f1f;margin-bottom:3px;font-size:14px;}
		.verlo-alert-body p{margin:0;color:#5c1c1c;font-weight:500;}
		.verlo-alert-body a{font-weight:600;}
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
			<h1><?php esc_html_e( 'Site Strategy Profile', 'verlo' ); ?>
				<a href="<?php echo esc_url( VERLO_DOCS_URL ); ?>" target="_blank" rel="noopener noreferrer" class="page-title-action"><?php esc_html_e( 'Help & Docs', 'verlo' ); ?></a>
			</h1>
			<p style="margin-top:2px;color:#646970;"><?php esc_html_e( 'The one-time configuration that drives keyword, tone, intent, and structure decisions for this site.', 'verlo' ); ?></p>

			<?php if ( '__working__' === $notice ) : ?>
				<?php Verlo_Async_Job::render_poll_bootstrap( 'analyze', 'analyze', admin_url( 'admin.php?page=verlo-profile' ) ); ?>
			<?php elseif ( $notice && $is_error ) : ?>
				<!-- Deliberately not the standard thin-bordered .notice-error: this
				     page also carries other plugins' promotional/status notices
				     above it, and a genuine connection/action failure needs to
				     stand out from that noise rather than blend into it. -->
				<div class="verlo-alert verlo-alert-error" role="alert">
					<span class="verlo-alert-icon" aria-hidden="true">⚠️</span>
					<div class="verlo-alert-body">
						<span class="verlo-alert-title"><?php esc_html_e( 'Something went wrong', 'verlo' ); ?></span>
						<p>
							<?php echo esc_html( $notice ); ?>
							<?php if ( $link_kg ) : ?>
								&nbsp;<a href="<?php echo esc_url( $kg_url ); ?>"><?php esc_html_e( 'Open the Knowledge Graph page to build it now →', 'verlo' ); ?></a>
							<?php endif; ?>
						</p>
					</div>
				</div>
			<?php elseif ( $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( $notice ); ?></p>
				</div>
			<?php endif; ?>
			<?php Verlo_Guided_Tour::maybe_render_banner( 'verlo-profile' ); ?>

			<div class="verlo-grid">

				<!-- Verlo connection card -->
				<div class="verlo-card">
					<h2>
						<?php esc_html_e( 'Verlo connection', 'verlo' ); ?>
						<?php if ( $connected ) : ?>
							<span class="verlo-badge ok"><?php esc_html_e( 'Connected', 'verlo' ); ?></span>
							<span class="verlo-badge info" style="font-size:11px;">
								<?php
								printf(
									/* translators: %s: plan name, e.g. "Pro" */
									esc_html__( '%s plan', 'verlo' ),
									esc_html( ucfirst( Verlo_Auth::plan() ) )
								);
								?>
							</span>
						<?php else : ?>
							<span class="verlo-badge off"><?php esc_html_e( 'Not connected', 'verlo' ); ?></span>
						<?php endif; ?>
					</h2>

					<?php if ( $connected ) : ?>
						<p class="verlo-sub"><?php esc_html_e( 'Your license is active. Disconnect to enter a different license key.', 'verlo' ); ?></p>
						<p class="verlo-meta" style="margin-bottom:12px;">
							<?php
							printf(
								/* translators: %s: first characters of the site id */
								esc_html__( 'Site ID: %s', 'verlo' ),
								'<code>' . esc_html( substr( Verlo_Auth::site_id(), 0, 8 ) . '…' ) . '</code>'
							);
							?>
						</p>
						<form method="post" action="<?php echo esc_url( $url ); ?>">
							<input type="hidden" name="action" value="verlo_disconnect" />
							<?php wp_nonce_field( 'verlo_disconnect' ); ?>
							<button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Disconnect Verlo? Content generation will stop until you reconnect.', 'verlo' ) ); ?>');"><?php esc_html_e( 'Disconnect', 'verlo' ); ?></button>
						</form>
					<?php else : ?>
						<div data-verlo-tour-target="connect"<?php echo Verlo_Guided_Tour::target_id_attr( 'connect' ); ?>>
						<p class="verlo-sub"><?php esc_html_e( 'Connect your Verlo account — no need to find and paste a license key.', 'verlo' ); ?></p>
						<div class="verlo-actions" style="margin-bottom:16px;">
							<a class="button button-primary button-hero" href="<?php echo esc_url( self::connect_start_url() ); ?>"><?php esc_html_e( 'Connect with Verlo', 'verlo' ); ?></a>
						</div>
						<p class="verlo-sub" style="margin-top:0;"><?php esc_html_e( 'Or enter a license key manually:', 'verlo' ); ?></p>
						<form method="post" action="<?php echo esc_url( $url ); ?>">
							<input type="hidden" name="action" value="verlo_connection" />
							<?php wp_nonce_field( 'verlo_connection' ); ?>
							<table class="form-table" role="presentation">
								<tr class="verlo-field"><th><?php esc_html_e( 'License key', 'verlo' ); ?></th><td>
									<input type="password" name="license_key" value="" autocomplete="off" placeholder="verlo-…" style="max-width:360px;" />
								</td></tr>
							</table>
							<div class="verlo-actions">
								<button type="submit" class="button button-primary" data-verlo-progress="<?php esc_attr_e( 'Connecting to Verlo…', 'verlo' ); ?>"><?php esc_html_e( 'Connect', 'verlo' ); ?></button>
							</div>
						</form>
						</div>
						<?php Verlo_Guided_Tour::render_target_callout( 'connect' ); ?>
					<?php endif; ?>
				</div>

				<!-- Settings card -->
				<div class="verlo-card">
					<h2><?php esc_html_e( 'Settings', 'verlo' ); ?></h2>
					<p class="verlo-sub"><?php esc_html_e( 'Image and link settings for generated articles.', 'verlo' ); ?></p>
					<form method="post" action="<?php echo esc_url( $url ); ?>">
						<input type="hidden" name="action" value="verlo_connection" />
						<input type="hidden" name="verlo_settings_only" value="1" />
						<?php wp_nonce_field( 'verlo_connection' ); ?>
						<table class="form-table" role="presentation">
							<tr class="verlo-field"><th><?php esc_html_e( 'Trusted outbound domains', 'verlo' ); ?></th><td>
								<textarea name="outbound_domains" rows="3" placeholder="<?php echo esc_attr( __( 'one domain per line, e.g.', 'verlo' ) . "\nakc.org\nmayoclinic.org" ); ?>"><?php echo esc_textarea( $s['outbound_domains'] ?? '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Niche-specific sites generated articles may link out to (in addition to Wikipedia and .gov/.edu). Leave blank for universal authorities only.', 'verlo' ); ?></p>
							</td></tr>
							<tr class="verlo-field"><th><?php esc_html_e( 'In-body images (max)', 'verlo' ); ?></th><td>
								<select name="inline_images">
									<?php $ic = (int) ( $s['inline_images'] ?? 1 ); ?>
									<?php foreach ( array( 0, 1, 2, 3 ) as $opt ) : ?>
										<option value="<?php echo $opt; ?>" <?php selected( $ic, $opt ); ?>>
											<?php
											if ( 0 === $opt ) {
												esc_html_e( 'None (featured image only)', 'verlo' );
											} else {
												printf(
													/* translators: %d: number of images */
													esc_html( _n( 'Up to %d image', 'Up to %d images', $opt, 'verlo' ) ),
													$opt
												);
											}
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</td></tr>
						</table>
						<div class="verlo-actions">
							<button type="submit" class="button"><?php esc_html_e( 'Save settings', 'verlo' ); ?></button>
						</div>
					</form>
				</div>

				<!-- Site analysis card -->
				<div class="verlo-card">
					<h2><?php esc_html_e( 'Site analysis', 'verlo' ); ?></h2>
					<p class="verlo-sub"><?php esc_html_e( 'Reads a low-token snapshot of your knowledge graph (sample titles + top vocabulary, never full posts) and proposes profile values for your review. One call. Nothing is final until you save.', 'verlo' ); ?></p>
					<form method="post" action="<?php echo esc_url( $url ); ?>">
						<input type="hidden" name="action" value="verlo_analyze" />
						<?php wp_nonce_field( 'verlo_analyze' ); ?>
						<div class="verlo-actions">
							<button type="submit" class="button button-primary" data-verlo-tour-target="profile-analyze"<?php echo Verlo_Guided_Tour::target_id_attr( 'profile-analyze' ); ?> data-verlo-progress="<?php esc_attr_e( 'Analyzing your site with Verlo…', 'verlo' ); ?>" data-verlo-phases="analyze" <?php disabled( ! $connected ); ?>><?php esc_html_e( 'Analyze my site with Verlo', 'verlo' ); ?></button>
							<?php if ( ! $connected ) : ?>
								<span class="description"><?php esc_html_e( 'Connect your Verlo license first.', 'verlo' ); ?></span>
							<?php endif; ?>
						</div>
						<?php Verlo_Guided_Tour::render_target_callout( 'profile-analyze' ); ?>
					</form>
					<p class="verlo-meta">
						<?php
						if ( $p['meta']['inferred_at'] ) {
							printf(
								/* translators: %s: human-readable time since the last analysis */
								esc_html__( 'Last analysis: %s ago.', 'verlo' ),
								esc_html( human_time_diff( (int) $p['meta']['inferred_at'], time() ) )
							);
						} else {
							esc_html_e( 'No analysis run yet.', 'verlo' );
						}
						?>
					</p>
				</div>
			</div>

			<!-- Profile card -->
			<div class="verlo-card verlo-card-full">
				<h2>
					<?php esc_html_e( 'Profile', 'verlo' ); ?>
					<?php if ( $complete ) : ?>
						<span class="verlo-badge ok"><?php esc_html_e( 'Complete', 'verlo' ); ?></span>
					<?php else : ?>
						<span class="verlo-badge warn"><?php esc_html_e( 'Incomplete: niche, audience & voice required', 'verlo' ); ?></span>
					<?php endif; ?>
				</h2>
				<p class="verlo-sub"><?php esc_html_e( 'Fill manually or accept/edit the Verlo proposal, then save.', 'verlo' ); ?></p>
				<form method="post" action="<?php echo esc_url( $url ); ?>">
					<input type="hidden" name="action" value="verlo_save_profile" />
					<?php wp_nonce_field( 'verlo_save_profile' ); ?>
					<table class="form-table" role="presentation">
						<tr class="verlo-field"><th><?php esc_html_e( 'Site name', 'verlo' ); ?></th><td><input type="text" name="site_name" value="<?php echo esc_attr( $p['site_name'] ); ?>" /></td></tr>
						<tr class="verlo-field"><th><?php esc_html_e( 'Tagline', 'verlo' ); ?></th><td><input type="text" name="tagline" value="<?php echo esc_attr( $p['tagline'] ); ?>" /></td></tr>
						<tr class="verlo-field"><th><?php esc_html_e( 'Niche', 'verlo' ); ?></th><td><input type="text" name="niche" value="<?php echo esc_attr( $p['niche'] ); ?>" placeholder="<?php esc_attr_e( 'what this site is about', 'verlo' ); ?>" /></td></tr>
						<tr class="verlo-field"><th><?php esc_html_e( 'Monetization model', 'verlo' ); ?></th><td>
							<select name="monetization_model">
								<?php foreach ( $models as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $p['monetization_model'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The master switch. It changes keyword, tone, and volume strategy.', 'verlo' ); ?></p>
						</td></tr>
						<tr class="verlo-field"><th><?php esc_html_e( 'Audience', 'verlo' ); ?></th><td><textarea name="audience" rows="3" placeholder="<?php esc_attr_e( 'who they are and what they need', 'verlo' ); ?>"><?php echo esc_textarea( $p['audience'] ); ?></textarea></td></tr>
						<tr class="verlo-field"><th><?php esc_html_e( 'Voice', 'verlo' ); ?></th><td><textarea name="voice" rows="2" placeholder="<?php esc_attr_e( 'tone and style', 'verlo' ); ?>"><?php echo esc_textarea( $p['voice'] ); ?></textarea></td></tr>
						<tr class="verlo-field"><th><?php esc_html_e( 'Language', 'verlo' ); ?></th><td><input type="text" name="language" value="<?php echo esc_attr( $p['language'] ); ?>" style="max-width:120px;" /></td></tr>
						<tr class="verlo-field"><th><?php esc_html_e( 'Geo target', 'verlo' ); ?></th><td><input type="text" name="geo" value="<?php echo esc_attr( $p['geo'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. US, UK, global', 'verlo' ); ?>" style="max-width:240px;" /></td></tr>
						<tr class="verlo-field"><th><?php esc_html_e( 'Constraints', 'verlo' ); ?></th><td><textarea name="constraints" rows="2" placeholder="<?php esc_attr_e( 'topics to avoid, compliance notes', 'verlo' ); ?>"><?php echo esc_textarea( $p['constraints'] ); ?></textarea></td></tr>
					</table>
					<div class="verlo-actions"><?php submit_button( __( 'Save profile', 'verlo' ), 'primary', 'submit', false ); ?></div>
				</form>
				<p class="verlo-meta">
					<?php
					if ( $p['meta']['updated_at'] ) {
						printf(
							/* translators: %s: human-readable time since the profile was last saved */
							esc_html__( 'Last saved %s ago.', 'verlo' ),
							esc_html( human_time_diff( (int) $p['meta']['updated_at'], time() ) )
						);
					}
					?>
				</p>
			</div>

			<!-- Export / import card -->
			<div class="verlo-card verlo-card-full">
				<h2><?php esc_html_e( 'Export / Import', 'verlo' ); ?></h2>
				<p class="verlo-sub"><?php esc_html_e( 'Reuse this profile as a template on your other sites.', 'verlo' ); ?></p>
				<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline;">
					<input type="hidden" name="action" value="verlo_export_profile" />
					<?php wp_nonce_field( 'verlo_export_profile' ); ?>
					<?php submit_button( __( 'Download profile JSON', 'verlo' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( $url ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="verlo_import_profile" />
					<?php wp_nonce_field( 'verlo_import_profile' ); ?>
					<textarea name="profile_json" rows="5" class="large-text code" placeholder="<?php esc_attr_e( 'paste profile JSON here', 'verlo' ); ?>"></textarea>
					<div class="verlo-actions"><?php submit_button( __( 'Import profile JSON', 'verlo' ), 'secondary', 'submit', false ); ?></div>
				</form>
			</div>
		</div>
		<?php
	}

	/* ----- handlers ----- */

	/**
	 * Handles both the license-key connect form and the settings-only save form.
	 * When verlo_settings_only=1, only saves outbound_domains/inline_images.
	 */
	public static function handle_connection() {
		self::guard( 'verlo_connection' );

		$domains = sanitize_textarea_field( wp_unslash( $_POST['outbound_domains'] ?? '' ) );
		$inline  = max( 0, min( 3, (int) ( $_POST['inline_images'] ?? 1 ) ) );

		// Settings-only save (from the Settings card — no license key involved).
		if ( ! empty( $_POST['verlo_settings_only'] ) ) {
			$s = verlo_get_settings();
			$s['outbound_domains'] = $domains;
			$s['inline_images']    = $inline;
			update_option( VERLO_OPT_SETTINGS, $s, 'no' );
			self::redirect( __( 'Settings saved.', 'verlo' ) );
		}

		// License-key connect.
		$license_key = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		if ( '' === $license_key ) {
			self::redirect( __( 'Enter a license key first.', 'verlo' ), true );
		}
		// Verlo keys always look like VERLO-XXXXXX-XXXXXX-XXXXXX-XXXXXX (see
		// generateLicenseKey() in verlo-saas). Reject anything else locally —
		// no reason to spend a network round trip telling someone their pasted
		// value isn't a key at all.
		if ( ! preg_match( '/^VERLO(-[0-9A-F]{6}){4}$/i', $license_key ) ) {
			self::redirect( __( 'That doesn\'t look like a Verlo license key. Copy it from your dashboard\'s License Keys page — it looks like VERLO-XXXXXX-XXXXXX-XXXXXX-XXXXXX.', 'verlo' ), true );
		}

		$s = verlo_get_settings();
		$s['outbound_domains'] = $domains;
		$s['inline_images']    = $inline;
		update_option( VERLO_OPT_SETTINGS, $s, 'no' );

		$res = Verlo_Auth::verify( $license_key );
		if ( is_wp_error( $res ) ) {
			self::redirect( sprintf( /* translators: %s: error message */ __( 'Connection failed: %s', 'verlo' ), $res->get_error_message() ), true );
		}

		$plan = isset( $res['plan'] ) ? ucfirst( (string) $res['plan'] ) : 'active';
		self::redirect( sprintf( /* translators: %s: plan name */ __( 'Connected! Verlo is active (%s plan).', 'verlo' ), $plan ) );
	}

	/** Disconnect and clear all stored auth data. */
	public static function handle_disconnect() {
		self::guard( 'verlo_disconnect' );
		Verlo_Auth::disconnect();
		self::redirect( __( 'Verlo disconnected.', 'verlo' ) );
	}

	/** Nonce-signed link that starts the "Connect with Verlo" redirect flow. */
	protected static function connect_start_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=verlo_connect_start' ), 'verlo_connect_start' );
	}

	/**
	 * Step 1 of the redirect flow: send the browser to the dashboard's
	 * connect-plugin page. return_url is nonce-signed for THIS admin session
	 * so the completion request below can't be forged by a crafted link
	 * pointing at a different site's wp-admin (check_admin_referer there is
	 * the actual CSRF guard, same as every other admin-post handler here).
	 */
	public static function handle_connect_start() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'verlo_connect_start' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'verlo' ) );
		}

		// wp_nonce_url() runs its result through esc_html(), which turns the
		// querystring's "&" into "&amp;" — correct for printing straight into
		// an href, but this URL isn't printed as HTML: it's embedded as a raw
		// value inside $dashboard_url below. rawurlencode() has no idea "&amp;"
		// is an HTML entity, so it preserves those literal characters, and the
		// nonce lands in the request under the key "amp;_wpnonce" instead of
		// "_wpnonce". $_GET['_wpnonce'] then reads empty every single time, so
		// verification in handle_connect_complete() always failed — 100% of
		// connect attempts, not the rare stale-session case the fallback below
		// was written for. Build the nonce URL directly instead of through
		// wp_nonce_url()'s HTML-escaped output.
		$return_url = add_query_arg(
			'_wpnonce',
			wp_create_nonce( 'verlo_connect_complete' ),
			admin_url( 'admin-post.php?action=verlo_connect_complete' )
		);

		// Built by hand (not add_query_arg) so return_url's own querystring
		// (?action=...&_wpnonce=...) is correctly percent-encoded as a single
		// value rather than merging into the outer query string.
		$dashboard_url = Verlo_SaaS_Client::dashboard_url() . '/connect-plugin'
			. '?site_url=' . rawurlencode( home_url() )
			. '&return_url=' . rawurlencode( $return_url );

		wp_redirect( $dashboard_url );
		exit;
	}

	/**
	 * Step 2: the dashboard redirects back here with a one-time claim token
	 * after the user authorized the connection. Exchange it for the license
	 * key and connect exactly as if it had been typed in manually.
	 */
	public static function handle_connect_complete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'verlo' ) );
		}
		// A plain check_admin_referer() here would wp_die() on a bad/expired
		// nonce with WordPress's generic "The link you followed has expired"
		// page — a dead end with no way back into the plugin, and no chance
		// to explain what actually happened. This round trip goes out to the
		// dashboard and back, so a stale nonce (an old tab, a slow return
		// trip, a session that rotated in between) is a real, recoverable
		// case, not just an attack to reject. Verify the nonce ourselves and
		// send the user back to our own screen with a clear next step instead.
		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'verlo_connect_complete' ) ) {
			self::redirect( __( 'Connection failed: this link has expired. Click "Connect with Verlo" again to retry.', 'verlo' ), true );
		}

		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		if ( '' === $token ) {
			self::redirect( __( 'Connection failed: no token received.', 'verlo' ), true );
		}

		$res = Verlo_Auth::connect_via_token( $token );
		if ( is_wp_error( $res ) ) {
			self::redirect( sprintf( /* translators: %s: error message */ __( 'Connection failed: %s', 'verlo' ), $res->get_error_message() ), true );
		}

		$plan = isset( $res['plan'] ) ? ucfirst( (string) $res['plan'] ) : 'active';
		self::redirect( sprintf( /* translators: %s: plan name */ __( 'Connected! Verlo is active (%s plan).', 'verlo' ), $plan ) );
	}

	public static function handle_save_profile() {
		self::guard( 'verlo_save_profile' );
		Verlo_Profile::save( wp_unslash( $_POST ), 'manual' );
		self::redirect( __( 'Profile saved.', 'verlo' ) );
	}

	/**
	 * Analysis calls the Verlo SaaS and can take up to ~60s
	 * (Verlo_SaaS_Client::run_job()'s timeout) - long enough that hosts with
	 * a shorter proxy/PHP execution limit than that (common on shared
	 * hosting) return a 503 before it finishes, even though the analysis
	 * itself would have succeeded. Queuing through Verlo_Async_Job instead
	 * returns control to the browser immediately; the page shows a live
	 * progress state and polls until the real result is ready (see
	 * progress_overlay()'s resume-on-load poller and Verlo_Profile::run_pending()).
	 */
	public static function handle_analyze() {
		self::guard( 'verlo_analyze' );
		if ( ! Verlo_Auth::is_connected() ) {
			self::redirect( __( 'Connect Verlo first under Strategy Profile → Verlo connection.', 'verlo' ), true );
		}
		Verlo_Async_Job::queue( 'analyze' );
		self::redirect( '__working__' );
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
			self::redirect( sprintf( /* translators: %s: error message */ __( 'Import failed: %s', 'verlo' ), $res->get_error_message() ), true );
		}
		self::redirect( __( 'Profile imported.', 'verlo' ) );
	}

	protected static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce ) ) {
			wp_die( esc_html__( 'Permission denied.', 'verlo' ) );
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
