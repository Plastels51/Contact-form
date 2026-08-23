<?php
/**
 * Field type registry — descriptors, sanitisation and validation.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Field_Types
 *
 * A field type is a plain descriptor array; the renderer, the AJAX handler and
 * the admin editor all read the same descriptor instead of switching on the
 * type name in three different places.
 *
 * Descriptor keys:
 *   label        — human name, shown in the tag generator
 *   group        — grouping in the tag generator
 *   render       — renderer handler id (see CFS_Form_Renderer)
 *   input_type   — HTML input type, for render = "input"
 *   sanitize     — sanitiser id (see sanitize())
 *   rules        — validation rule ids (see validate())
 *   role         — default submission role: name | email | phone | comment
 *   supports     — attribute names the editor offers for this type
 *   submits      — false for decorative types that send no value
 *   multiple     — true when the field submits an array
 *   needs_options— true when the type is meaningless without "options"
 *   css          — CSS modifier suffix: cfs-field--{css}
 *   pattern      — default HTML5 pattern attribute
 *   autocomplete — default autocomplete attribute
 *
 * Add-ons register their own types through the "cfs_field_types" filter and may
 * supply render_cb / sanitize_cb / validate_cb callables to override the
 * built-in handlers.
 */
class CFS_Field_Types {

	/**
	 * Attributes accepted by every input-like type.
	 */
	const COMMON_SUPPORTS = array( 'label', 'placeholder', 'default', 'class', 'icon', 'help', 'error', 'role', 'width' );

	/**
	 * Default HTML5 pattern for the name-like types.
	 *
	 * The ranges are Latin (A–z), Latin-1 and Latin Extended-A minus the two
	 * multiplication signs that sit inside them (À–Ö Ø–ö ø–ſ), and the whole
	 * Cyrillic block (Ѐ–ӿ), which already contains Ё and ё — the old class
	 * listed Ё twice and still turned away every visitor whose name carried a
	 * Ukrainian і, a Polish ł or a Turkish İ. The server-side "letters" rule
	 * accepts any Unicode letter, so a narrower class here could only ever
	 * block a name the plugin would have been happy to store.
	 *
	 * Written as literal characters rather than \p{L}: the pattern attribute
	 * was compiled without the unicode flag before the HTML spec settled on
	 * u/v, and in that reading \p{L} matches the letters of "p{L}" instead.
	 */
	const LETTERS_PATTERN = "[A-Za-zÀ-ÖØ-öø-ſЀ-ӿ\s\-']+";

	/**
	 * Type aliases: alias => canonical type.
	 *
	 * "comment" is the 2.x name for what is now "textarea"; keeping the alias
	 * means templates written from muscle memory (and the legacy adapter) keep
	 * working without a second code path.
	 *
	 * @var array<string, string>
	 */
	const ALIASES = array(
		'comment'        => 'textarea',
		'tel'            => 'phone',
		'checkbox-group' => 'multicheck',
		'checkboxes'     => 'multicheck',
	);

	/**
	 * Cached descriptor table.
	 *
	 * @var array<string, array>|null
	 */
	private static $types = null;

