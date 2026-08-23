<?php
/**
 * Form renderer — walks the compiled plan and emits HTML.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Form_Renderer
 *
 * One instance per rendered form. HTML segments of the template are emitted
 * as-is — they were sanitised once, when the form was saved, and running kses
 * over them again here would strip the SVG icons and ARIA attributes this
 * class generates.
 */
class CFS_Form_Renderer {

	/**
	 * Form being rendered.
	 *
	 * @var CFS_Form
	 */
	private $form;

	/**
	 * Compiled schema.
	 *
	 * @var array
	 */
	private $schema;

	/**
	 * Presentation settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Instance number within the current request.
	 *
	 * @var int
	 */
	private $instance;

	/**
	 * Unique suffix for element IDs: "{form id}-{instance}".
	 *
	 * @var string
	 */
	private $uid;

	/**
	 * Field widths accepted by the "width" attribute.
	 *
	 * @var array<string, string>
	 */
	private static $widths = array(
		'1/2' => 'w-1-2',
		'1/3' => 'w-1-3',
		'2/3' => 'w-2-3',
		'1/4' => 'w-1-4',
		'3/4' => 'w-3-4',
		'1/1' => 'w-1-1',
	);

	/**
	 * Constructor.
	 *
	 * @param CFS_Form $form     Form to render.
	 * @param array    $overrides Shortcode-level overrides (css_class, container…).
	 */
	public function __construct( CFS_Form $form, array $overrides = array() ) {
		$this->form     = $form;
		$this->schema   = $form->get_schema();
		$this->settings = array_merge( $form->get_settings(), array_filter( $overrides, 'strlen' ) );
		$this->instance = $form->next_instance();
		$this->uid      = $form->get_id() . '-' . $this->instance;
	}

	/**
	 * Render the whole form.
	 *
	 * @return string
	 */
	public function render(): string {
		if ( ! $this->form->is_renderable() ) {
			return $this->render_broken_notice();
		}

		$is_dialog = 'dialog' === ( $this->settings['container'] ?? 'div' );
		$wrap_id   = 'cfs-wrap-' . $this->uid;

		$html = '';

		if ( $is_dialog ) {
			$html .= $this->render_modal_trigger( $wrap_id );
		}

		$tag = $is_dialog ? 'dialog' : 'div';

		$html .= sprintf(
			'<%s class="%s" id="%s">',
			$tag,
			esc_attr( $this->wrap_classes( $is_dialog ) ),
			esc_attr( $wrap_id )
		);

		if ( $is_dialog ) {
			$html .= sprintf(
				'<button type="button" class="cfs-modal-close" data-dialog="%s" aria-label="%s">&#x2715;</button>',
				esc_attr( $wrap_id ),
				esc_attr__( 'Закрыть', 'contact-form-submissions' )
			);
		}

		$html .= '<div class="cfs-form-message" role="alert" aria-live="polite" style="display:none;"></div>';
		$html .= $this->render_form_element();
		$html .= sprintf( '</%s>', $tag );

		/**
		 * Filter the rendered form HTML.
		 *
		 * The second argument stays the form id, as in 2.x; the third is now
		 * the form object instead of the shortcode attribute array.
		 *
		 * @param string   $html     Rendered HTML.
		 * @param int      $form_id  Form post ID.
		 * @param CFS_Form $form     Form being rendered.
		 * @param int      $instance Instance number within the request.
		 */
		return (string) apply_filters( 'cfs_form_html', $html, $this->form->get_id(), $this->form, $this->instance );
	}

	/**
	 * Wrapper classes.
	 *
	 * @param bool $is_dialog Whether the form renders inside a <dialog>.
	 * @return string
	 */
	private function wrap_classes( bool $is_dialog ): string {
		$classes = array( 'cfs-form-wrap' );

		if ( $is_dialog ) {
			$classes[] = 'cfs-form-wrap--dialog';
		}
		if ( $this->form->is_multi_step() ) {
			$classes[] = 'cfs-form-wrap--steps';
		}

		$theme = (string) ( $this->settings['style_theme'] ?? '' );
		if ( '' === $theme ) {
			$theme = (string) get_option( 'cfs_style_theme', 'default' );
		}
		if ( in_array( $theme, array( 'underline', 'outlined-top', 'filled', 'contained', 'left-label' ), true ) ) {
			$classes[] = 'cfs-style--' . $theme;
		}

		foreach ( $this->split_classes( (string) ( $this->settings['css_class'] ?? '' ) ) as $extra ) {
			$classes[] = $extra;
		}

		return implode( ' ', $classes );
	}

