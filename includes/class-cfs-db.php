<?php
/**
 * Database handler — CRUD for submissions, submission meta and rate limits.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_DB
 */
class CFS_DB {

	/**
	 * Submissions table name (without prefix).
	 */
	const TABLE_SUBMISSIONS = 'contact_submissions';

	/**
	 * Submission meta table name (without prefix).
	 */
	const TABLE_META = 'cfs_submission_meta';

	/**
	 * Rate limits table name (without prefix).
	 */
	const TABLE_RATE_LIMITS = 'cfs_rate_limits';

	/**
	 * Get submissions table name.
	 *
	 * @return string
	 */
	public function get_submissions_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUBMISSIONS;
	}

	/**
	 * Get submission meta table name.
	 *
	 * @return string
	 */
	public function get_meta_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_META;
	}

	/**
	 * Get rate limits table name.
	 *
	 * @return string
	 */
	public function get_rate_limits_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_RATE_LIMITS;
	}

	/**
	 * Create plugin tables via dbDelta.
	 */
	public function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$submissions     = $this->get_submissions_table();
		$meta            = $this->get_meta_table();
		$rate_limits     = $this->get_rate_limits_table();

		$sql_submissions = "CREATE TABLE {$submissions} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id VARCHAR(50) NOT NULL DEFAULT 'default',
			form_post_id BIGINT(20) UNSIGNED DEFAULT NULL,
			name VARCHAR(255) DEFAULT NULL,
			email VARCHAR(255) DEFAULT NULL,
			phone VARCHAR(20) DEFAULT NULL,
			comment TEXT DEFAULT NULL,
			form_data_json LONGTEXT NOT NULL,
			status ENUM('new','processed','spam') NOT NULL DEFAULT 'new',
			ip_address VARCHAR(45) DEFAULT NULL,
			user_agent TEXT DEFAULT NULL,
			page_url VARCHAR(2048) DEFAULT NULL,
			submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at DATETIME DEFAULT NULL,
			processed_by BIGINT(20) UNSIGNED DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_form_id (form_id),
			KEY idx_form_post (form_post_id),
			KEY idx_submitted_at (submitted_at),
			KEY idx_ip_address (ip_address),
			KEY idx_status_form (status, form_id)
		) {$charset_collate};";

		/*
		 * Generic key/value store attached to a submission.
		 *
		 * Exists so add-on plugins (CRM integrations, notification bridges…)
		 * can persist their own per-submission state without altering the
		 * submissions schema or shipping a table of their own. Stays empty —
		 * and free — on sites that use no add-ons.
		 *
		 * meta_key is capped at 100 chars so the composite indexes stay under
		 * the 767-byte InnoDB key limit on utf8mb4.
		 */
		$sql_meta = "CREATE TABLE {$meta} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id BIGINT(20) UNSIGNED NOT NULL,
			meta_key VARCHAR(100) NOT NULL,
			meta_value LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_submission_key (submission_id, meta_key),
			KEY idx_key_value (meta_key, meta_value(64))
		) {$charset_collate};";

		$sql_rate_limits = "CREATE TABLE {$rate_limits} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_address VARCHAR(45) NOT NULL,
			submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_ip_time (ip_address, submitted_at)
		) {$charset_collate};";

		dbDelta( $sql_submissions );
		dbDelta( $sql_meta );
		dbDelta( $sql_rate_limits );

		update_option( 'cfs_db_version', CFS_VERSION );
	}

	/**
	 * Run dbDelta when the stored schema version is behind the plugin version.
	 *
	 * create_tables() only ran on activation, so schema changes shipped in an
	 * update were never applied to sites that update by replacing files. This
	 * closes that gap — dbDelta adds missing columns and indexes in place and
	 * leaves existing data untouched.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( 'cfs_db_version' ) === CFS_VERSION ) {
			return;
		}
		$this->create_tables();
	}

	/**
	 * Drop plugin tables (called from uninstall.php).
	 */
	public function drop_tables(): void {
		global $wpdb;
		$submissions = $this->get_submissions_table();
		$meta        = $this->get_meta_table();
		$rate_limits = $this->get_rate_limits_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$submissions}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$meta}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$rate_limits}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Insert a new submission.
	 *
	 * @param array $data Submission data.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public function insert_submission( array $data ) {
		global $wpdb;

		$insert = array(
			'form_id'        => $data['form_id'] ?? 'default',
			'form_post_id'   => isset( $data['form_post_id'] ) ? (int) $data['form_post_id'] : null,
			'name'           => $data['name'] ?? null,
			'email'          => $data['email'] ?? null,
			'phone'          => $data['phone'] ?? null,
			'comment'        => $data['comment'] ?? null,
			'form_data_json' => wp_json_encode( $data ),
			'status'         => $data['status'] ?? 'new',
			'ip_address'     => $data['ip_address'] ?? null,
			'user_agent'     => $data['user_agent'] ?? null,
			'page_url'       => $data['page_url'] ?? null,
			'submitted_at'   => current_time( 'mysql' ),
		);

		// Only 'new' and 'spam' can be set at insert time.
		if ( ! in_array( $insert['status'], array( 'new', 'spam' ), true ) ) {
			$insert['status'] = 'new';
		}

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->get_submissions_table(), $insert, $formats );

		if ( false === $result ) {
			return false;
		}

		delete_transient( 'cfs_new_count' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Submission counts per form, keyed by form post ID.
	 *
	 * One grouped query instead of a COUNT per row in the forms list.
	 *
	 * @return array<int, int>
	 */
	public function count_by_form_post(): array {
		global $wpdb;
		$table = $this->get_submissions_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT form_post_id, COUNT(*) AS total FROM {$table} WHERE form_post_id IS NOT NULL GROUP BY form_post_id" );

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->form_post_id ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Get a single submission by ID.
	 *
	 * @param int $id Submission ID.
	 * @return object|null
	 */
	public function get_submission( int $id ) {
		global $wpdb;
		$table = $this->get_submissions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Get list of submissions with filters.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_submissions( array $args = array() ): array {
		global $wpdb;
		$table = $this->get_submissions_table();

		$defaults = array(
			'status'   => '',
			'form_id'  => '',
			'page'     => 1,
			'per_page' => 20,
			'orderby'  => 'submitted_at',
			'order'    => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'form_id = %s';
			$values[] = $args['form_id'];
		}

		$where_sql = implode( ' AND ', $where );

		$allowed_orderby = array( 'submitted_at', 'status', 'id' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'submitted_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$values[] = $per_page;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare( $sql, $values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		// get_results() returns null on a query error; the return type is array.
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count submissions with optional filters.
	 *
	 * @param array $args Filters.
	 * @return int
	 */
	public function count_submissions( array $args = array() ): int {
		global $wpdb;
		$table  = $this->get_submissions_table();
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'form_id = %s';
			$values[] = $args['form_id'];
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get counts for every status in one query.
	 *
	 * Replaces four separate COUNT(*) round trips on the list screen.
	 *
	 * @param string $form_id Optional form filter.
	 * @return array{all:int,new:int,processed:int,spam:int}
	 */
	public function count_all_by_status( string $form_id = '' ): array {
		global $wpdb;
		$table = $this->get_submissions_table();

		$counts = array(
			'all'       => 0,
			'new'       => 0,
			'processed' => 0,
			'spam'      => 0,
		);

		if ( '' !== $form_id ) {
			$sql = "SELECT status, COUNT(*) AS total FROM {$table} WHERE form_id = %s GROUP BY status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! is_array( $rows ) ) {
			return $counts;
		}

		foreach ( $rows as $row ) {
			$status = (string) $row->status;
			$total  = (int) $row->total;
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = $total;
			}
			$counts['all'] += $total;
		}

		return $counts;
	}

	/**
	 * Get count of new submissions (cached).
	 *
	 * @return int
	 */
	public function get_new_count(): int {
		$cached = get_transient( 'cfs_new_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = $this->count_submissions( array( 'status' => 'new' ) );
		set_transient( 'cfs_new_count', $count, 5 * MINUTE_IN_SECONDS );
		return $count;
	}

	/**
	 * Update submission status.
	 *
	 * @param int    $id     Submission ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public function update_status( int $id, string $status ): bool {
		global $wpdb;

		$allowed = array( 'new', 'processed', 'spam' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$data   = array( 'status' => $status );
		$format = array( '%s' );

		if ( 'processed' === $status ) {
			$data['processed_at'] = current_time( 'mysql' );
			$data['processed_by'] = get_current_user_id();
			$format[]             = '%s';
			$format[]             = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$this->get_submissions_table(),
			$data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		delete_transient( 'cfs_new_count' );

		return true;
	}

	/**
	 * Delete a submission by ID.
	 *
	 * @param int $id Submission ID.
	 * @return bool
	 */
	public function delete_submission( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete(
			$this->get_submissions_table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$this->delete_all_meta( $id );
		delete_transient( 'cfs_new_count' );

		return true;
	}

	/**
	 * Bulk update submission statuses.
	 *
	 * @param array  $ids    Array of submission IDs.
	 * @param string $status New status.
	 * @return bool
	 */
	public function bulk_update_status( array $ids, string $status ): bool {
		global $wpdb;

		$allowed = array( 'new', 'processed', 'spam' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = $this->get_submissions_table();

		if ( 'processed' === $status ) {
			$sql    = "UPDATE {$table} SET status = %s, processed_at = %s, processed_by = %d WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$values = array_merge(
				array( $status, current_time( 'mysql' ), get_current_user_id() ),
				$ids
			);
		} else {
			$sql    = "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$values = array_merge( array( $status ), $ids );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		delete_transient( 'cfs_new_count' );

		return false !== $result;
	}

	/**
	 * Bulk delete submissions.
	 *
	 * @param array $ids Array of submission IDs.
	 * @return bool
	 */
	public function bulk_delete( array $ids ): bool {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = $this->get_submissions_table();
		$meta_table   = $this->get_meta_table();

		$sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $ids ) );

		$meta_sql = "DELETE FROM {$meta_table} WHERE submission_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $meta_sql, $ids ) );

		delete_transient( 'cfs_new_count' );

		return false !== $result;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   SUBMISSION META — generic per-submission storage for add-ons
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Read one meta value for a submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $key           Meta key.
	 * @param mixed  $default       Returned when the key does not exist.
	 * @return mixed Stored value (arrays/objects come back decoded), or $default.
	 */
	public function get_meta( int $submission_id, string $key, $default = null ) {
		global $wpdb;
		$table = $this->get_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$table} WHERE submission_id = %d AND meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$submission_id,
				$key
			)
		);

		if ( null === $value ) {
			return $default;
		}

		return maybe_unserialize( $value );
	}

	/**
	 * Read every meta value for a submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return array<string,mixed> key => value.
	 */
	public function get_all_meta( int $submission_id ): array {
		global $wpdb;
		$table = $this->get_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$table} WHERE submission_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$submission_id
			)
		);

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (string) $row->meta_key ] = maybe_unserialize( $row->meta_value );
			}
		}

		return $out;
	}

	/**
	 * Create or overwrite one meta value.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $key           Meta key (max 100 chars).
	 * @param mixed  $value         Value; arrays and objects are serialised.
	 * @return bool
	 */
	public function update_meta( int $submission_id, string $key, $value ): bool {
		global $wpdb;

		if ( $submission_id <= 0 || '' === $key ) {
			return false;
		}

		$key   = substr( $key, 0, 100 );
		$table = $this->get_meta_table();

		// REPLACE keeps this a single round trip and relies on the unique
		// (submission_id, meta_key) index to overwrite in place.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->replace(
			$table,
			array(
				'submission_id' => $submission_id,
				'meta_key'      => $key,
				'meta_value'    => maybe_serialize( $value ),
			),
			array( '%d', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Update several meta values at once.
	 *
	 * @param int   $submission_id Submission ID.
	 * @param array $pairs         key => value map.
	 * @return bool True when every write succeeded.
	 */
	public function update_meta_bulk( int $submission_id, array $pairs ): bool {
		$ok = true;
		foreach ( $pairs as $key => $value ) {
			$ok = $this->update_meta( $submission_id, (string) $key, $value ) && $ok;
		}
		return $ok;
	}

	/**
	 * Delete one meta key.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $key           Meta key.
	 * @return bool
	 */
	public function delete_meta( int $submission_id, string $key ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->delete(
			$this->get_meta_table(),
			array(
				'submission_id' => $submission_id,
				'meta_key'      => $key,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Delete every meta row for a submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return bool
	 */
	public function delete_all_meta( int $submission_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->delete(
			$this->get_meta_table(),
			array( 'submission_id' => $submission_id ),
			array( '%d' )
		);
	}

	/**
	 * Delete a meta key across every submission.
	 *
	 * Intended for add-on uninstall routines that need to drop their own state
	 * without touching anyone else's.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public function delete_meta_everywhere( string $key ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->delete(
			$this->get_meta_table(),
			array( 'meta_key' => $key ),
			array( '%s' )
		);
	}

	/**
	 * Count submissions carrying a given meta key/value pair.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value to match.
	 * @return int
	 */
	public function count_by_meta( string $key, string $value ): int {
		global $wpdb;
		$table = $this->get_meta_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE meta_key = %s AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$value
			)
		);
	}

	/**
	 * Get submission IDs carrying a given meta key/value pair.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value to match.
	 * @param int    $limit Maximum number of IDs.
	 * @return int[]
	 */
	public function get_ids_by_meta( string $key, string $value, int $limit = 100 ): array {
		global $wpdb;
		$table = $this->get_meta_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT submission_id FROM {$table} WHERE meta_key = %s AND meta_value = %s ORDER BY submission_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$value,
				max( 1, $limit )
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   RATE LIMITING
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Check rate limit for an IP address.
	 *
	 * All timestamps here are UTC. Mixing current_time('mysql') (site local)
	 * with gmdate() thresholds previously made the window either far too wide
	 * or permanently empty, depending on the sign of the site's UTC offset.
	 *
	 * @param string $ip IP address.
	 * @return bool True if rate-limited, false if allowed.
	 */
	public function is_rate_limited( string $ip ): bool {
		global $wpdb;
		$table = $this->get_rate_limits_table();

		// Clean up old records.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE submitted_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			)
		);

		$one_minute_ago = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
		$one_hour_ago   = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// Count last minute.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_minute = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND submitted_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ip,
				$one_minute_ago
			)
		);

		if ( $count_minute >= 5 ) {
			return true;
		}

		// Count last hour.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_hour = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND submitted_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ip,
				$one_hour_ago
			)
		);

		return $count_hour >= 20;
	}

	/**
	 * Record a submission attempt for rate limiting.
	 *
	 * Stored in UTC to match the thresholds used by is_rate_limited().
	 *
	 * @param string $ip IP address.
	 */
	public function record_rate_limit( string $ip ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->get_rate_limits_table(),
			array(
				'ip_address'   => $ip,
				'submitted_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Get distinct form IDs from submissions.
	 *
	 * @return array
	 */
	public function get_form_ids(): array {
		global $wpdb;
		$table = $this->get_submissions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col( "SELECT DISTINCT form_id FROM {$table} ORDER BY form_id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get recent submissions.
	 *
	 * @param int    $limit  Number of records.
	 * @param string $status Filter by status.
	 * @return array
	 */
	public function get_recent( int $limit = 5, string $status = 'new' ): array {
		global $wpdb;
		$table = $this->get_submissions_table();

		if ( '' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY submitted_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY submitted_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$status,
					$limit
				)
			);
		}

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count submissions by status.
	 *
	 * @param string $status Status to count ('new', 'processed', 'spam').
	 * @return int
	 */
	public function count_by_status( string $status ): int {
		global $wpdb;
		$table = $this->get_submissions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status
			)
		);
		return (int) $count;
	}
}
