<?php
/**
 * Integration registry — third-party destinations for a submission.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Integrations
 *
 * An add-on describes itself once and the core does the rest: it draws the
 * settings card, escapes and stores the values, and calls the handler after a
 * submission is saved. The add-on writes no admin markup of its own.
 *
 *   add_filter( 'cfs_integrations', function ( array $items ): array {
 *       $items['bitrix24'] = array(
 *           'label'       => 'Битрикс24',
 *           'description' => 'Передача заявок в CRM',
 *           'fields'      => array(
 *               'webhook_url' => array( 'type' => 'url', 'label' => 'Вебхук', 'required' => true ),
 *               'map'         => array(
 *                   'type'    => 'field_map',
 *                   'label'   => 'Сопоставление полей',
 *                   'targets' => array( 'TITLE' => 'Название', 'PHONE' => 'Телефон' ),
 *               ),
 *           ),
 *           'run'      => array( $handler, 'send' ),
 *           'test'     => array( $handler, 'test_connection' ),
 *           'deferred' => true,
 *       );
 *       return $items;
 *   } );
 */
class CFS_Integrations {

	/**
	 * Setting field types the core knows how to render and sanitise.
	 */
	const FIELD_TYPES = array( 'text', 'url', 'number', 'password', 'checkbox', 'select', 'textarea', 'field_map' );

	/**
	 * Cached registry.
	 *
	 * @var array<string, array>|null
	 */
	private static $items = null;

	/**
	 * Every registered integration.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		if ( null !== self::$items ) {
			return self::$items;
		}

		/**
		 * Register integrations.
		 *
		 * @param array<string, array> $items Integration descriptors keyed by id.
		 */
		$items = (array) apply_filters( 'cfs_integrations', array() );

		$clean = array();
		foreach ( $items as $id => $item ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $item ) ) {
				continue;
			}

			$clean[ $id ] = array_merge(
				array(
					'id'          => $id,
					'label'       => $id,
					'description' => '',
					'fields'      => array(),
					'run'         => null,
					'test'        => null,
					'deferred'    => false,
				),
				$item
			);

			$clean[ $id ]['id'] = $id;
		}

		self::$items = $clean;

		return self::$items;
	}

	/**
	 * One integration descriptor.
	 *
	 * @param string $id Integration id.
	 * @return array|null
	 */
	public static function get( string $id ) {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Reset the cached registry (used by tests).
	 */
	public static function flush_cache(): void {
		self::$items = null;
	}

	/**
	 * Normalise one field descriptor.
	 *
	 * @param array $field Raw descriptor.
	 * @return array
	 */
	public static function normalize_field( array $field ): array {
		$field = array_merge(
			array(
				'type'        => 'text',
				'label'       => '',
				'description' => '',
				'required'    => false,
				'default'     => '',
				'options'     => array(),
				'targets'     => array(),
				'placeholder' => '',
			),
			$field
		);

		if ( ! in_array( $field['type'], self::FIELD_TYPES, true ) ) {
			$field['type'] = 'text';
		}

		return $field;
	}

	/**
	 * Sanitise the settings posted for one integration.
	 *
	 * @param string $id  Integration id.
	 * @param array  $raw Raw posted values.
	 * @return array
	 */
	public static function sanitize_settings( string $id, array $raw ): array {
		$item = self::get( $id );
		if ( null === $item ) {
			return array();
		}

		$clean = array();

		foreach ( (array) $item['fields'] as $key => $field ) {
			$key   = sanitize_key( (string) $key );
			$field = self::normalize_field( (array) $field );
			$value = $raw[ $key ] ?? null;

			switch ( $field['type'] ) {
				case 'checkbox':
					$clean[ $key ] = ! empty( $value );
					break;

				case 'url':
					$clean[ $key ] = esc_url_raw( (string) $value );
					break;

				case 'number':
					$clean[ $key ] = '' === (string) $value ? '' : (string) (float) $value;
					break;

				case 'textarea':
					$clean[ $key ] = sanitize_textarea_field( (string) $value );
					break;

				case 'select':
					$allowed       = array_map( 'strval', array_keys( (array) $field['options'] ) );
					$candidate     = (string) $value;
					$clean[ $key ] = in_array( $candidate, $allowed, true ) ? $candidate : '';
					break;

				case 'field_map':
					$map = array();
					foreach ( (array) $value as $target => $source ) {
						$target = sanitize_text_field( (string) $target );
						$source = sanitize_key( (string) $source );
						if ( '' !== $target && '' !== $source ) {
							$map[ $target ] = $source;
						}
					}
					$clean[ $key ] = $map;
					break;

				case 'password':
				case 'text':
				default:
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
			}
		}

		return $clean;
	}

	/**
	 * Stored settings for one integration on one form, merged with defaults.
	 *
	 * @param CFS_Form $form Form.
	 * @param string   $id   Integration id.
	 * @return array{enabled: bool, settings: array}
	 */
	public static function form_settings( CFS_Form $form, string $id ): array {
		$stored = $form->get_integrations();
		$entry  = isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) ? $stored[ $id ] : array();

		$item     = self::get( $id );
		$defaults = array();

		if ( null !== $item ) {
			foreach ( (array) $item['fields'] as $key => $field ) {
				$field                       = self::normalize_field( (array) $field );
				$defaults[ sanitize_key( (string) $key ) ] = $field['default'];
			}
		}

		return array(
			'enabled'  => ! empty( $entry['enabled'] ),
			'settings' => array_merge( $defaults, isset( $entry['settings'] ) && is_array( $entry['settings'] ) ? $entry['settings'] : array() ),
		);
	}

	/**
	 * Integrations enabled on a form, in registration order.
	 *
	 * @param CFS_Form $form Form.
	 * @return array<string, array> Integration id => descriptor.
	 */
	public static function enabled_for( CFS_Form $form ): array {
		$enabled = array();

		foreach ( self::all() as $id => $item ) {
			$state = self::form_settings( $form, $id );
			if ( $state['enabled'] ) {
				$enabled[ $id ] = $item;
			}
		}

		return $enabled;
	}
}
