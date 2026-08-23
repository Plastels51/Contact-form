<?php
/**
 * Print the most recent submission — a quick manual check during development.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'contact_submissions';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" );

if ( ! $row ) {
	echo "no submissions\n";
	return;
}

foreach ( array( 'id', 'form_id', 'form_post_id', 'name', 'phone', 'email', 'comment', 'page_url', 'status', 'ip_address', 'submitted_at' ) as $column ) {
	printf( "%-14s %s\n", $column, (string) $row->$column );
}

echo "--- form_data_json ---\n";
echo (string) wp_json_encode(
	json_decode( (string) $row->form_data_json, true ),
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
echo "\n";