	/**
	 * The trigger button that opens a modal form.
	 *
	 * @param string $wrap_id ID of the <dialog> element.
	 * @return string
	 */
	private function render_modal_trigger( string $wrap_id ): string {
		$classes = array_merge(
			array( 'cfs-modal-btn' ),
			$this->split_classes( (string) ( $this->settings['modal_button_class'] ?? '' ) )
		);

		return sprintf(
			'<button type="button" class="%s" data-dialog="%s" aria-haspopup="dialog" aria-controls="%s">%s%s%s</button>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $wrap_id ),
			esc_attr( $wrap_id ),
			CFS_Icons::render( (string) ( $this->settings['modal_button_icon_before'] ?? '' ) ),
			esc_html( (string) ( $this->settings['modal_button_text'] ?? '' ) ),
			CFS_Icons::render( (string) ( $this->settings['modal_button_icon_after'] ?? '' ) )
		);
	}

	/**
	 * The <form> element with its hidden fields and body.
	 *
	 * @return string
	 */
	private function render_form_element(): string {
		$attrs = array(
			'class'          => 'cfs-form',
			'id'             => 'cfs-form-' . $this->uid,
			'method'         => 'post',
			'novalidate'     => true,
			'data-form-id'   => (string) $this->form->get_id(),
			'data-instance'  => (string) $this->instance,
			'data-cfs-config' => (string) wp_json_encode( $this->build_config() ),
		);

		return '<form' . $this->attrs_to_html( $attrs ) . '>'
			. $this->render_hidden_system_fields()
			. $this->render_body()
			. '</form>';
	}

	/**
	 * Honeypots and the fields the AJAX handler needs.
	 *
	 * @return string
	 */
	private function render_hidden_system_fields(): string {
		$html = '<div class="cfs-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;overflow:hidden;">'
			. '<input type="text" name="cfs_hp_w" value="" tabindex="-1" autocomplete="new-password">'
			. '<input type="text" name="cfs_hp_x" value="" tabindex="-1" autocomplete="new-password">'
			. '</div>';

		$hidden = array(
			'action'        => 'cfs_submit_form',
			'cfs_form_id'   => (string) $this->form->get_id(),
			'cfs_timestamp' => (string) time(),
			'cfs_hash'      => $this->form->get_hash(),
			'cfs_instance'  => (string) $this->instance,
			'cfs_page_url'  => $this->current_url(),
		);

		foreach ( $hidden as $name => $value ) {
			$html .= sprintf(
				'<input type="hidden" name="%s" value="%s">',
				esc_attr( $name ),
				esc_attr( $value )
			);
		}

		return $html;
	}

	/**
	 * Form body: either a flat plan walk or the multi-step wizard.
	 *
	 * @return string
	 */
	private function render_body(): string {
		$plan = (array) ( $this->schema['plan'] ?? array() );

		// The submit button is pulled out of the flow in wizard mode — a submit
		// button sitting inside step 1 would be unreachable and confusing.
		$multi_step  = $this->form->is_multi_step();
		$submit_tag  = null;
		$rendered    = array();

		foreach ( $plan as $entry ) {
			if ( 'submit' === $entry['kind'] ) {
				$submit_tag = $entry;
				if ( $multi_step ) {
					continue;
				}
			}
			$rendered[] = $entry;
		}

		$html = '';

		if ( $multi_step ) {
			$html .= $this->render_stepper();
			$html .= $this->render_steps( $rendered );
			$html .= $this->render_step_nav( $submit_tag );

			return $html;
		}

		foreach ( $rendered as $entry ) {
			$html .= $this->render_entry( $entry );
		}

		// No [submit] in the template — add the button so the form still works.
		if ( null === $submit_tag ) {
			$html .= '<div class="cfs-field cfs-field--submit">' . $this->render_submit_button( array(), false ) . '</div>';
		}

		return $html;
	}

	/**
	 * Group plan entries into .cfs-step blocks.
	 *
	 * @param array $plan Plan entries, submit already removed.
	 * @return string
	 */
	private function render_steps( array $plan ): string {
		$total  = count( (array) $this->schema['steps'] );
		$html   = '';

		for ( $step = 0; $step < $total; $step++ ) {
			$body = '';
			foreach ( $plan as $entry ) {
				if ( (int) $entry['step'] !== $step || 'step' === $entry['kind'] ) {
					continue;
				}
				$body .= $this->render_entry( $entry );
			}

			$html .= sprintf(
				'<div class="cfs-step" data-step="%d"%s>%s</div>',
				$step,
				0 === $step ? '' : ' hidden',
				$body
			);
		}

		return $html;
	}

