<?php
/**
 * Uninstall script — runs when the plugin is deleted via WP admin.
 * Drops tables and removes all plugin options.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

// Security: only run from WordPress uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-cfs-db.php';

$db = new CFS_DB();
$db->drop_tables();

// Remove plugin options.
$options = array(
	'cfs_db_version',
	'cfs_extra_emails',
	'cfs_email_subject',
	'cfs_banned_words',
	'cfs_save_ip',
	'cfs_save_ua',
	'cfs_disable_styles',
	'cfs_max_comment_length',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete transients.
delete_transient( 'cfs_new_count' );
