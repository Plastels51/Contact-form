<?php
/**
 * Submission model — one shape for both stored payload versions.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Submission
 *
 * form_data_json comes in two shapes:
 *
 *   v1 (2.x) — primary values at the top level, indexed and custom fields in
 *              "extra", an optional "_schema" listing tokens;
 *   v2 (3.x) — an ordered "fields" list carrying name, type, label, value and
 *              a ready-to-print display string.
 *
 * Everything that reads submissions — the admin card, the CSV export, add-ons —
 * goes through this class, so neither has to know which era a row came from.
 */
class CFS_Submission {

	/**
	 * Field types that belong in the "applicant" box.
	 */
	const CONTACT_TYPES = array( 'name', 'surname', 'patronymic', 'phone', 'email' );

	/**
	 * Types that never carry a value.
	 */
	const NON_VALUE_TYPES = array( 'submit', 'step', 'text' );

	/**
	 * Raw database row.
	 *
	 * @var object
	 */
	private $row;

	/**
	 * Decoded payload.
	 *
	 * @var array
	 */
	private $payload;

	/**
	 * Normalised field list.
	 *
	 * @var array|null
	 */
	private $fields = null;

	/**
	 * Constructor.
	 *
	 * @param object $row Database row.
	 */
	private function __construct( $row ) {
		$this->row = $row;

		$decoded       = json_decode( (string) ( $row->form_data_json ?? '' ), true );
		$this->payload = is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Build from a database row.
	 *
	 * @param object $row Database row.
	 * @return CFS_Submission
	 */
	public static function from_row( $row ): CFS_Submission {
		return new self( $row );
	}

	/**
	 * Raw row, for code that still needs the columns.
	 *
	 * @return object
	 */
	public function get_row() {
		return $this->row;
	}

	/**
	 * Submission ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return (int) ( $this->row->id ?? 0 );
	}

	/**
	 * Payload version: 1 for rows written by 2.x, 2 for 3.x.
	 *
	 * @return int
	 */
	public function get_version(): int {
		return (int) ( $this->payload['_v'] ?? 1 );
	}

	/**
	 * Post ID of the form, or 0 for a legacy submission.
	 *
	 * @return int
	 */
	public function get_form_post_id(): int {
		return (int) ( $this->row->form_post_id ?? 0 );
	}

	/**
	 * Form identifier as stored — a post ID for 3.x, a hashed slug for 2.x.
	 *
	 * @return string
	 */
	public function get_form_id(): string {
		return (string) ( $this->row->form_id ?? '' );
	}

	/**
	 * Human-readable form name.
	 *
	 * The title recorded at submit time wins: it still names the form correctly
	 * after it has been renamed or deleted.
	 *
	 * @return string
	 */
	public function get_form_title(): string {
		$stored = (string) ( $this->payload['form']['title'] ?? '' );
		if ( '' !== $stored ) {
			return $stored;
		}

		$form_id = $this->get_form_post_id();
		if ( $form_id > 0 ) {
			$form = CFS_Form::load( $form_id );
			if ( $form ) {
				return $form->get_title();
			}
		}

		return $this->get_form_id();
	}

	/**
	 * Every submitted field, in the order the form declared them.
	 *
	 * @return array<int, array{name: string, type: string, label: string, value: mixed, display: string}>
	 */
	public function get_fields(): array {
		if ( null !== $this->fields ) {
			return $this->fields;
		}

		$this->fields = 2 === $this->get_version()
			? $this->fields_from_v2()
			: $this->fields_from_v1();

		return $this->fields;
	}

	/**
	 * Fields of a 3.x payload.
	 *
	 * @return array
	 */
	private function fields_from_v2(): array {
		$fields = array();

		foreach ( (array) ( $this->payload['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}

			$value = $field['value'] ?? '';

			$fields[] = array(
				'name'    => (string) $field['name'],
				'type'    => (string) ( $field['type'] ?? 'text' ),
				'label'   => (string) ( $field['label'] ?? $field['name'] ),
				'value'   => $value,
				'display' => (string) ( $field['display'] ?? ( is_array( $value ) ? implode( ', ', $value ) : (string) $value ) ),
			);
		}

		return $fields;
	}

	/**
	 * Fields of a 2.x payload, reconstructed from what that version stored.
	 *
	 * @return array
	 */
	private function fields_from_v1(): array {
		$primary = array( 'name', 'surname', 'patronymic', 'phone', 'email', 'comment', 'select', 'checkbox' );

		$values = array();
		foreach ( $primary as $key ) {
			if ( isset( $this->payload[ $key ] ) && '' !== (string) $this->payload[ $key ] ) {
				$values[ $key ] = (string) $this->payload[ $key ];
			}
		}
		foreach ( (array) ( $this->payload['extra'] ?? array() ) as $key => $value ) {
			$values[ (string) $key ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		$types  = array();
		$labels = array();

		// The schema snapshot, when present, gives the order and the labels.
		$order = array();
		foreach ( (array) ( $this->payload['_schema'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['token'] ) ) {
				continue;
			}
			$token           = (string) $entry['token'];
			$order[]         = $token;
			$types[ $token ] = (string) ( $entry['type'] ?? $this->guess_type( $token ) );
			$labels[ $token ] = (string) ( $entry['label'] ?? '' );
		}

		// Anything present in the data but missing from the snapshot still shows.
		foreach ( array_keys( $values ) as $token ) {
			if ( ! in_array( $token, $order, true ) ) {
				$order[] = $token;
			}
		}

		$fields = array();
		foreach ( $order as $token ) {
			$type  = $types[ $token ] ?? $this->guess_type( $token );
			$value = (string) ( $values[ $token ] ?? '' );
			$label = (string) ( $labels[ $token ] ?? '' );

			if ( '' === $label ) {
				$label = CFS_Field_Types::exists( $type )
					? CFS_Field_Types::default_label( $type )
					: ucfirst( str_replace( array( '_', '-' ), ' ', $token ) );
			}

			$fields[] = array(
				'name'    => $token,
				'type'    => $type,
				'label'   => $label,
				'value'   => $value,
				'display' => 'phone' === $type ? CFS_Field_Types::format_phone( $value ) : $value,
			);
		}

		return $fields;
	}

	/**
	 * Best guess at a 2.x token's type: "comment_2" was a comment.
	 *
	 * @param string $token Field token.
	 * @return string
	 */
	private function guess_type( string $token ): string {
		$base = (string) preg_replace( '/(_\d+)$/', '', $token );

		return CFS_Field_Types::exists( $base ) ? CFS_Field_Types::canonical( $base ) : 'text';
	}

	/**
	 * Contact fields — name, phone, email and friends.
	 *
	 * @return array
	 */
	public function get_contact_fields(): array {
		return array_values(
			array_filter(
				$this->get_fields(),
				static function ( array $field ): bool {
					return in_array( $field['type'], self::CONTACT_TYPES, true );
				}
			)
		);
	}

	/**
	 * Everything the visitor filled in that is not contact data.
	 *
	 * @return array
	 */
	public function get_data_fields(): array {
		return array_values(
			array_filter(
				$this->get_fields(),
				static function ( array $field ): bool {
					return ! in_array( $field['type'], self::CONTACT_TYPES, true )
						&& 'hidden' !== $field['type']
						&& ! in_array( $field['type'], self::NON_VALUE_TYPES, true );
				}
			)
		);
	}

	/**
	 * Hidden fields — UTM tags and anything else the page passed along.
	 *
	 * @return array
	 */
	public function get_hidden_fields(): array {
		return array_values(
			array_filter(
				$this->get_fields(),
				static function ( array $field ): bool {
					return 'hidden' === $field['type'] && '' !== (string) $field['display'];
				}
			)
		);
	}

	/**
	 * The name to show in a list: surname, name and patronymic in that order.
	 *
	 * Falls back to the first contact field with a value, so a form that only
	 * asks for a phone number still has something to click on.
	 *
	 * @param string $fallback Returned when nothing is filled in.
	 * @return string
	 */
	public function get_display_name( string $fallback = '—' ): string {
		$parts = array(
			'surname'    => '',
			'name'       => '',
			'patronymic' => '',
		);

		foreach ( $this->get_fields() as $field ) {
			$type = (string) $field['type'];
			if ( array_key_exists( $type, $parts ) && '' === $parts[ $type ] ) {
				$parts[ $type ] = (string) $field['display'];
			}
		}

		$full = trim( implode( ' ', array_filter( $parts ) ) );
		if ( '' !== $full ) {
			return $full;
		}

		foreach ( $this->get_contact_fields() as $field ) {
			if ( '' !== (string) $field['display'] ) {
				return (string) $field['display'];
			}
		}

		return $fallback;
	}

	/**
	 * Display value of one field.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	public function get_display( string $name ): string {
		foreach ( $this->get_fields() as $field ) {
			if ( $field['name'] === $name ) {
				return (string) $field['display'];
			}
		}

		return '';
	}

	/**
	 * name => label for every field, for CSV headers.
	 *
	 * @return array<string, string>
	 */
	public function get_labels(): array {
		$labels = array();

		foreach ( $this->get_fields() as $field ) {
			$labels[ (string) $field['name'] ] = (string) $field['label'];
		}

		return $labels;
	}

	/**
	 * Whether the page that submitted this had an outdated copy of the form.
	 *
	 * @return bool
	 */
	public function is_stale(): bool {
		return ! empty( $this->payload['form']['stale'] );
	}
}