	/**
	 * Step progress header.
	 *
	 * @return string
	 */
	private function render_stepper(): string {
		$labels = (array) ( $this->schema['step_labels'] ?? array() );
		$total  = count( (array) $this->schema['steps'] );
		$named  = '' !== trim( implode( '', $labels ) );

		$html = sprintf(
			'<ol class="%s" role="list" aria-label="%s">',
			$named ? 'cfs-stepper' : 'cfs-stepper cfs-stepper--compact',
			esc_attr__( 'Прогресс формы', 'contact-form-submissions' )
		);

		for ( $i = 0; $i < $total; $i++ ) {
			$label = (string) ( $labels[ $i ] ?? '' );

			$html .= sprintf(
				'<li class="cfs-stepper-item%s" aria-current="%s"><span class="cfs-step-num" aria-hidden="true">%d</span>%s</li>',
				0 === $i ? ' is-active' : '',
				0 === $i ? 'step' : 'false',
				$i + 1,
				( $named && '' !== $label ) ? '<span class="cfs-step-label">' . esc_html( $label ) . '</span>' : ''
			);
		}

		return $html . '</ol>';
	}

	/**
	 * Back / next / submit bar for the wizard.
	 *
	 * @param array|null $submit_tag Submit entry from the plan, when present.
	 * @return string
	 */
	private function render_step_nav( $submit_tag ): string {
		$back = sprintf(
			'<button type="button" class="cfs-btn cfs-btn--back" hidden>%s%s%s</button>',
			CFS_Icons::render( (string) ( $this->settings['back_icon_before'] ?? '' ) ),
			esc_html( (string) ( $this->settings['back_text'] ?? '' ) ),
			''
		);

		$next = sprintf(
			'<button type="button" class="cfs-btn cfs-btn--next">%s%s%s</button>',
			'',
			esc_html( (string) ( $this->settings['next_text'] ?? '' ) ),
			CFS_Icons::render( (string) ( $this->settings['next_icon_after'] ?? '' ) )
		);

		$submit = $this->render_submit_button(
			is_array( $submit_tag ) ? (array) $submit_tag['attrs'] : array(),
			true
		);

		return '<div class="cfs-step-nav">' . $back . $next . $submit . '</div>';
	}

	/**
	 * Render one plan entry.
	 *
	 * @param array $entry Plan entry.
	 * @return string
	 */
	private function render_entry( array $entry ): string {
		switch ( $entry['kind'] ) {
			case 'html':
				// Already sanitised at save time — see CFS_Template_Sanitizer.
				return (string) $entry['content'];

			case 'field':
				$field = $this->form->get_field( (string) $entry['name'] );
				return null === $field ? '' : $this->render_field( $field );

			case 'submit':
				return '<div class="cfs-field cfs-field--submit">'
					. $this->render_submit_button( (array) $entry['attrs'], false )
					. '</div>';
		}

		return '';
	}

