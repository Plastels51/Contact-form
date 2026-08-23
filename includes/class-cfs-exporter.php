<?php
/**
 * CSV export.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Exporter
 *
 * Columns are built from the exported submissions themselves, so a form with
 * eight fields exports eight columns with their own headings instead of
 * flattening everything the 2.x export had no column for into one cell.
 */
class CFS_Exporter {

	/**
	 * Rows fetched per query while paging through the result set.
	 */
	const PAGE_SIZE = 500;

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
	 * Stream a CSV export to the browser.
	 *
	 * @param array $filters Filters: status, form_id.
	 */
	public function export_csv( array $filters = array() ): void {
		$form_id  = isset( $filters['form_id'] ) ? sanitize_key( (string) $filters['form_id'] ) : 'all';
		$filename = sprintf( 'submissions-%s-%s.csv', '' !== $form_id ? $form_id : 'all', gmdate( 'Y-m-d' ) );

		// The column set has to be known before the header row is written, so
		// the rows are walked twice: once to collect fields, once to print.
		$columns = $this->collect_columns( $filters );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		// UTF-8 BOM — without it Excel reads Cyrillic as mojibake.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$this->put_row( $output, $this->header_row( $columns ) );

		$this->walk(
			$filters,
			function ( $row ) use ( $output, $columns ): void {
				$this->put_row( $output, $this->data_row( $row, $columns ) );
			}
		);

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Build the whole table in memory: row 0 is the header.
	 *
	 * export_csv() streams instead of using this, so a large export never has
	 * to fit in memory; this exists for tests and for add-ons that want the
	 * same table in another format.
	 *
	 * @param array $filters Filters: status, form_id.
	 * @return array<int, array>
	 */
	public function build_table( array $filters = array() ): array {
		$columns = $this->collect_columns( $filters );
		$table   = array( $this->header_row( $columns ) );

		$this->walk(
			$filters,
			function ( $row ) use ( &$table, $columns ): void {
				$table[] = $this->data_row( $row, $columns );
			}
		);

		return $table;
	}

	/**
	 * Field columns for this export: name => label, in form order.
	 *
	 * @param array $filters Active filters.
	 * @return array<string, string>
	 */
	private function collect_columns( array $filters ): array {
		$columns = array();

		// A filtered export follows the form's current field order, so the file
		// matches what the editor shows even for fields nobody has filled yet.
		$form_id = isset( $filters['form_id'] ) ? (string) $filters['form_id'] : '';
		if ( '' !== $form_id && ctype_digit( $form_id ) ) {
			$form = CFS_Form::load( (int) $form_id );
			if ( $form ) {
				foreach ( $form->get_fields() as $name => $field ) {
					if ( empty( $field['submits'] ) ) {
						continue;
					}
					$columns[ (string) $name ] = wp_strip_all_tags( (string) $field['label'] );
				}
			}
		}

		$this->walk(
			$filters,
			function ( $row ) use ( &$columns ): void {
				foreach ( CFS_Submission::from_row( $row )->get_labels() as $name => $label ) {
					if ( ! isset( $columns[ $name ] ) ) {
						$columns[ $name ] = $label;
					}
				}
			}
		);

		return $columns;
	}

	/**
	 * Run a callback over every submission matching the filters.
	 *
	 * @param array    $filters  Active filters.
	 * @param callable $callback Receives one row.
	 */
	private function walk( array $filters, callable $callback ): void {
		$page = 1;

		do {
			$rows = $this->db->get_submissions(
				array_merge(
					$filters,
					array(
						'page'     => $page,
						'per_page' => self::PAGE_SIZE,
					)
				)
			);

			foreach ( $rows as $row ) {
				$callback( $row );
			}

			++$page;
		} while ( count( $rows ) === self::PAGE_SIZE );
	}

	/**
	 * Header row.
	 *
	 * @param array $columns Field columns.
	 * @return array
	 */
	private function header_row( array $columns ): array {
		return array_merge(
			array(
				'ID',
				__( 'Форма', 'contact-form-submissions' ),
				__( 'Дата', 'contact-form-submissions' ),
				__( 'Статус', 'contact-form-submissions' ),
			),
			array_values( $columns ),
			array(
				'IP',
				__( 'Страница', 'contact-form-submissions' ),
				'User Agent',
			)
		);
	}

	/**
	 * One data row.
	 *
	 * @param object $row     Database row.
	 * @param array  $columns Field columns.
	 * @return array
	 */
	private function data_row( $row, array $columns ): array {
		$item   = CFS_Submission::from_row( $row );
		$values = array();

		foreach ( array_keys( $columns ) as $name ) {
			$values[] = $item->get_display( (string) $name );
		}

		return array_merge(
			array(
				(int) $row->id,
				$item->get_form_title(),
				(string) $row->submitted_at,
				$this->status_label( (string) $row->status ),
			),
			$values,
			array(
				(string) ( $row->ip_address ?? '' ),
				(string) ( $row->page_url ?? '' ),
				(string) ( $row->user_agent ?? '' ),
			)
		);
	}

	/**
	 * Human-readable status.
	 *
	 * @param string $status Status column value.
	 * @return string
	 */
	private function status_label( string $status ): string {
		switch ( $status ) {
			case 'processed':
				return __( 'Обработана', 'contact-form-submissions' );
			case 'spam':
				return __( 'Спам', 'contact-form-submissions' );
			default:
				return __( 'Новая', 'contact-form-submissions' );
		}
	}

	/**
	 * Write one CSV row, neutralising spreadsheet formulas.
	 *
	 * A value starting with =, +, - or @ is executed as a formula by Excel and
	 * LibreOffice, which turns a contact form into a remote code execution
	 * vector for whoever opens the export. Prefixing with an apostrophe makes
	 * the cell literal text.
	 *
	 * @param resource $handle Output stream.
	 * @param array    $fields Row values.
	 */
	private function put_row( $handle, array $fields ): void {
		fputcsv( $handle, array_map( array( __CLASS__, 'escape_cell' ), $fields ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
	}

	/**
	 * Neutralise one cell.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function escape_cell( $value ): string {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