	/**
	 * Return every registered field type.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		if ( null !== self::$types ) {
			return self::$types;
		}

		$types = array(

			// ── Text-like ────────────────────────────────────────────────────
			'text'       => array(
				'label'      => __( 'Текстовое поле', 'contact-form-submissions' ),
				'group'      => 'basic',
				'render'     => 'input',
				'input_type' => 'text',
				'sanitize'   => 'text',
				'rules'      => array(),
				'supports'   => array( 'pattern', 'minlength', 'maxlength', 'autocomplete' ),
				'css'        => 'text',
			),
			'name'       => array(
				'label'    => __( 'Имя', 'contact-form-submissions' ),
				'group'    => 'basic',
				'render'   => 'input',
				'input_type' => 'text',
				'sanitize' => 'text',
				'rules'    => array( 'letters' ),
				'role'     => 'name',
				'supports' => array( 'pattern', 'minlength', 'maxlength', 'autocomplete' ),
				'css'      => 'name',
				'pattern'  => self::LETTERS_PATTERN,
			),
			'surname'    => array(
				'label'    => __( 'Фамилия', 'contact-form-submissions' ),
				'group'    => 'basic',
				'render'   => 'input',
				'input_type' => 'text',
				'sanitize' => 'text',
				'rules'    => array( 'letters' ),
				'supports' => array( 'pattern', 'minlength', 'maxlength', 'autocomplete' ),
				'css'      => 'surname',
				'pattern'  => self::LETTERS_PATTERN,
			),
			'patronymic' => array(
				'label'    => __( 'Отчество', 'contact-form-submissions' ),
				'group'    => 'basic',
				'render'   => 'input',
				'input_type' => 'text',
				'sanitize' => 'text',
				'rules'    => array( 'letters' ),
				'supports' => array( 'pattern', 'minlength', 'maxlength', 'autocomplete' ),
				'css'      => 'patronymic',
				'pattern'  => self::LETTERS_PATTERN,
			),

			// ── Contact ──────────────────────────────────────────────────────
			'email'      => array(
				'label'        => __( 'Email', 'contact-form-submissions' ),
				'group'        => 'contact',
				'render'       => 'input',
				'input_type'   => 'email',
				// Deliberately not sanitize_email(): it strips every character
				// it dislikes, so a typo like "не-почта" arrived as an empty
				// string and an optional field then passed validation with the
				// visitor's input silently thrown away. Plain text sanitising
				// keeps the value intact so the "email" rule can reject it.
				'sanitize'     => 'text',
				'rules'        => array( 'email' ),
				'role'         => 'email',
				'supports'     => array( 'pattern', 'autocomplete' ),
				'css'          => 'email',
				'pattern'      => '[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}',
				'autocomplete' => 'email',
			),
			'phone'      => array(
				'label'        => __( 'Телефон', 'contact-form-submissions' ),
				'group'        => 'contact',
				'render'       => 'input',
				'input_type'   => 'tel',
				'sanitize'     => 'text',
				'rules'        => array( 'phone' ),
				'role'         => 'phone',
				'supports'     => array( 'pattern', 'autocomplete' ),
				'css'          => 'phone',
				'pattern'      => '\+7 \(\d{3}\) \d{3}-\d{2}-\d{2}',
				'autocomplete' => 'tel',
			),
			'url'        => array(
				'label'        => __( 'Ссылка', 'contact-form-submissions' ),
				'group'        => 'contact',
				'render'       => 'input',
				'input_type'   => 'url',
				'sanitize'     => 'url',
				'rules'        => array( 'url' ),
				'supports'     => array( 'autocomplete' ),
				'css'          => 'url',
				'autocomplete' => 'url',
			),

			// ── Numeric / temporal ───────────────────────────────────────────
			'number'     => array(
				'label'      => __( 'Число', 'contact-form-submissions' ),
				'group'      => 'basic',
				'render'     => 'input',
				'input_type' => 'number',
				'sanitize'   => 'text',
				'rules'      => array( 'number' ),
				'supports'   => array( 'min', 'max', 'step' ),
				'css'        => 'number',
			),
			'date'       => array(
				'label'      => __( 'Дата', 'contact-form-submissions' ),
				'group'      => 'basic',
				'render'     => 'input',
				'input_type' => 'date',
				'sanitize'   => 'text',
				'rules'      => array( 'date' ),
				'supports'   => array( 'min', 'max' ),
				'css'        => 'date',
			),

			// ── Multiline ────────────────────────────────────────────────────
			'textarea'   => array(
				'label'    => __( 'Комментарий', 'contact-form-submissions' ),
				'group'    => 'basic',
				'render'   => 'textarea',
				'sanitize' => 'textarea',
				'rules'    => array( 'maxlength' ),
				'role'     => 'comment',
				'supports' => array( 'rows', 'maxlength', 'minlength' ),
				'css'      => 'textarea',
			),

			// ── Choice ───────────────────────────────────────────────────────
			'select'     => array(
				'label'         => __( 'Выпадающий список', 'contact-form-submissions' ),
				'group'         => 'choice',
				'render'        => 'select',
				'sanitize'      => 'text',
				'rules'         => array( 'options' ),
				'supports'      => array( 'options' ),
				'needs_options' => true,
				'css'           => 'select',
			),
			'radio'      => array(
				'label'         => __( 'Радиокнопки', 'contact-form-submissions' ),
				'group'         => 'choice',
				'render'        => 'radio',
				'sanitize'      => 'text',
				'rules'         => array( 'options' ),
				'supports'      => array( 'options' ),
				'needs_options' => true,
				'css'           => 'radio',
			),
			'multicheck' => array(
				'label'         => __( 'Группа чекбоксов', 'contact-form-submissions' ),
				'group'         => 'choice',
				'render'        => 'multicheck',
				'sanitize'      => 'text_array',
				'rules'         => array( 'options' ),
				'supports'      => array( 'options' ),
				'needs_options' => true,
				'multiple'      => true,
				'css'           => 'multicheck',
			),
			'checkbox'   => array(
				'label'    => __( 'Чекбокс', 'contact-form-submissions' ),
				'group'    => 'choice',
				'render'   => 'checkbox',
				'sanitize' => 'bool',
				'rules'    => array(),
				'supports' => array(),
				'css'      => 'checkbox',
			),
			'agreement'  => array(
				'label'    => __( 'Согласие', 'contact-form-submissions' ),
				'group'    => 'choice',
				'render'   => 'agreement',
				'sanitize' => 'bool',
				'rules'    => array(),
				'supports' => array(),
				'css'      => 'agreement',
			),

			// ── Special ──────────────────────────────────────────────────────
			'hidden'     => array(
				'label'    => __( 'Скрытое поле', 'contact-form-submissions' ),
				'group'    => 'special',
				'render'   => 'hidden',
				'sanitize' => 'text',
				'rules'    => array(),
				'supports' => array( 'value', 'source' ),
				'css'      => 'hidden',
			),
			'submit'     => array(
				'label'    => __( 'Кнопка отправки', 'contact-form-submissions' ),
				'group'    => 'special',
				'render'   => 'submit',
				'submits'  => false,
				'supports' => array( 'text', 'icon_before', 'icon_after', 'class' ),
				'css'      => 'submit',
			),
			'step'       => array(
				'label'    => __( 'Разделитель шага', 'contact-form-submissions' ),
				'group'    => 'special',
				'render'   => 'step',
				'submits'  => false,
				'supports' => array( 'label' ),
				'css'      => 'step',
			),
		);

		// Fill in the defaults so consumers never have to test for a missing key.
		foreach ( $types as $type => $descriptor ) {
			$types[ $type ] = array_merge(
				array(
					'label'         => $type,
					'group'         => 'basic',
					'render'        => 'input',
					'input_type'    => 'text',
					'sanitize'      => 'text',
					'rules'         => array(),
					'role'          => '',
					'supports'      => array(),
					'submits'       => true,
					'multiple'      => false,
					'needs_options' => false,
					'css'           => $type,
					'pattern'       => '',
					'autocomplete'  => '',
				),
				$descriptor
			);

			if ( ! empty( $types[ $type ]['submits'] ) ) {
				$types[ $type ]['supports'] = array_values(
					array_unique( array_merge( self::COMMON_SUPPORTS, $types[ $type ]['supports'] ) )
				);
			}
		}

		/**
		 * Filter the field type registry.
		 *
		 * @param array<string, array> $types Descriptors keyed by type name.
		 */
		self::$types = (array) apply_filters( 'cfs_field_types', $types );

