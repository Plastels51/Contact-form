<?php
/**
 * Database handler — CRUD for submissions and rate limits.
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
		$rate_limits     = $this->get_rate_limits_table();

		$sql_submissions = "CREATE TABLE {$submissions} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id VARCHAR(50) NOT NULL DEFAULT 'default',
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
			KEY idx_submitted_at (submitted_at),
			KEY idx_ip_address (ip_address),
			KEY idx_status_form (status, form_id)
		) {$charset_collate};";

		$sql_rate_limits = "CREATE TABLE {$rate_limits} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_address VARCHAR(45) NOT NULL,
			submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_ip_time (ip_address, submitted_at)
		) {$charset_collate};";

		dbDelta( $sql_submissions );
		dbDelta( $sql_rate_limits );

		update_option( 'cfs_db_version', CFS_VERSION );
	}

	/**
	 * Drop plugin tables (called from uninstall.php).
	 */
	public function drop_tables(): void {
		global $wpdb;
		$submissions = $this->get_submissions_table();
		$rate_limits = $this->get_rate_limits_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$submissions}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			'form_id'       => $data['form_id'] ?? 'default',
			'name'          => $data['name'] ?? null,
			'email'         => $data['email'] ?? null,
			'phone'         => $data['phone'] ?? null,
			'comment'       => $data['comment'] ?? null,
			'form_data_json' => wp_json_encode( $data ),
			'status'        => 'new',
			'ip_address'    => $data['ip_address'] ?? null,
			'user_agent'    => $data['user_agent'] ?? null,
			'page_url'      => $data['page_url'] ?? null,
			'submitted_at'  => current_time( 'mysql' ),
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->get_submissions_table(), $insert, $formats );

		if ( false === $result ) {
			return false;
		}

		delete_transient( 'cfs_new_count' );

		return (int) $wpdb->insert_id;
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
		return $wpdb->get_results(
			$wpdb->prepare( $sql, $values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
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

		delete_transient( 'cfs_new_count' );

		return false !== $result;
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

		delete_transient( 'cfs_new_count' );

		return false !== $result;
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

		$ids = array_map( 'intval', $ids );
		if ( empty( $ids ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = $this->get_submissions_table();
		$extra_set    = '';

		if ( 'processed' === $status ) {
			$extra_set = ", processed_at = '" . current_time( 'mysql' ) . "', processed_by = " . (int) get_current_user_id();
		}

		$values   = array_merge( array( $status ), $ids );
		$sql      = "UPDATE {$table} SET status = %s{$extra_set} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

		$ids = array_map( 'intval', $ids );
		if ( empty( $ids ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = $this->get_submissions_table();
		$sql          = "DELETE FROM {$table} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $ids ) );

		delete_transient( 'cfs_new_count' );

		return false !== $result;
	}

	/**
	 * Check rate limit for an IP address.
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
				gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) )
			)
		);

		$one_minute_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
		$one_hour_ago   = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );

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
	 * Record a submission for rate limiting.
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
				'submitted_at' => current_time( 'mysql' ),
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
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY submitted_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$limit
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY submitted_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status,
				$limit
			)
		);
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
