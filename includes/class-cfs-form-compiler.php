<?php
/**
 * Template compiler — turns a template into a schema plus a render plan.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Form_Compiler
 *
 * Everything downstream — the renderer, the AJAX handler, the mail templates,
 * the admin card and the CSV export — reads the compiled schema. The template
 * text itself is parsed exactly once, when the form is saved.
 */
class CFS_Form_Compiler {

	/**
	 * Schema format version, stored with every compiled schema and every
	 * submission so old data stays readable after future changes.
	 */
	const SCHEMA_VERSION = 3;

	/**
	 * Field names the plugin uses for its own machinery.
	 *
	 * @var string[]
	 */
	const RESERVED_NAMES = array(
		'cfs',
		'action',
		'nonce',
		'page_url',
		'cfs_form_id',
		'cfs_timestamp',
		'cfs_hp_w',
		'cfs_hp_x',
		'cfs_instance',
		'cfs_hash',
		'_wp_http_referer',
		'submit',
	);

	/**
	 * Roles that map onto dedicated submission columns.
	 *
	 * @var string[]
	 */
	const ROLES = array( 'name', 'email', 'phone', 'comment' );

	/**
	 * Compile a template.
	 *
	 * @param string $template Sanitised template text.
	 * @return array{schema: array, errors: array}
	 */
	public static function compile( string $template ): array {
		$segments = CFS_Template_Parser::parse( $template );

		$fields      = array();
		$order       = array();
		$roles       = array();
		$plan        = array();
		$errors      = array();
		$steps       = array( array() );
		$step_labels = array( '' );
		$step_index  = 0;
		$has_submit  = false;
		$step_dirty  = false; // Whether the current step already has content.

		foreach ( $segments as $segment ) {

			if ( 'html' === $segment['kind'] ) {
				$plan[] = array(
					'kind'    => 'html',
					'content' => $segment['content'],
					'step'    => $step_index,
				);
				if ( '' !== trim( wp_strip_all_tags( $segment['content'] ) ) ) {
					$step_dirty = true;
				}
				continue;
			}

			$tag  = $segment['tag'];
			$type = CFS_Field_Types::canonical( $tag['type'] );

			// ── Unknown type ────────────────────────────────────────────────
			if ( ! CFS_Field_Types::exists( $type ) ) {
				$errors[] = self::error(
					sprintf(
						/* translators: %s: field type as written in the template */
						__( 'Неизвестный тип поля «%s».', 'contact-form-submissions' ),
						$tag['type']
					),
					$tag
				);
				continue;
			}

			$descriptor = CFS_Field_Types::get( $type );
			$attrs      = self::normalize_attrs( $tag, $type, $descriptor, $errors );

			// ── Step separator ──────────────────────────────────────────────
			if ( 'step' === $type ) {
				$label = (string) ( $attrs['label'] ?? '' );

				if ( 0 === $step_index && ! $step_dirty && empty( $steps[0] ) ) {
					// A [step] at the very top labels the first step instead of
					// creating an empty one before it.
					$step_labels[0] = $label;
					continue;
				}

				++$step_index;
				$steps[ $step_index ]       = array();
				$step_labels[ $step_index ] = $label;
				$step_dirty                 = false;
				$plan[]                     = array(
					'kind' => 'step',
					'step' => $step_index,
				);
				continue;
			}

			// ── Submit button ───────────────────────────────────────────────
			if ( 'submit' === $type ) {
				$has_submit = true;
				$step_dirty = true;
				$plan[]     = array(
					'kind'  => 'submit',
					'attrs' => $attrs,
					'step'  => $step_index,
				);
				continue;
			}

			// ── Regular field ───────────────────────────────────────────────
			$name = self::resolve_name( $tag, $type, $fields, $errors );
			if ( '' === $name ) {
				continue;
			}

			$field = self::build_field( $name, $type, $tag, $attrs, $descriptor );

			if ( ! empty( $descriptor['needs_options'] ) && empty( $field['options'] ) ) {
				$errors[] = self::error(
					sprintf(
						/* translators: 1: field name, 2: field type */
						__( 'Поле «%1$s» типа «%2$s» без атрибута options — задайте варианты в формате options="Метка:значение,Метка2:значение2".', 'contact-form-submissions' ),
						$name,
						$type
					),
					$tag
				);
				continue;
			}

			// A role may only be claimed once — the first field wins so the
			// submission columns stay deterministic.
			if ( '' !== $field['role'] ) {
				if ( isset( $roles[ $field['role'] ] ) ) {
					$errors[] = self::error(
						sprintf(
							/* translators: 1: role name, 2: field that already claimed it */
							__( 'Роль «%1$s» уже занята полем «%2$s» — значение этого поля попадёт только в JSON заявки.', 'contact-form-submissions' ),
							$field['role'],
							$roles[ $field['role'] ]
						),
						$tag,
						'warning'
					);
					$field['role'] = '';
				} else {
					$roles[ $field['role'] ] = $name;
				}
			}

			$fields[ $name ]      = $field;
			$order[]              = $name;
			$steps[ $step_index ][] = $name;
			$step_dirty           = true;

			$plan[] = array(
				'kind' => 'field',
				'name' => $name,
				'step' => $step_index,
			);
		}

		// ── Whole-template diagnostics ──────────────────────────────────────
		if ( empty( $fields ) ) {
			$errors[] = self::error(
				__( 'В шаблоне нет ни одного поля — форма не будет выведена.', 'contact-form-submissions' ),
				null
			);
		}

		if ( ! $has_submit && ! empty( $fields ) ) {
			$errors[] = self::error(
				__( 'В шаблоне нет тега [submit] — кнопка отправки будет добавлена в конец формы.', 'contact-form-submissions' ),
				null,
				'warning'
			);
		}

		if ( ! CFS_Template_Sanitizer::is_balanced( $template ) ) {
			$errors[] = self::error(
				__( 'В разметке есть незакрытый HTML-тег.', 'contact-form-submissions' ),
				null,
				'warning'
			);
		}

		// Drop the trailing empty step left by a [step] at the end of a template.
		$last = count( $steps ) - 1;
		if ( $last > 0 && empty( $steps[ $last ] ) ) {
			unset( $steps[ $last ], $step_labels[ $last ] );
		}

		$schema = array(
			'version'     => self::SCHEMA_VERSION,
			'hash'        => md5( $template ),
			'order'       => $order,
			'fields'      => $fields,
			'roles'       => $roles,
			'steps'       => count( $steps ) > 1 ? array_values( $steps ) : array(),
			'step_labels' => count( $steps ) > 1 ? array_values( $step_labels ) : array(),
			'has_submit'  => $has_submit,
			'plan'        => $plan,
		);

		/**
		 * Filter the compiled schema.
		 *
		 * @param array  $schema   Compiled schema.
		 * @param string $template Source template.
		 */
		$schema = (array) apply_filters( 'cfs_compiled_schema', $schema, $template );

		return array(
			'schema' => $schema,
			'errors' => $errors,
		);
	}