	/**
	 * Render one field.
	 *
	 * @param array $field Compiled field definition.
	 * @return string
	 */
	public function render_field( array $field ): string {
		$descriptor = CFS_Field_Types::get( (string) $field['type'] );
		if ( null === $descriptor ) {
			return '';
		}

		if ( isset( $descriptor['render_cb'] ) && is_callable( $descriptor['render_cb'] ) ) {
			$html = (string) call_user_func( $descriptor['render_cb'], $field, $this, $descriptor );
		} else {
			switch ( $descriptor['render'] ) {
				case 'textarea':
					$html = $this->render_textarea( $field, $descriptor );
					break;
				case 'select':
					$html = $this->render_select( $field, $descriptor );
					break;
				case 'radio':
					$html = $this->render_radio( $field, $descriptor );
					break;
				case 'multicheck':
					$html = $this->render_multicheck( $field, $descriptor );
					break;
				case 'checkbox':
				case 'agreement':
					$html = $this->render_checkbox( $field, $descriptor );
					break;
				case 'hidden':
					$html = $this->render_hidden( $field );
					break;
				case 'input':
				default:
					$html = $this->render_input( $field, $descriptor );
					break;
			}
		}

		/**
		 * Filter the HTML of a single field.
		 *
		 * @param string             $html     Field markup.
		 * @param array              $field    Compiled field definition.
		 * @param CFS_Form_Renderer  $renderer Renderer instance.
		 */
		return (string) apply_filters( 'cfs_render_field', $html, $field, $this );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Field renderers
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Single-line input types.
	 *
	 * @param array $field      Compiled field.
	 * @param array $descriptor Type descriptor.
	 * @return string
	 */
	private function render_input( array $field, array $descriptor ): string {
		$name  = (string) $field['name'];
		$id    = $this->field_id( $name );
		$icon  = CFS_Icons::render( $this->attr( $field, 'icon' ) );

		$attrs = array(
			'type'             => (string) $descriptor['input_type'],
			'id'               => $id,
			'name'             => $this->input_name( $field ),
			'class'            => 'phone' === $field['type'] ? 'cfs-input cfs-input--phone' : 'cfs-input',
			'data-cfs-field'   => $name,
			'placeholder'      => $this->attr( $field, 'placeholder' ),
			'value'            => $this->attr( $field, 'default' ),
			'pattern'          => $this->attr( $field, 'pattern' ),
			'autocomplete'     => $this->attr( $field, 'autocomplete' ),
			'aria-describedby' => $id . '-error',
		);

		foreach ( array( 'min', 'max', 'step', 'minlength', 'maxlength' ) as $key ) {
			$value = (string) ( $field['constraints'][ $key ] ?? '' );
			if ( '' !== $value ) {
				$attrs[ $key ] = $value;
			}
		}

		if ( ! empty( $field['required'] ) ) {
			$attrs['required']      = true;
			$attrs['aria-required'] = 'true';
		}

		// Date and number inputs always show browser chrome, so their label has
		// nowhere to sit — it starts in the floated position.
		$always_floated = in_array( $field['type'], array( 'date', 'number' ), true );

		return $this->wrap_field(
			$field,
			$this->render_label( $field, $id )
			. '<input' . $this->attrs_to_html( $attrs ) . '>'
			. $icon
			. $this->render_error_slot( $id )
			. $this->render_help( $field ),
			'' !== $icon,
			$always_floated
		);
	}

	/**
	 * Multi-line input.
	 *
	 * @param array $field      Compiled field.
	 * @param array $descriptor Type descriptor.
	 * @return string
	 */
	private function render_textarea( array $field, array $descriptor ): string {
		unset( $descriptor );

		$name = (string) $field['name'];
		$id   = $this->field_id( $name );
		$icon = CFS_Icons::render( $this->attr( $field, 'icon' ) );
		$rows = (int) $this->attr( $field, 'rows', '4' );

		$attrs = array(
			'id'               => $id,
			'name'             => $this->input_name( $field ),
			'class'            => 'cfs-input cfs-textarea',
			'data-cfs-field'   => $name,
			'rows'             => (string) max( 2, $rows ),
			'placeholder'      => $this->attr( $field, 'placeholder' ),
			'aria-describedby' => $id . '-error',
		);

		$maxlength = (string) ( $field['constraints']['maxlength'] ?? '' );
		if ( '' !== $maxlength ) {
			$attrs['maxlength'] = $maxlength;
		}

		if ( ! empty( $field['required'] ) ) {
			$attrs['required']      = true;
			$attrs['aria-required'] = 'true';
		}

		return $this->wrap_field(
			$field,
			$this->render_label( $field, $id )
			. '<textarea' . $this->attrs_to_html( $attrs ) . '>' . esc_textarea( $this->attr( $field, 'default' ) ) . '</textarea>'
			. $icon
			. $this->render_error_slot( $id )
			. $this->render_help( $field ),
			'' !== $icon
		);
	}

	/**
	 * Dropdown.
	 *
	 * The label is not a floating one here: it becomes the placeholder option,
	 * with an aria-label carrying it for screen readers.
	 *
	 * @param array $field      Compiled field.
	 * @param array $descriptor Type descriptor.
	 * @return string
	 */
	private function render_select( array $field, array $descriptor ): string {
		unset( $descriptor );

		$name    = (string) $field['name'];
		$id      = $this->field_id( $name );
		$icon    = CFS_Icons::render( $this->attr( $field, 'icon' ) );
		$default = $this->attr( $field, 'default' );

		$attrs = array(
			'id'               => $id,
			'name'             => $this->input_name( $field ),
			'class'            => 'cfs-input cfs-select',
			'data-cfs-field'   => $name,
			'aria-label'       => (string) $field['label'],
			'aria-describedby' => $id . '-error',
		);

		if ( ! empty( $field['required'] ) ) {
			$attrs['required']      = true;
			$attrs['aria-required'] = 'true';
		}

		$options = sprintf(
			'<option value="" disabled%s>— %s —</option>',
			'' === $default ? ' selected' : '',
			esc_html( (string) $field['label'] )
		);

		foreach ( (array) $field['options'] as $option ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $option['value'] ),
				( '' !== $default && $default === (string) $option['value'] ) ? ' selected' : '',
				esc_html( (string) $option['label'] )
			);
		}

