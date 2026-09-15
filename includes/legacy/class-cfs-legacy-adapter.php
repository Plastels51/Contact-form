<?php
/**
 * Converts 2.x shortcode attributes into a 3.x template.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Legacy_Adapter
 */
class CFS_Legacy_Adapter {

	/**
	 * Required-by-default fields in 2.x.
	 *
	 * @var array<string, bool>
	 */
	const DEFAULT_REQUIRED = array(
		'name'    => true,
		'surname' => true,
		'phone'   => true,
	);

	/**
	 * 2.x base types that became a different 3.x type.
	 *
	 * @var array<string, string>
	 */
	const TYPE_MAP = array(
		'comment' => 'textarea',
	);

	/**
	 * Convert one set of shortcode attributes.
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return array{title: string, template: string, groups: array}
	 */
	public static function convert( array $atts ): array {
		$atts = array_change_key_case( $atts, CASE_LOWER );

		$lines = array();

		$title = trim( (string) ( $atts['title'] ?? '' ) );
		if ( '' !== $title ) {
			$lines[] = '<h3 class="cfs-form-title">' . esc_html( $title ) . '</h3>';
		}

		$subtitle = trim( (string) ( $atts['subtitle'] ?? '' ) );
		if ( '' !== $subtitle ) {
			$lines[] = '<p class="cfs-form-subtitle">' . esc_html( $subtitle ) . '</p>';
		}

		if ( ! empty( $lines ) ) {
			$lines[] = '';
		}

		// fields="a,b|c,d" — the pipe splits the form into wizard steps.
		$groups      = array_map( 'trim', explode( '|', (string) ( $atts['fields'] ?? 'name,phone,email' ) ) );
		$step_labels = '' !== trim( (string) ( $atts['steps'] ?? '' ) )
			? array_map( 'trim', explode( '|', (string) $atts['steps'] ) )
			: array();

		$star_used = false !== strpos( (string) ( $atts['fields'] ?? '' ), '*' );
		$multistep = count( $groups ) > 1;

		foreach ( $groups as $index => $group ) {
			if ( $multistep ) {
				$label   = (string) ( $step_labels[ $index ] ?? '' );
				$lines[] = '' !== $label ? '[step label="' . self::quote( $label ) . '"]' : '[step]';
			}

			foreach ( array_filter( array_map( 'trim', explode( ',', $group ) ) ) as $token ) {
				$line = self::convert_field( $token, $atts, $star_used );
				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}

			if ( $multistep ) {
				$lines[] = '';
			}
		}

		$lines[] = self::convert_submit( $atts );

		return array(
			'title'    => '' !== $title ? $title : self::derive_title( $atts ),
			'template' => implode( "\n", $lines ) . "\n",
			'groups'   => self::convert_settings( $atts ),
		);
	}

	/**
	 * Convert one field token into a tag, or into HTML for the static text type.
	 *
	 * @param string $raw_token Token as written in the fields attribute.
	 * @param array  $atts      Shortcode attributes.
	 * @param bool   $star_used Whether star notation is in play.
	 * @return string
	 */
	private static function convert_field( string $raw_token, array $atts, bool $star_used ): string {
		$starred = '*' === substr( $raw_token, -1 );
		$token   = $starred ? rtrim( $raw_token, '*' ) : $raw_token;
		$token   = strtolower( trim( $token ) );

		if ( '' === $token ) {
			return '';
		}

		$base = preg_match( '/^([a-z]+)_(\d+)$/', $token, $m ) ? $m[1] : $token;
		$type = self::TYPE_MAP[ $base ] ?? $base;

		// The 2.x "text" type was a display-only paragraph, which is plain HTML
		// in a 3.x template.
		if ( 'text' === $base ) {
			$content = (string) self::attr( $token, $base, 'label', $atts, '' );
			return '' !== $content ? '<p>' . wp_kses( $content, self::inline_html() ) . '</p>' : '';
		}

		if ( 'hidden' === $base ) {
			$name  = (string) self::attr( $token, $base, 'name', $atts, '' );
			$value = (string) self::attr( $token, $base, 'value', $atts, '' );
			$name  = sanitize_key( $name );

			if ( '' === $name ) {
				return '';
			}

			return '[hidden ' . $name . ( '' !== $value ? ' value="' . self::quote( $value ) . '"' : '' ) . ']';
		}

		if ( ! CFS_Field_Types::exists( $type ) ) {
			return '';
		}

		$required = $star_used
			? $starred
			: 'yes' === (string) self::attr( $token, $base, 'required', $atts, ! empty( self::DEFAULT_REQUIRED[ $base ] ) ? 'yes' : 'no' );

		$parts = array( '[' . $type . ( $required ? '*' : '' ), $token );

		$label = (string) self::attr( $token, $base, 'label', $atts, '' );
		if ( '' === $label ) {
			$label = (string) self::attr( $token, $base, 'name', $atts, '' );
		}
		if ( 'agreement' === $base && '' === $label ) {
			$label = (string) get_option( 'cfs_agreement_text', '' );
		}
		if ( '' !== $label ) {
			$parts[] = 'label="' . self::quote( $label ) . '"';
		}

		$simple = array(
			'placeholder' => 'placeholder',
			'icon'        => 'icon',
			'pattern'     => 'pattern',
			'hint'        => 'help',
			'min'         => 'min',
			'max'         => 'max',
			'step'        => 'step',
		);

		foreach ( $simple as $from => $to ) {
			$value = (string) self::attr( $token, $base, $from, $atts, '' );
			if ( '' !== $value ) {
				$parts[] = $to . '="' . self::quote( $value ) . '"';
			}
		}

		if ( in_array( $base, array( 'select', 'radio', 'multicheck' ), true ) ) {
			$options = (string) self::attr( $token, $base, 'options', $atts, '' );
			if ( '' !== $options ) {
				$parts[] = 'options="' . self::quote( $options ) . '"';
			}
		}

		if ( 'comment' === $base ) {
			$rows = (string) self::attr( $token, $base, 'rows', $atts, '' );
			if ( '' !== $rows ) {
				$parts[] = 'rows="' . self::quote( $rows ) . '"';
			}
		}

		return implode( ' ', $parts ) . ']';
	}