	/**
	 * Resolve the field name for a tag, generating one when it is omitted.
	 *
	 * @param array  $tag    Parsed tag.
	 * @param string $type   Canonical type.
	 * @param array  $fields Fields compiled so far.
	 * @param array  $errors Error list, appended to in place.
	 * @return string Field name, or '' when the tag must be skipped.
	 */
	private static function resolve_name( array $tag, string $type, array $fields, array &$errors ): string {
		$name = (string) $tag['name'];

		// Omitted name → derive one from the type, numbering repeats.
		if ( '' === $name ) {
			$name = $type;
			$i    = 2;
			while ( isset( $fields[ $name ] ) ) {
				$name = $type . '_' . $i;
				++$i;
			}
			return $name;
		}

		$name = strtolower( $name );

		if ( ! preg_match( '/^[a-z][a-z0-9_-]*$/', $name ) ) {
			$errors[] = self::error(
				sprintf(
					/* translators: %s: field name as written in the template */
					__( 'Недопустимое имя поля «%s»: разрешены латинские буквы, цифры, дефис и подчёркивание, начинаться должно с буквы.', 'contact-form-submissions' ),
					$tag['name']
				),
				$tag
			);
			return '';
		}

		if ( in_array( $name, self::RESERVED_NAMES, true ) ) {
			$errors[] = self::error(
				sprintf(
					/* translators: %s: reserved field name */
					__( 'Имя «%s» зарезервировано плагином, выберите другое.', 'contact-form-submissions' ),
					$name
				),
				$tag
			);
			return '';
		}

		if ( isset( $fields[ $name ] ) ) {
			$errors[] = self::error(
				sprintf(
					/* translators: %s: duplicated field name */
					__( 'Поле с именем «%s» уже есть в форме — имена должны быть уникальными.', 'contact-form-submissions' ),
					$name
				),
				$tag
			);
			return '';
		}

		return $name;
	}