		return $this->wrap_field(
			$field,
			'<select' . $this->attrs_to_html( $attrs ) . '>' . $options . '</select>'
			. $icon
			. $this->render_error_slot( $id )
			. $this->render_help( $field ),
			'' !== $icon
		);
	}

	/**
	 * Radio group.
	 *
	 * @param array $field      Compiled field.
	 * @param array $descriptor Type descriptor.
	 * @return string
	 */
	private function render_radio( array $field, array $descriptor ): string {
		unset( $descriptor );
		return $this->render_choice_group( $field, 'radio' );
	}

	/**
	 * Checkbox group.
	 *
	 * @param array $field      Compiled field.
	 * @param array $descriptor Type descriptor.
	 * @return string
	 */
	private function render_multicheck( array $field, array $descriptor ): string {
		unset( $descriptor );
		return $this->render_choice_group( $field, 'multicheck' );
	}

	/**
	 * Shared markup for radio and checkbox groups.
	 *
	 * Option element IDs are built from the option's position, not its value:
	 * values may be Cyrillic or contain spaces, and mangling them into an ID
	 * used to be what forced multicheck values through sanitize_key().
	 *
	 * @param array  $field Compiled field.
	 * @param string $kind  'radio' or 'multicheck'.
	 * @return string
	 */
	private function render_choice_group( array $field, string $kind ): string {
		$name     = (string) $field['name'];
		$error_id = $this->field_id( $name ) . '-error';
		$is_radio = 'radio' === $kind;
		$default  = $this->attr( $field, 'default' );
		$defaults = array_filter( array_map( 'trim', explode( ',', $default ) ) );

		$options = '';
		$index   = 0;

		foreach ( (array) $field['options'] as $option ) {
			$option_id = $this->field_id( $name ) . '-' . $index;
			$value     = (string) $option['value'];

			$input_attrs = array(
				'type'  => $is_radio ? 'radio' : 'checkbox',
				'id'    => $option_id,
				'name'  => $this->input_name( $field ),
				'value' => $value,
				'class' => $is_radio ? 'cfs-radio' : 'cfs-multicheck-input',
			);

			if ( in_array( $value, $defaults, true ) ) {
				$input_attrs['checked'] = true;
			}

			// Only radios carry the required attribute: the browser applies it
			// per input, which for a checkbox group would demand every box.
			if ( $is_radio && ! empty( $field['required'] ) ) {
				$input_attrs['required']      = true;
				$input_attrs['aria-required'] = 'true';
			}

			$options .= sprintf(
				'<label class="%s" for="%s"><input%s><span>%s</span></label>',
				$is_radio ? 'cfs-radio-label' : 'cfs-multicheck-label',
				esc_attr( $option_id ),
				$this->attrs_to_html( $input_attrs ),
				esc_html( (string) $option['label'] )
			);

			++$index;
		}

		$fieldset_attrs = array(
			'class'            => $is_radio
				? 'cfs-field cfs-field--radio'
				: 'cfs-field cfs-field--multicheck cfs-multicheck-fieldset',
			'aria-describedby' => $error_id,
			'data-field'       => $name,
			'data-cfs-field'   => $name,
		);

		foreach ( $this->extra_classes( $field ) as $class ) {
			$fieldset_attrs['class'] .= ' ' . $class;
		}

		if ( ! empty( $field['required'] ) ) {
			$fieldset_attrs['data-required'] = 'true';
		}

		return '<fieldset' . $this->attrs_to_html( $fieldset_attrs ) . '>'
			. '<legend class="cfs-field-legend">' . esc_html( (string) $field['label'] ) . $this->required_mark( $field ) . '</legend>'
			. '<div class="' . ( $is_radio ? 'cfs-radio-group' : 'cfs-multicheck-group' ) . '">' . $options . '</div>'
			. $this->render_error_slot( $this->field_id( $name ) )
			. $this->render_help( $field )
			. '</fieldset>';
	}

	/**
	 * Single checkbox, including the agreement variant.
	 *
	 * @param array $field      Compiled field.
	 * @param array $descriptor Type descriptor.
	 * @return string
	 */
	private function render_checkbox( array $field, array $descriptor ): string {
		$name         = (string) $field['name'];
		$id           = $this->field_id( $name );
		$is_agreement = 'agreement' === $descriptor['render'];

		$attrs = array(
			'type'             => 'checkbox',
			'id'               => $id,
			'name'             => $this->input_name( $field ),
			'value'            => '1',
			'class'            => 'cfs-checkbox',
			'data-cfs-field'   => $name,
			'aria-describedby' => $id . '-error',
		);

		if ( '1' === $this->attr( $field, 'default' ) ) {
			$attrs['checked'] = true;
		}

		if ( ! empty( $field['required'] ) ) {
			$attrs['required']      = true;
			$attrs['aria-required'] = 'true';
		}

		// The agreement label is the one place a field label may carry markup:
		// it almost always links to a privacy policy.
		$label = $is_agreement
			? '<p>' . wp_kses( $this->agreement_label( $field ), $this->agreement_allowed_html() ) . '</p>'
			: '<span>' . esc_html( (string) $field['label'] ) . '</span>';

		$classes = array( 'cfs-field', 'cfs-field--checkbox' );
		if ( $is_agreement ) {
			$classes[] = 'cfs-field--agreement';
		}
		foreach ( $this->extra_classes( $field ) as $class ) {
			$classes[] = $class;
		}

		return sprintf(
			'<div class="%s"><label class="cfs-checkbox-label"><input%s>%s%s</label>%s%s</div>',
			esc_attr( implode( ' ', $classes ) ),
			$this->attrs_to_html( $attrs ),
			$label,
			$this->required_mark( $field ),
			$this->render_error_slot( $id ),
			$this->render_help( $field )
		);
	}

	/**
	 * Hidden field.
	 *
	 * @param array $field Compiled field.
	 * @return string
	 */
	private function render_hidden( array $field ): string {
		$source = $this->attr( $field, 'source' );

		$attrs = array(
			'type'           => 'hidden',
			'name'           => $this->input_name( $field ),
			'id'             => $this->field_id( (string) $field['name'] ),
			'data-cfs-field' => (string) $field['name'],
			'value'          => '' !== $source
				? $this->resolve_source( $source )
				: $this->attr( $field, 'value', $this->attr( $field, 'default' ) ),
		);

		// query: and cookie: sources are filled in by the browser — reading them
		// on the server would bake one visitor's value into a cached page.
		if ( '' !== $source && preg_match( '/^(query|cookie):/', $source ) ) {
			$attrs['data-cfs-source'] = $source;
		}

		return '<input' . $this->attrs_to_html( $attrs ) . '>';
	}

	/**
	 * Submit button.
	 *
	 * @param array $attrs        Attributes from the [submit] tag.
	 * @param bool  $start_hidden Whether to start hidden (wizard mode).
	 * @return string
	 */
	private function render_submit_button( array $attrs, bool $start_hidden ): string {
		$text = (string) ( $attrs['text'] ?? '' );
		if ( '' === $text ) {
			$text = __( 'Отправить', 'contact-form-submissions' );
		}

		$classes = array_merge(
			array( 'cfs-btn', 'cfs-btn--submit' ),
			$this->split_classes( (string) ( $attrs['class'] ?? '' ) )
		);

		$button_attrs = array(
			'type'  => 'submit',
			'class' => implode( ' ', $classes ),
			'id'    => 'cfs-submit-' . $this->uid,
		);

		if ( $start_hidden ) {
			$button_attrs['hidden'] = true;
		}

		return '<button' . $this->attrs_to_html( $button_attrs ) . '>'
			. CFS_Icons::render( (string) ( $attrs['icon_before'] ?? '' ) )
			. esc_html( $text )
			. CFS_Icons::render( (string) ( $attrs['icon_after'] ?? '' ) )
			. '</button>';
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Building blocks
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Wrap field markup in its .cfs-field container.
	 *
	 * @param array  $field          Compiled field.
	 * @param string $inner          Inner markup.
	 * @param bool   $has_icon       Whether an icon was rendered.
	 * @param bool   $always_floated Whether the label starts floated.
	 * @return string
	 */
	private function wrap_field( array $field, string $inner, bool $has_icon = false, bool $always_floated = false ): string {
		$descriptor = CFS_Field_Types::get( (string) $field['type'] );
		$classes    = array( 'cfs-field', 'cfs-field--' . (string) $descriptor['css'] );

		if ( $always_floated ) {
			$classes[] = 'focused';
		}
		if ( $has_icon ) {
			$classes[] = 'cfs-field--has-icon';
		}
		foreach ( $this->extra_classes( $field ) as $class ) {
			$classes[] = $class;
		}

		return sprintf( '<div class="%s">%s</div>', esc_attr( implode( ' ', $classes ) ), $inner );
	}

	/**
	 * Field label element.
	 *
	 * @param array  $field Compiled field.
	 * @param string $id    Input element ID.
	 * @return string
	 */
	private function render_label( array $field, string $id ): string {
		return sprintf(
			'<label for="%s">%s%s</label>',
			esc_attr( $id ),
			esc_html( (string) $field['label'] ),
			$this->required_mark( $field )
		);
	}

	/**
	 * The asterisk shown next to a required field's label.
	 *
	 * @param array $field Compiled field.
	 * @return string
	 */
	private function required_mark( array $field ): string {
		return empty( $field['required'] )
			? ''
			: '<span class="cfs-required" aria-hidden="true">*</span>';
	}

	/**
	 * The element client-side and server-side errors are written into.
	 *
	 * @param string $id Input element ID.
	 * @return string
	 */
	private function render_error_slot( string $id ): string {
		return sprintf(
			'<span id="%s-error" class="cfs-error" role="alert" aria-live="polite"></span>',
			esc_attr( $id )
		);
	}

	/**
	 * Optional hint under the field.
	 *
	 * @param array $field Compiled field.
	 * @return string
	 */
	private function render_help( array $field ): string {
		$help = $this->attr( $field, 'help' );
		return '' === $help ? '' : '<p class="cfs-field-hint">' . esc_html( $help ) . '</p>';
	}

	/**
	 * Notice shown in place of a form that cannot render.
	 *
	 * Visitors get nothing but an HTML comment; someone who can fix the form
	 * gets told what is wrong.
	 *
	 * @return string
	 */
	private function render_broken_notice(): string {
		if ( ! CFS_Post_Type::user_can_manage() ) {
			return '<!-- CFS: форма ' . (int) $this->form->get_id() . ' содержит ошибки -->';
		}

		$items = '';
		foreach ( $this->form->get_errors() as $error ) {
			$items .= '<li>' . esc_html( (string) $error['message'] ) . '</li>';
		}

		return '<div class="cfs-form-broken notice notice-error"><p><strong>'
			. esc_html__( 'Форма содержит ошибки и не выводится:', 'contact-form-submissions' )
			. '</strong></p><ul>' . $items . '</ul></div>';
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * The configuration the front-end script needs.
	 *
	 * @return array
	 */
	private function build_config(): array {
		$after  = $this->form->get_after();
		$fields = array();

		foreach ( $this->form->get_fields() as $name => $field ) {
			if ( empty( $field['submits'] ) ) {
				continue;
			}

			$fields[ $name ] = array(
				'type'     => (string) $field['type'],
				'label'    => (string) $field['label'],
				'required' => (bool) $field['required'],
				'multiple' => (bool) $field['multiple'],
				'error'    => $this->attr( $field, 'error' ),
				'rules'    => (array) CFS_Field_Types::get( (string) $field['type'] )['rules'],
			);

			foreach ( array( 'min', 'max', 'step', 'minlength', 'maxlength' ) as $key ) {
				$value = (string) ( $field['constraints'][ $key ] ?? '' );
				if ( '' !== $value ) {
					$fields[ $name ][ $key ] = $value;
				}
			}

			if ( ! empty( $field['options'] ) ) {
				$fields[ $name ]['options'] = CFS_Field_Types::option_values( $field );
			}
		}

		return array(
			'formId'   => $this->form->get_id(),
			'instance' => $this->instance,
			'fields'   => $fields,
			'steps'    => (array) ( $this->schema['steps'] ?? array() ),
			'after'    => array(
				'mode'            => (string) $after['mode'],
				'message'         => (string) $after['message'],
				'redirectUrl'     => (string) $after['redirect_url'],
				'redirectDelay'   => (int) $after['redirect_delay'],
				'resetForm'       => (bool) $after['reset_form'],
				'scrollToMessage' => (bool) $after['scroll_to_message'],
				'closeModal'      => (bool) $after['close_modal'],
			),
		);
	}

	/**
	 * Element ID for a field.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	public function field_id( string $name ): string {
		return 'cfs-' . $this->uid . '-' . $name;
	}

	/**
	 * POST name for a field.
	 *
	 * Everything is namespaced under cfs[…] so a field can never collide with
	 * the plugin's own request fields, whatever the author calls it.
	 *
	 * @param array $field Compiled field.
	 * @return string
	 */
	public function input_name( array $field ): string {
		return 'cfs[' . $field['name'] . ']' . ( ! empty( $field['multiple'] ) ? '[]' : '' );
	}

	/**
	 * Read one template attribute.
	 *
	 * @param array  $field   Compiled field.
	 * @param string $key     Attribute name.
	 * @param string $default Value when the attribute is absent.
	 * @return string
	 */
	public function attr( array $field, string $key, string $default = '' ): string {
		$value = $field['attrs'][ $key ] ?? $default;
		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Extra CSS classes for a field: the class attribute plus the width helper.
	 *
	 * @param array $field Compiled field.
	 * @return string[]
	 */
	private function extra_classes( array $field ): array {
		$classes = $this->split_classes( $this->attr( $field, 'class' ) );

		$width = $this->attr( $field, 'width' );
		if ( isset( self::$widths[ $width ] ) ) {
			$classes[] = 'cfs-field--' . self::$widths[ $width ];
		}

		return $classes;
	}

	/**
	 * Split and sanitise a space-separated class list.
	 *
	 * @param string $raw Raw class attribute.
	 * @return string[]
	 */
	private function split_classes( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}

		$parts = preg_split( '/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_filter( array_map( 'sanitize_html_class', (array) $parts ) ) );
	}

	/**
	 * Resolve a hidden field's "source" attribute on the server.
	 *
	 * @param string $source Source expression, e.g. "page:url".
	 * @return string
	 */
	private function resolve_source( string $source ): string {
		$parts = explode( ':', $source, 2 );
		$kind  = strtolower( trim( $parts[0] ) );
		$key   = isset( $parts[1] ) ? trim( $parts[1] ) : '';

		if ( 'page' === $kind ) {
			switch ( $key ) {
				case 'url':
					return $this->current_url();
				case 'title':
					return (string) wp_get_document_title();
				case 'id':
					return (string) get_queried_object_id();
			}
			return '';
		}

		if ( 'user' === $kind ) {
			$user = wp_get_current_user();
			if ( ! $user || ! $user->exists() ) {
				return '';
			}
			switch ( $key ) {
				case 'email':
					return (string) $user->user_email;
				case 'login':
					return (string) $user->user_login;
				case 'name':
					return (string) $user->display_name;
				case 'id':
					return (string) $user->ID;
			}
		}

		return '';
	}

	/**
	 * URL of the page the form is rendered on.
	 *
	 * @return string
	 */
	private function current_url(): string {
		$id = get_queried_object_id();
		if ( $id ) {
			$permalink = get_permalink( $id );
			if ( $permalink ) {
				return (string) $permalink;
			}
		}
		return home_url( '/' );
	}

	/**
	 * Markup allowed inside an agreement label.
	 *
	 * @return array<string, array>
	 */
	private function agreement_allowed_html(): array {
		return array(
			'a'      => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
				'class'  => array(),
			),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'br'     => array(),
			'span'   => array( 'class' => array() ),
		);
	}

	/**
	 * Agreement label, falling back to the site-wide setting.
	 *
	 * @param array $field Compiled field.
	 * @return string
	 */
	private function agreement_label( array $field ): string {
		$label = (string) $field['label'];

		// An agreement tag written without a label inherits the site-wide text
		// rather than showing the type's generic name.
		if ( '' === $label || CFS_Field_Types::default_label( 'agreement' ) === $label ) {
			$option = (string) get_option( 'cfs_agreement_text', '' );
			if ( '' !== $option ) {
				return $option;
			}
			if ( CFS_Field_Types::default_label( 'agreement' ) === $label ) {
				return __( 'Я даю согласие на обработку персональных данных', 'contact-form-submissions' );
			}
		}

		return $label;
	}

	/**
	 * Build an attribute string, escaping every value.
	 *
	 * @param array $attrs name => value; true renders a bare attribute, '' is skipped.
	 * @return string
	 */
	private function attrs_to_html( array $attrs ): string {
		$html = '';

		foreach ( $attrs as $key => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}
			if ( true === $value ) {
				$html .= ' ' . esc_attr( $key );
				continue;
			}
			if ( '' === (string) $value ) {
				continue;
			}
			$html .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( (string) $value ) );
		}

		return $html;
	}
}