	/**
	 * The submit button tag.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	private static function convert_submit( array $atts ): string {
		$parts = array( '[submit' );

		$text = (string) ( $atts['button_text'] ?? '' );
		if ( '' !== $text ) {
			$parts[] = '"' . self::quote( $text ) . '"';
		}

		foreach ( array( 'button_icon_before' => 'icon_before', 'button_icon_after' => 'icon_after', 'button_class' => 'class' ) as $from => $to ) {
			$value = (string) ( $atts[ $from ] ?? '' );
			if ( '' !== $value ) {
				$parts[] = $to . '="' . self::quote( $value ) . '"';
			}
		}

		return implode( ' ', $parts ) . ']';
	}

	/**
	 * Everything that is not a field: presentation and after-submit behaviour.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return array Settings groups keyed by meta key.
	 */
	private static function convert_settings( array $atts ): array {
		$after    = CFS_Form::defaults( CFS_Form::META_AFTER );
		$settings = CFS_Form::defaults( CFS_Form::META_SETTINGS );

		$message = (string) ( $atts['success_message'] ?? '' );
		if ( '' !== $message ) {
			$after['message'] = $message;
		}

		$redirect = (string) ( $atts['redirect_url'] ?? '' );
		if ( '' !== $redirect ) {
			$after['mode']         = 'message_redirect';
			$after['redirect_url'] = esc_url_raw( $redirect );
			$after['redirect_delay'] = max( 0, min( 60, (int) ( $atts['redirect_delay'] ?? 2 ) ) );
		}

		// 2.x always closed a modal after the delay; keeping that avoids a
		// surprise for anyone whose visitors are used to it.
		if ( 'dialog' === (string) ( $atts['container'] ?? '' ) ) {
			$settings['container']   = 'dialog';
			$after['close_modal']    = true;
		}

		foreach ( array(
			'modal_button_text'        => 'modal_button_text',
			'modal_button_icon_before' => 'modal_button_icon_before',
			'modal_button_icon_after'  => 'modal_button_icon_after',
			'modal_button_class'       => 'modal_button_class',
			'class'                    => 'css_class',
			'next_text'                => 'next_text',
			'back_text'                => 'back_text',
			'next_icon_after'          => 'next_icon_after',
			'back_icon_before'         => 'back_icon_before',
		) as $from => $to ) {
			$value = (string) ( $atts[ $from ] ?? '' );
			if ( '' !== $value ) {
				$settings[ $to ] = $value;
			}
		}

		return array(
			CFS_Form::META_AFTER    => $after,
			CFS_Form::META_SETTINGS => $settings,
		);
	}

	/**
	 * Two-level attribute lookup: {token}_{attr}, then {base}_{attr}.
	 *
	 * @param string $token   Full token, e.g. "comment_2".
	 * @param string $base    Base type, e.g. "comment".
	 * @param string $attr    Attribute suffix.
	 * @param array  $atts    Shortcode attributes.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	private static function attr( string $token, string $base, string $attr, array $atts, $default ) {
		if ( array_key_exists( $token . '_' . $attr, $atts ) ) {
			return $atts[ $token . '_' . $attr ];
		}
		if ( array_key_exists( $base . '_' . $attr, $atts ) ) {
			return $atts[ $base . '_' . $attr ];
		}
		return $default;
	}

	/**
	 * Escape a value for a double-quoted tag attribute.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function quote( string $value ): string {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), trim( $value ) );
	}

	/**
	 * Inline HTML allowed in a converted static text field.
	 *
	 * @return array<string, array>
	 */
	private static function inline_html(): array {
		return array(
			'a'      => array( 'href' => array(), 'target' => array(), 'rel' => array(), 'class' => array() ),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
		);
	}

	/**
	 * A readable name when the shortcode had no title.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	private static function derive_title( array $atts ): string {
		$fields = (string) ( $atts['fields'] ?? '' );
		$short  = implode( ', ', array_slice( array_filter( array_map( 'trim', preg_split( '/[,|]/', str_replace( '*', '', $fields ) ) ) ), 0, 3 ) );

		return '' !== $short
			/* translators: %s: first field names of the migrated form */
			? sprintf( __( 'Форма (%s)', 'contact-form-submissions' ), $short )
			: __( 'Форма с сайта', 'contact-form-submissions' );
	}
}