	/**
	 * Normalise tag attributes: positional values, entity decoding, unknown keys.
	 *
	 * @param array  $tag        Parsed tag.
	 * @param string $type       Canonical type.
	 * @param array  $descriptor Type descriptor.
	 * @param array  $errors     Error list, appended to in place.
	 * @return array<string, string>
	 */
	private static function normalize_attrs( array $tag, string $type, array $descriptor, array &$errors ): array {
		$attrs = array();

		foreach ( $tag['attrs'] as $key => $value ) {
			// "options" keeps its entities: decoding "&#44;" before the string
			// is split would turn an escaped comma into a real separator.
			$attrs[ $key ] = 'options' === $key
				? (string) $value
				: CFS_Field_Types::decode_entities( (string) $value );
		}

		// A bare quoted string is the label — or the button text for [submit].
		if ( ! empty( $tag['positional'] ) ) {
			$positional = CFS_Field_Types::decode_entities( (string) $tag['positional'][0] );
			$target     = 'submit' === $type ? 'text' : 'label';
			if ( ! isset( $attrs[ $target ] ) ) {
				$attrs[ $target ] = $positional;
			}
		}

		$supported = (array) $descriptor['supports'];
		foreach ( array_keys( $attrs ) as $key ) {
			if ( ! in_array( $key, $supported, true ) ) {
				$errors[] = self::error(
					sprintf(
						/* translators: 1: attribute name, 2: field type */
						__( 'Атрибут «%1$s» не поддерживается типом «%2$s» и будет проигнорирован.', 'contact-form-submissions' ),
						$key,
						$type
					),
					$tag,
					'warning'
				);
			}
		}

		return $attrs;
	}

	/**
	 * Assemble a compiled field definition.
	 *
	 * @param string $name       Field name.
	 * @param string $type       Canonical type.
	 * @param array  $tag        Parsed tag.
	 * @param array  $attrs      Normalised attributes.
	 * @param array  $descriptor Type descriptor.
	 * @return array
	 */
	private static function build_field( string $name, string $type, array $tag, array $attrs, array $descriptor ): array {
		$label = (string) ( $attrs['label'] ?? '' );
		if ( '' === $label ) {
			$label = CFS_Field_Types::default_label( $type );
		}

		$options = array();
		if ( isset( $attrs['options'] ) ) {
			$options = CFS_Field_Types::parse_options( (string) $attrs['options'] );
		}

		$role = (string) ( $attrs['role'] ?? $descriptor['role'] );
		if ( ! in_array( $role, self::ROLES, true ) ) {
			$role = '';
		}

		$constraints = array();
		foreach ( array( 'min', 'max', 'step', 'maxlength', 'minlength' ) as $key ) {
			if ( isset( $attrs[ $key ] ) && '' !== (string) $attrs[ $key ] ) {
				$constraints[ $key ] = (string) $attrs[ $key ];
			}
		}

		// Attributes consumed into dedicated keys are removed so the renderer
		// cannot emit them twice.
		unset( $attrs['label'], $attrs['options'], $attrs['role'] );

		if ( ! isset( $attrs['pattern'] ) && '' !== (string) $descriptor['pattern'] ) {
			$attrs['pattern'] = (string) $descriptor['pattern'];
		}
		if ( ! isset( $attrs['autocomplete'] ) && '' !== (string) $descriptor['autocomplete'] ) {
			$attrs['autocomplete'] = (string) $descriptor['autocomplete'];
		}

		return array(
			'name'        => $name,
			'type'        => $type,
			'label'       => $label,
			'required'    => (bool) $tag['required'],
			'role'        => $role,
			'options'     => $options,
			'constraints' => $constraints,
			'attrs'       => $attrs,
			'multiple'    => (bool) $descriptor['multiple'],
			'submits'     => (bool) $descriptor['submits'],
		);
	}

	/**
	 * Build one diagnostic entry.
	 *
	 * @param string     $message Human-readable message.
	 * @param array|null $tag     Tag the message refers to, when there is one.
	 * @param string     $level   'error' or 'warning'.
	 * @return array
	 */
	private static function error( string $message, $tag = null, string $level = 'error' ): array {
		return array(
			'level'   => $level,
			'message' => $message,
			'line'    => is_array( $tag ) ? (int) $tag['line'] : 0,
			'raw'     => is_array( $tag ) ? (string) $tag['raw'] : '',
		);
	}
}
