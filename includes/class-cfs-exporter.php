<?php
/**
 * CSV exporter for submissions.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Exporter
 */
class CFS_Exporter {

	/**
	 * DB instance.
	 *
	 * @var CFS_DB
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param CFS_DB $db DB instance.
	 */
	public function __construct( CFS_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Stream CSV to browser.
	 *
	 * @param array $filters Filters: status, form_id.
	 */
	public function export_csv( array $filters = array() ): void {
		$form_id = isset( $filters['form_id'] ) ? sanitize_key( $filters['form_id'] ) : 'all';
		$date    = gmdate( 'Y-m-d' );
		$filename = "submissions-{$form_id}-{$date}.csv";

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		// UTF-8 BOM for Excel.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		// Header row.
		fputcsv( $output, array(
			'ID',
			__( 'Форма', 'contact-form-submissions' ),
			__( 'Имя', 'contact-form-submissions' ),
			__( 'Фамилия', 'contact-form-submissions' ),
			__( 'Телефон', 'contact-form-submissions' ),
			__( 'Email', 'contact-form-submissions' ),
			__( 'Комментарий', 'contact-form-submissions' ),
			__( 'Статус', 'contact-form-submissions' ),
			__( 'IP', 'contact-form-submissions' ),
			__( 'Страница', 'contact-form-submissions' ),
			__( 'Дата', 'contact-form-submissions' ),
		) );

		// Data rows — iterate in pages to avoid memory issues.
		$page     = 1;
		$per_page = 500;

		do {
			$args = array_merge(
				$filters,
				array(
					'page'     => $page,
					'per_page' => $per_page,
				)
			);

			$rows = $this->db->get_submissions( $args );

			foreach ( $rows as $row ) {
				$form_data = array();
				if ( ! empty( $row->form_data_json ) ) {
					$decoded = json_decode( $row->form_data_json, true );
					$form_data = is_array( $decoded ) ? $decoded : array();
				}

				fputcsv( $output, array(
					$row->id,
					$row->form_id,
					$row->name ?? '',
					$form_data['surname'] ?? '',
					$row->phone ?? '',
					$row->email ?? '',
					$row->comment ?? '',
					$row->status,
					$row->ip_address ?? '',
					$row->page_url ?? '',
					$row->submitted_at,
				) );
			}

			++$page;
		} while ( count( $rows ) === $per_page );

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
