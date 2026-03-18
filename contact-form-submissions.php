<?php
/**
 * Plugin Name: Contact Form Submissions
 * Plugin URI:  https://github.com/Plastels51/contact-form-submissions#readme
 * Description: Flexible contact forms via shortcode.
 * Version:     2.1.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Author:      Plastels51
 * Author URI:  https://github.com/Plastels51
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: contact-form-submissions
 * Domain Path: /languages
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'CFS_VERSION', '2.1.0' );
define( 'CFS_PLUGIN_FILE', __FILE__ );
define( 'CFS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CFS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CFS_TEXT_DOMAIN', 'contact-form-submissions' );

// Autoload includes.
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-db.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-mailer.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-exporter.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-form-builder.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-ajax-handler.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-dashboard.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-admin.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-plugin.php';

// Activation / deactivation hooks.
register_activation_hook( __FILE__, array( 'CFS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CFS_Plugin', 'deactivate' ) );

// Boot the plugin.
add_action( 'plugins_loaded', array( 'CFS_Plugin', 'get_instance' ) );
