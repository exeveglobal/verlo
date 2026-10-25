<?php
/**
 * Plugin Name:       Verlo
 * Plugin URI:        https://verlohub.com/
 * Description:       Publishes the articles you write and approve in Verlo to this site. Planning, writing and editing all happen at app.verlohub.com; this plugin only receives finished articles and creates or updates the post, with the layout, SEO fields and FAQ data in place.
 * Version:           2.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            EXEVE
 * Author URI:        https://exeve.global/
 * License:           GPL-2.0-or-later
 * Text Domain:       verlo
 *
 * Verlo 2 is a publisher, not a writer. Articles arrive in one of two ways, both signed with your connection key:
 *   - Verlo sends them to this site (POST /wp-json/verlo/v1/publish), or
 *   - this site collects them (a background check every few minutes, and at once when Verlo says one is waiting),
 *     which works behind firewalls and on private sites.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VERLO_VERSION', '2.1.1' );
define( 'VERLO_FILE', __FILE__ );
define( 'VERLO_DIR', plugin_dir_path( __FILE__ ) );
define( 'VERLO_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'VERLO_API_BASE' ) ) {
	// Override in wp-config.php for a staging environment only.
	define( 'VERLO_API_BASE', 'https://api.verlohub.com' );
}
define( 'VERLO_OPT_CONNECTION', 'verlo_connection' );
define( 'VERLO_HOOK_POLL', 'verlo_poll' );

require_once VERLO_DIR . 'includes/class-verlo-signature.php';
require_once VERLO_DIR . 'includes/class-verlo-settings.php';
require_once VERLO_DIR . 'includes/class-verlo-api.php';
require_once VERLO_DIR . 'includes/class-verlo-blocks.php';
require_once VERLO_DIR . 'includes/class-verlo-faq-schema.php';
require_once VERLO_DIR . 'includes/class-verlo-content-styles.php';
require_once VERLO_DIR . 'includes/class-verlo-publisher.php';
require_once VERLO_DIR . 'includes/class-verlo-rest.php';
require_once VERLO_DIR . 'includes/class-verlo-poller.php';
require_once VERLO_DIR . 'includes/class-verlo-admin.php';

register_activation_hook( __FILE__, array( 'Verlo_Poller', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Verlo_Poller', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	Verlo_Rest::init();
	Verlo_Poller::init();
	Verlo_Content_Styles::init();
	Verlo_Faq_Schema::init();
	if ( is_admin() ) {
		Verlo_Admin::init();
	}
} );
