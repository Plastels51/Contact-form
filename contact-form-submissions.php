<?php
/**
 * Plugin Name: Contact Form Submissions
 * Plugin URI:  https://github.com/Plastels51/contact-form-submissions#readme
 * Description: Flexible contact forms via shortcode.
 * Version:     3.0.0
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
define( 'CFS_VERSION', '3.0.0' );
define( 'CFS_PLUGIN_FILE', __FILE__ );
define( 'CFS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CFS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CFS_TEXT_DOMAIN', 'contact-form-submissions' );

// Autoload includes.
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-db.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-mail-template.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-mailer.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-action-result.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-action-runner.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-exporter.php';

// Form engine: template → schema → HTML.
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-post-type.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-template-parser.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-template-sanitizer.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-field-types.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-form-compiler.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-form.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-submission.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-icons.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-form-renderer.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-shortcode.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-integrations.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-ajax-handler.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-dashboard.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-admin.php';
require_once CFS_PLUGIN_DIR . 'includes/admin/class-cfs-admin-forms.php';
require_once CFS_PLUGIN_DIR . 'includes/admin/class-cfs-admin-form-editor.php';
require_once CFS_PLUGIN_DIR . 'includes/class-cfs-plugin.php';

/*
 * Compatibility module for the 2.x shortcode syntax.
 *
 * Guarded by file_exists() on purpose: deleting includes/legacy/ is the entire
 * uninstall procedure for it — nothing in the core references the folder, and
 * this block simply stops finding anything to load.
 */
if ( file_exists( CFS_PLUGIN_DIR . 'includes/legacy/class-cfs-legacy.php' ) ) {
	require_once CFS_PLUGIN_DIR . 'includes/legacy/class-cfs-legacy.php';
	add_action( 'plugins_loaded', array( 'CFS_Legacy', 'boot' ), 5 );
}

// Activation / deactivation hooks.
register_activation_hook( __FILE__, array( 'CFS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CFS_Plugin', 'deactivate' ) );

// Boot the plugin.
add_action( 'plugins_loaded', array( 'CFS_Plugin', 'get_instance' ) );