		return self::$types;
	}

	/**
	 * Reset the cached registry (used by tests and after switching locale).
	 */
	public static function flush_cache(): void {
		self::$types = null;
	}

	/**
	 * Resolve an alias to its canonical type name.
	 *
	 * @param string $type Type as written in the template.
	 * @return string
	 */
	public static function canonical( string $type ): string {
		$type = strtolower( trim( $type ) );
		return self::ALIASES[ $type ] ?? $type;
	}

	/**
	 * Whether a type (or alias) is registered.
	 *
	 * @param string $type Type name.
	 * @return bool
	 */
	public static function exists( string $type ): bool {
		return isset( self::all()[ self::canonical( $type ) ] );
	}

	/**
	 * Get one descriptor.
	 *
	 * @param string $type Type name or alias.
	 * @return array|null
	 */
	public static function get( string $type ) {
		return self::all()[ self::canonical( $type ) ] ?? null;
	}

	/**
	 * Default label for a type, used when the tag omits one.
	 *
	 * @param string $type Type name.
	 * @return string
	 */
	public static function default_label( string $type ): string {
		$descriptor = self::get( $type );
		return $descriptor ? (string) $descriptor['label'] : ucfirst( $type );
	}

	/**
	 * Parse an options string "Метка:значение,Метка2:значение2" into value => label.
	 *
	 * A comma inside a label can be escaped three ways — "\,", "&#44;" and
	 * "&comma;" — because the first survives the template parser and the other
	 * two survive being pasted from the 2.x shortcode syntax.
	 *
	 * An option without a colon uses its own text as both label and value.
	 *
	 * Returns an ordered list of value/label pairs rather than a value => label
	 * map: PHP silently rewrites decimal string keys into integers, so a map
	 * built from options="Да:1,Нет:2" would carry int keys that never match the
	 * strings arriving in $_POST under a strict comparison. A list sidesteps
	 * the whole class of bug instead of guarding against it at every use site.
	 *
	 * @param string $raw Raw options string.
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function parse_options( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$placeholder = "\u{F8FF}";
		$raw         = str_replace( array( '\\,', '&#44;', '&comma;' ), $placeholder, $raw );

		$result = array();
		$seen   = array();

		foreach ( explode( ',', $raw ) as $option ) {
			$option = str_replace( $placeholder, ',', $option );
			$parts  = explode( ':', $option, 2 );

			if ( 2 === count( $parts ) ) {
				$value = self::decode_entities( trim( $parts[1] ) );
				$label = self::decode_entities( trim( $parts[0] ) );
			} else {
				$label = self::decode_entities( trim( $parts[0] ) );
				$value = $label;
			}

			if ( '' === $label ) {
				continue;
			}

			if ( '' === $value ) {
				$value = (string) ( count( $result ) + 1 );
			}

			if ( isset( $seen[ $value ] ) ) {
				continue; // Duplicate value — first one wins.
			}
			$seen[ $value ] = true;

			$result[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		return $result;
	}

	/**
	 * Allowed values of a compiled field, in template order.
	 *
	 * @param array $field Compiled field definition.
	 * @return string[]
	 */
	public static function option_values( array $field ): array {
		$values = array();
		foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
			$values[] = (string) $option['value'];
		}
		return $values;
	}

	/**
	 * Label of one option, falling back to the value itself.
	 *
	 * @param array  $field Compiled field definition.
	 * @param string $value Option value.
	 * @return string
	 */
	public static function option_label( array $field, string $value ): string {
		foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
			if ( (string) $option['value'] === $value ) {
				return (string) $option['label'];
			}
		}
		return $value;
	}

	/**
	 * Decode HTML entities in a value that came out of the template.
	 *
	 * The template is stored kses-clean, and kses escapes any bare "<", ">" or
	 * "&" it finds in plain text — including inside a field tag, which it sees
	 * as text. Without decoding here, a label written as `Цена < 100` would be
	 * stored as `Цена &lt; 100` and then escaped a second time at render,
	 * showing the entity to the visitor.
	 *
	 * Deliberately NOT applied to a raw options string: decoding "&#44;" before
	 * the string is split would turn an escaped comma into a real separator.
	 *
	 * @param string $value Value taken from a tag attribute.
	 * @return string
	 */
	public static function decode_entities( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Sanitise a raw submitted value according to its type.
	 *
	 * @param string $type Field type.
	 * @param mixed  $raw  Raw value from $_POST (already unslashed).
	 * @return string|array
	 */
	public static function sanitize( string $type, $raw ) {
		$descriptor = self::get( $type );
		if ( null === $descriptor ) {
			return '';
		}

		if ( isset( $descriptor['sanitize_cb'] ) && is_callable( $descriptor['sanitize_cb'] ) ) {
			return call_user_func( $descriptor['sanitize_cb'], $raw, $descriptor );
		}

		switch ( $descriptor['sanitize'] ) {
			case 'text_array':
				// Values are matched against the compiled option list, so they
				// need no key-mangling — sanitize_key() would flatten "Москва"
				// to an empty string and lose the choice entirely.
				$values = is_array( $raw ) ? $raw : array( $raw );
				$clean  = array();
				foreach ( $values as $value ) {
					if ( is_array( $value ) ) {
						continue;
					}
					$value = sanitize_text_field( (string) $value );
					if ( '' !== $value ) {
						$clean[] = $value;
					}
				}
				return $clean;

			case 'bool':
				// Unchecked boxes are absent from $_POST entirely; anything that
				// did arrive counts as checked.
				return '' === (string) $raw ? '' : '1';

			case 'email':
				return sanitize_email( (string) $raw );

			case 'url':
				return esc_url_raw( (string) $raw );

			case 'textarea':
				return sanitize_textarea_field( (string) $raw );

			case 'text':
			default:
				return is_array( $raw ) ? '' : sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * Validate a sanitised value against its compiled field definition.
	 *
	 * @param array $field Compiled field definition (see CFS_Form::get_schema()).
	 * @param mixed $value Sanitised value.
	 * @return string Error message, or '' when the value is acceptable.
	 */
	public static function validate( array $field, $value ): string {
		$type       = (string) ( $field['type'] ?? '' );
		$descriptor = self::get( $type );
		if ( null === $descriptor ) {
			return '';
		}

		$is_empty = is_array( $value ) ? empty( $value ) : ( '' === (string) $value );

		// Required is checked for every type, including the boolean ones where
		// "not checked" means "absent from the request".
		if ( ! empty( $field['required'] ) && $is_empty ) {
			return '' !== (string) ( $field['attrs']['error'] ?? '' )
				? (string) $field['attrs']['error']
				: __( 'Обязательное поле.', 'contact-form-submissions' );
		}

		if ( $is_empty ) {
			return ''; // Optional and empty — nothing else to check.
		}

		if ( isset( $descriptor['validate_cb'] ) && is_callable( $descriptor['validate_cb'] ) ) {
			return (string) call_user_func( $descriptor['validate_cb'], $value, $field, $descriptor );
		}

		foreach ( (array) $descriptor['rules'] as $rule ) {
			$error = self::apply_rule( $rule, $value, $field );
			if ( '' !== $error ) {
				// A field-level error="" message replaces whatever the rule said.
				return '' !== (string) ( $field['attrs']['error'] ?? '' )
					? (string) $field['attrs']['error']
					: $error;
			}
		}

		$error = self::pattern_error( $field, $value );
		if ( '' !== $error ) {
			return '' !== (string) ( $field['attrs']['error'] ?? '' )
				? (string) $field['attrs']['error']
				: $error;
		}

		return '';
	}

	/**
	 * Check a value against a pattern the form author wrote.
	 *
	 * Only author-supplied patterns are enforced, never the defaults a type
	 * carries. A default describes the *displayed* value — the phone mask sends
	 * "79991234567" while its pattern spells out "+7 (999) 999-99-99" — so
	 * applying it to what arrives would reject every honest submission.
	 *
	 * An author's own pattern has no such split: whatever they constrained in
	 * the browser is what the browser sends, and leaving it unchecked meant a
	 * [text code pattern="\d{6}"] field held only as long as the visitor used a
	 * browser. curl never had to agree.
	 *
	 * @param array $field Compiled field definition.
	 * @param mixed $value Sanitised value.
	 * @return string Error message or ''.
	 */
	private static function pattern_error( array $field, $value ): string {
		if ( 'author' !== (string) ( $field['pattern_from'] ?? '' ) || is_array( $value ) ) {
			return '';
		}

		$pattern = (string) ( $field['attrs']['pattern'] ?? '' );
		if ( '' === $pattern ) {
			return '';
		}

		/*
		 * chr(1) as the delimiter: every printable character can legitimately
		 * appear inside a regex, and escaping the chosen one correctly would
		 * mean parsing the pattern first. HTML5 anchors the attribute and
		 * compiles it with unicode semantics, which is what ^(?:…)$ and /u
		 * reproduce here.
		 */
		$regex = chr( 1 ) . '^(?:' . $pattern . ')$' . chr( 1 ) . 'u';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unusable pattern is handled below, not reported to the visitor.
		$match = @preg_match( $regex, (string) $value );

		/*
		 * false means PCRE refused the pattern outright — an ECMAScript
		 * construct the two engines do not share, or the backtrack limit. The
		 * visitor is not the one who wrote it, so the check is skipped rather
		 * than turned into a rejection nobody can act on.
		 */
		if ( false === $match || 1 === $match ) {
			return '';
		}

		return __( 'Значение не соответствует требуемому формату.', 'contact-form-submissions' );
	}

	/**
	 * Apply a single validation rule.
	 *
	 * @param string $rule  Rule id.
	 * @param mixed  $value Sanitised value.
	 * @param array  $field Compiled field definition.
	 * @return string Error message or ''.
	 */
	private static function apply_rule( string $rule, $value, array $field ): string {
		$string = is_array( $value ) ? '' : (string) $value;

		switch ( $rule ) {
			case 'letters':
				if ( ! preg_match( "/^[\p{L}\s\-']+$/u", $string ) ) {
					return __( 'Допустимы только буквы, дефис, апостроф и пробел.', 'contact-form-submissions' );
				}
				return '';

			case 'phone':
				$digits = (string) preg_replace( '/\D/', '', $string );
				if ( strlen( $digits ) < 10 || strlen( $digits ) > 11 ) {
					return __( 'Введите корректный номер телефона (10–11 цифр).', 'contact-form-submissions' );
				}
				return '';

			case 'email':
				if ( ! is_email( $string ) ) {
					return __( 'Введите корректный email.', 'contact-form-submissions' );
				}
				return '';

			case 'url':
				if ( ! filter_var( $string, FILTER_VALIDATE_URL ) ) {
					return __( 'Введите корректный URL (например, https://…).', 'contact-form-submissions' );
				}
				return '';

			case 'number':
				if ( ! is_numeric( $string ) ) {
					return __( 'Введите числовое значение.', 'contact-form-submissions' );
				}
				$number = (float) $string;
				$min    = (string) ( $field['constraints']['min'] ?? '' );
				$max    = (string) ( $field['constraints']['max'] ?? '' );
				$step   = (string) ( $field['constraints']['step'] ?? '' );

				if ( '' !== $min && $number < (float) $min ) {
					/* translators: %s: minimum value */
					return sprintf( __( 'Минимальное значение: %s.', 'contact-form-submissions' ), $min );
				}
				if ( '' !== $max && $number > (float) $max ) {
					/* translators: %s: maximum value */
					return sprintf( __( 'Максимальное значение: %s.', 'contact-form-submissions' ), $max );
				}
				if ( '' !== $step && (float) $step > 0 ) {
					$base      = '' !== $min ? (float) $min : 0.0;
					$offset    = $number - $base;
					$remainder = fmod( $offset, (float) $step );
					// Float modulo is never exactly zero for values like 0.3;
					// compare against a tolerance scaled to the step.
					$tolerance = abs( (float) $step ) * 1e-6;
					if ( abs( $remainder ) > $tolerance && abs( abs( $remainder ) - abs( (float) $step ) ) > $tolerance ) {
						return __( 'Значение не соответствует шагу.', 'contact-form-submissions' );
					}
				}
				return '';

			case 'date':
				$date = DateTime::createFromFormat( 'Y-m-d', $string );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $string ) {
					return __( 'Некорректный формат даты.', 'contact-form-submissions' );
				}
				$min = (string) ( $field['constraints']['min'] ?? '' );
				$max = (string) ( $field['constraints']['max'] ?? '' );
				if ( '' !== $min && $string < $min ) {
					/* translators: %s: minimum date */
					return sprintf( __( 'Дата не может быть раньше %s.', 'contact-form-submissions' ), $min );
				}
				if ( '' !== $max && $string > $max ) {
					/* translators: %s: maximum date */
					return sprintf( __( 'Дата не может быть позже %s.', 'contact-form-submissions' ), $max );
				}
				return '';

			case 'maxlength':
				$max = (int) ( $field['constraints']['maxlength'] ?? 0 );
				if ( $max <= 0 ) {
					$max = (int) get_option( 'cfs_max_comment_length', 1000 );
				}
				if ( $max > 0 && mb_strlen( $string ) > $max ) {
					/* translators: %d: maximum characters */
					return sprintf( __( 'Слишком длинное значение. Максимум %d символов.', 'contact-form-submissions' ), $max );
				}
				$min = (int) ( $field['constraints']['minlength'] ?? 0 );
				if ( $min > 0 && mb_strlen( $string ) < $min ) {
					/* translators: %d: minimum characters */
					return sprintf( __( 'Слишком короткое значение. Минимум %d символов.', 'contact-form-submissions' ), $min );
				}
				return '';

			case 'options':
				$allowed = self::option_values( $field );
				if ( empty( $allowed ) ) {
					return '';
				}
				$selected = is_array( $value ) ? $value : array( $string );
				foreach ( $selected as $one ) {
					if ( ! in_array( (string) $one, $allowed, true ) ) {
						return __( 'Недопустимое значение.', 'contact-form-submissions' );
					}
				}
				return '';
		}

		return '';
	}

	/**
	 * Human-readable rendering of a stored value, for the admin card, CSV and mail.
	 *
	 * @param array $field Compiled field definition.
	 * @param mixed $value Stored value.
	 * @return string
	 */
	public static function display( array $field, $value ): string {
		$type = (string) ( $field['type'] ?? '' );

		if ( in_array( $type, array( 'checkbox', 'agreement' ), true ) ) {
			return '' === (string) $value
				? __( 'Нет', 'contact-form-submissions' )
				: __( 'Да', 'contact-form-submissions' );
		}

		if ( ! empty( $field['multiple'] ) || 'multicheck' === $type ) {
			$values = is_array( $value ) ? $value : array_filter( explode( ',', (string) $value ) );
			$labels = array();
			foreach ( $values as $one ) {
				$labels[] = self::option_label( $field, (string) $one );
			}
			return implode( ', ', $labels );
		}

		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			return self::option_label( $field, (string) $value );
		}

		if ( 'phone' === $type ) {
			return self::format_phone( (string) $value );
		}

		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	/**
	 * Format a stored digits-only phone number back into the display mask.
	 *
	 * @param string $digits Raw stored value.
	 * @return string
	 */
	public static function format_phone( string $digits ): string {
		$clean = (string) preg_replace( '/\D/', '', $digits );

		if ( 11 === strlen( $clean ) && ( '7' === $clean[0] || '8' === $clean[0] ) ) {
			return sprintf(
				'+7 (%s) %s-%s-%s',
				substr( $clean, 1, 3 ),
				substr( $clean, 4, 3 ),
				substr( $clean, 7, 2 ),
				substr( $clean, 9, 2 )
			);
		}

		return '' === $clean ? $digits : $clean;
	}
}
