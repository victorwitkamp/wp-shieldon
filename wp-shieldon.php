<?php
/**
 * WP Shieldon
 *
 * @author Terry Lin
 * @link https://terryl.in/
 *
 * @package Shieldon
 * @since 1.0.0
 * @version 2.0.2
 */

/**
 * Plugin Name: WP Shieldon Reloaded
 * Plugin URI:  https://github.com/victorwitkamp/wp-shieldon
 * Description: An anti-scraping plugin for WordPress.
 * Version:     2.0.2
 * Author:      Victor Witkamp
 * Author URI:  https://victorwitkamp.nl/
 * License:     GPL 3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: wp-shieldon
 * Domain Path: /languages
 */
declare(strict_types=1);
use WPShieldon\Plugin;
use WPShieldon\PluginAdmin;
use WPShieldon\WPSO_Tweak_WP_Core;

/**
 * Any issues, or would like to request a feature, please visit.
 * https://github.com/terrylinooo/wp-shieldon/issues
 *
 * Welcome to contribute your code here:
 * https://github.com/terrylinooo/wp-shieldon
 *
 * Thanks for using WP WP Shieldon!
 * Star it, fork it, share it if you like this plugin.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}
define( 'SHIELDON_PLUGIN_NAME', plugin_basename( __FILE__ ) );
define( 'SHIELDON_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHIELDON_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHIELDON_PLUGIN_PATH', __FILE__ );
define( 'SHIELDON_PLUGIN_VERSION', '2.0.1' );
define( 'SHIELDON_CORE_VERSION', '2.1.1' );
define( 'SHIELDON_PLUGIN_TEXT_DOMAIN', 'wp-shieldon' );

// Load helper functions
require_once SHIELDON_PLUGIN_DIR . 'includes/helpers.php';


// Composer autoloader. Mainly load Shieldon library.
require_once SHIELDON_PLUGIN_DIR . 'vendor/autoload.php';

// WP Shieldon Class autoloader.
require_once SHIELDON_PLUGIN_DIR . 'includes/autoload.php';


if ( PHP_VERSION_ID < 70100 ) {
	/**
	 * Prompt a warning message while PHP version does not meet the minimum requirement.
	 * And, nothing to do.
	 */
	function wpso_warning() {
		echo wpso_load_view( 'message/php-version-warning' );
	}

	add_action( 'admin_notices', 'wpso_warning' );
	return;
}

// Avoid to load Shieldon library while doing AJAX and REST API calls.
if ( wp_doing_ajax() ) {
	return;
}


/**
 * Start to run WP Shieldon plugin cores on admin panel.
 */
if ( is_admin() ) {
	new PluginAdmin();
	//  return;
}

/**
 * Check if Shieldon daemon is enabled.
 * The following code will be executed only when Shieldon daemon is enabled.
 * Otherwise, we make an early return and nothing to do.
 */
if ( 'yes' !== wpso_get_option( 'enable_daemon', 'shieldon_daemon' ) ) {
	return;
}

/**
 * Tweak WordPress core depends on Shieldon settings.
 *
 * @return void
 */
function wpso_tweak_init() {
	new WPSO_Tweak_WP_Core();
}

add_action( 'init', 'wpso_tweak_init' );

add_action( 'plugins_loaded', function () { ( new Plugin() ); } );
