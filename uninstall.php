<?php
/**
 * Uninstall script — runs when the plugin is deleted via WP admin.
 *
 * Removes everything the plugin created: its tables, its forms, its options
 * and the leftovers of the compatibility module.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

// Security: only run from WordPress uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-cfs-db.php';

global $wpdb;

$db = new CFS_DB();
$db->drop_tables();

/*
 * Forms are a post type, so they outlive the tables unless deleted explicitly.
 * Their meta goes with them — wp_delete_post() with force cleans postmeta.
 */
$cfs_form_ids = $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'cfs_form' )
);

foreach ( (array) $cfs_form_ids as $cfs_form_id ) {
	wp_delete_post( (int) $cfs_form_id, true );
}

// Pre-migration content backups written by the compatibility module.
$wpdb->query(
	$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_cfs_migration_backup' )
);

$cfs_options = array(
	'cfs_db_version',
	'cfs_extra_emails',
	'cfs_email_subject',
	'cfs_banned_words',
	'cfs_agreement_text',
	'cfs_save_ip',
	'cfs_save_ua',
	'cfs_style_theme',
	'cfs_disable_styles',
	'cfs_disable_btn_styles',
	'cfs_debug_mode',
	'cfs_max_comment_length',
	'cfs_legacy_migrated',
);

foreach ( $cfs_options as $cfs_option ) {
	delete_option( $cfs_option );
}

delete_transient( 'cfs_new_count' );

// Deferred actions that never got to run.
wp_clear_scheduled_hook( 'cfs_run_deferred_action' );

/*
 * 2.x kept a config transient per form. They expire on their own, but a site
 * being uninstalled should not be left with rows nobody will ever read.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cfs_form_config_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cfs_form_config_' ) . '%'
	)
);
