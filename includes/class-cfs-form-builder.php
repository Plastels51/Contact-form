<?php
/**
 * Form builder — renders shortcode [contact_form].
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Form_Builder
 */
class CFS_Form_Builder {

	/**
	 * DB instance.
	 *
	 * @var CFS_DB
	 */
	private $db;

	/**
	 * Registered form IDs for the current request.
	 *
	 * @var array
	 */
	private $registered_forms = array();

	/**
	 * Whether assets have been enqueued.
	 *
	 * @var bool
	 */
	private $assets_needed = false;

	/**
	 * Constructor.
	 *
	 * @param CFS_DB $db DB instance.
	 */
	public function __construct( CFS_DB $db ) {
		$this->db = $db;
		add_shortcode( 'contact_form', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Get registered form IDs (used for AJAX validation).
	 *
	 * @return array
	 */
	public function get_registered_forms(): array {
		return $this->registered_forms;
	}

	/**
	 * Enqueue assets only when shortcode was used.
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! $this->assets_needed ) {
			return;
		}
		$this->enqueue_assets();
	}

	/**
	 * Enqueue front-end assets.
	 * Public so it can be used as a WP hook callback.
	 */
	public function enqueue_assets(): void {
		if ( get_option( 'cfs_disable_styles', 'no' ) !== 'yes' ) {
			wp_enqueue_style(
				'cfs-form',
				CFS_PLUGIN_URL . 'assets/css/cfs-form.css',
				array(),
				CFS_VERSION
			);
		}

		wp_enqueue_script(
			'cfs-form',
			CFS_PLUGIN_URL . 'assets/js/cfs-form.js',
			array(),
			CFS_VERSION,
			true
		);

		wp_localize_script(
			'cfs-form',
			'cfsData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cfs_submit_form' ),
				'debug'   => get_option( 'cfs_debug_mode', 'no' ) === 'yes',
				'i18n'    => array(
					'sending'       => __( 'Отправка...', 'contact-form-submissions' ),
					'error_general' => __( 'Произошла ошибка. Попробуйте ещё раз.', 'contact-form-submissions' ),
					'required'      => __( 'Обязательное поле', 'contact-form-submissions' ),
					'invalid_email' => __( 'Некорректный email', 'contact-form-submissions' ),
					'invalid_phone' => __( 'Некорректный номер телефона', 'contact-form-submissions' ),
				),
			)
		);
	}

	/**
	 * Parse a field token like "comment_2" into base type and index.
	 *
	 * Examples:
	 *   "name"      → array( 'base' => 'name',    'index' => 1 )
	 *   "comment_2" → array( 'base' => 'comment', 'index' => 2 )
	 *   "phone_3"   → array( 'base' => 'phone',   'index' => 3 )
	 *
	 * @param string $field Full field token.
	 * @return array { base: string, index: int }
	 */
	private function parse_field_token( string $field ): array {
		if ( preg_match( '/^([a-z]+)_(\d+)$/', $field, $m ) ) {
			return array( 'base' => $m[1], 'index' => (int) $m[2] );
		}
		return array( 'base' => $field, 'index' => 1 );
	}

	/**
	 * Return the default human-readable label for a field base type.
	 *
	 * @param string $base Base type (name, surname, phone, …).
	 * @return string
	 */
	private function get_base_label( string $base ): string {
		$labels = array(
			'name'       => __( 'Имя', 'contact-form-submissions' ),
			'surname'    => __( 'Фамилия', 'contact-form-submissions' ),
			'patronymic' => __( 'Отчество', 'contact-form-submissions' ),
			'phone'      => __( 'Телефон', 'contact-form-submissions' ),
			'email'      => __( 'Email', 'contact-form-submissions' ),
			'comment'    => __( 'Комментарий', 'contact-form-submissions' ),
			'select'     => __( 'Выберите', 'contact-form-submissions' ),
			'text'       => __( 'Текст', 'contact-form-submissions' ),
			'radio'      => __( 'Выберите', 'contact-form-submissions' ),
			'checkbox'   => __( 'Согласен', 'contact-form-submissions' ),
			'agreement'  => __( 'Согласие', 'contact-form-submissions' ),
		);
		return $labels[ $base ] ?? ucfirst( $base );
	}

	/**
	 * Look up a field attribute with a two-level fallback chain:
	 *   1. $atts["{$field}_{$attr}"]  — field-specific override (e.g. "comment_2_label")
	 *   2. $atts["{$base}_{$attr}"]   — base-type default     (e.g. "comment_label")
	 *   3. $default
	 *
	 * @param string $field   Full field token (e.g. "comment_2").
	 * @param string $base    Base type        (e.g. "comment").
	 * @param string $attr    Attribute suffix (e.g. "label", "required").
	 * @param array  $atts    Shortcode attributes array.
	 * @param mixed  $default Fallback when neither key exists.
	 * @return mixed
	 */
	private function get_field_attr( string $field, string $base, string $attr, array $atts, $default ) {
		$field_key = $field . '_' . $attr;
		$base_key  = $base . '_' . $attr;

		if ( array_key_exists( $field_key, $atts ) ) {
			return $atts[ $field_key ];
		}
		if ( array_key_exists( $base_key, $atts ) ) {
			return $atts[ $base_key ];
		}
		return $default;
	}

	/**
	 * ═══════════════════════════════════════════════════════════════════════════
	 * SVG ICON LIBRARY — ADD YOUR CUSTOM ICONS HERE
	 * ═══════════════════════════════════════════════════════════════════════════
	 *
	 * Each entry: 'icon-name' => '<svg ...>...</svg>'
	 *
	 * Key:  a short slug (letters, digits, hyphens only).
	 * Value: an SVG string.
	 *       • Set width="20" height="20" so the CSS can scale it via 1.1rem.
	 *       • Use fill="none" stroke="currentColor" so it inherits the CSS
	 *         color of .cfs-field-icon (gray at rest, blue on focus, red on error).
	 *       • Always include aria-hidden="true" focusable="false".
	 *
	 * Usage in shortcode:
	 *   name_icon="user"
	 *   phone_icon="phone"
	 *   name_2_icon="user"        ← indexed field variant
	 *
	 * To add a new icon: append a new line to the array below.
	 * The change takes effect immediately — no cache to clear.
	 *
	 * Developers can also add icons without editing the plugin file:
	 *   add_filter( 'cfs_icon_library', function( $icons ) {
	 *       $icons['star'] = '<svg ...>...</svg>';
	 *       return $icons;
	 *   } );
	 * ═══════════════════════════════════════════════════════════════════════════
	 *
	 * @return array<string, string>
	 */
	private function get_icon_library(): array {
		$icons = array(

			// ── Frequently used ─────────────────────────────────────────────
			'user'     => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>',
			'phone'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.21h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.77a16 16 0 0 0 6.29 6.29l.87-.87a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
			'email'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
			'comment'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
			'select'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',

			// ── Contact / personal ───────────────────────────────────────────
			'company'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 0-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>',
			'location' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
			'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>',
			'lock'     => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
			'link'     => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
			'search'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>',
			'star'     => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',

		);

		/**
		 * Allow themes and other plugins to add or override icons.
		 *
		 * @param array<string, string> $icons Current icon library.
		 */
		return (array) apply_filters( 'cfs_icon_library', $icons );
	}

	/**
	 * Render an icon element for a field.
	 *
	 * Looks up $icon_name in get_icon_library(). Returns an empty string when
	 * the name is empty or not found in the library, so callers never need to
	 * check — just echo the return value.
	 *
	 * The icon is rendered as a <span> AFTER the input/textarea/select element
	 * so that the CSS sibling selector (.cfs-input:focus ~ .cfs-field-icon) can
	 * change the icon colour on focus.
	 *
	 * @param string $icon_name Short icon key (e.g. "user", "phone").
	 * @return string HTML or ''.
	 */
	private function render_icon( string $icon_name ): string {
		if ( '' === $icon_name ) {
			return '';
		}
		$library = $this->get_icon_library();
		$svg     = $library[ $icon_name ] ?? '';
		if ( '' === $svg ) {
			return '';
		}
		return $svg;
	}

	/**
	 * Render an icon element for a button (submit or modal trigger).
	 *
	 * Returns bare SVG — styling via parent selectors in CSS
	 * (e.g. .cfs-btn--submit > svg, .cfs-modal-btn > svg).
	 *
	 * @param string $icon_name Short icon key (e.g. "phone", "arrow").
	 * @return string HTML or ''.
	 */
	private function render_btn_icon( string $icon_name ): string {
		if ( '' === $icon_name ) {
			return '';
		}
		$library = $this->get_icon_library();
		$svg     = $library[ $icon_name ] ?? '';
		if ( '' === $svg ) {
			return '';
		}
		return $svg;
	}

	/**
	 * Render the [contact_form] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ): string {
		/*
		 * Capture raw atts BEFORE shortcode_atts() discards unknown keys.
		 * This preserves per-field overrides for indexed fields, e.g.:
		 *   comment_2_label="Второй комментарий"
		 *   name_2_required="yes"
		 */
		$raw_atts = is_array( $atts ) ? $atts : array();

		$atts = shortcode_atts(
			array(
				'form_id'         => '',
				'title'           => '',
				'fields'          => 'name,phone,email',
				'button_text'     => __( 'Отправить', 'contact-form-submissions' ),
				'class'           => '',
				'success_message' => __( 'Спасибо! Мы свяжемся с вами.', 'contact-form-submissions' ),
				'redirect_url'    => '',
				'redirect_delay'  => '2',
				// Modal / dialog container.
				'container'         => 'div',
				'modal_button_text'        => __( 'Открыть форму', 'contact-form-submissions' ),
				'modal_button_icon_before' => '',
				'modal_button_icon_after'  => '',
				// Submit button icons.
				'button_icon_before' => '',
				'button_icon_after'  => '',
				// Field labels.
				'name_label'        => __( 'Имя', 'contact-form-submissions' ),
				'surname_label'     => __( 'Фамилия', 'contact-form-submissions' ),
				'patronymic_label'  => __( 'Отчество', 'contact-form-submissions' ),
				'phone_label'       => __( 'Телефон', 'contact-form-submissions' ),
				'email_label'       => __( 'Email', 'contact-form-submissions' ),
				'comment_label'     => __( 'Комментарий', 'contact-form-submissions' ),
				'select_label'      => __( 'Выберите', 'contact-form-submissions' ),
				'checkbox_label'    => __( 'Согласен', 'contact-form-submissions' ),
				'agreement_label'   => '',
				// Required flags.
				'name_required'       => 'yes',
				'surname_required'    => 'yes',
				'patronymic_required' => 'no',
				'phone_required'      => 'yes',
				'email_required'      => 'no',
				'comment_required'    => 'no',
				'select_required'     => 'no',
				'checkbox_required'   => 'no',
				'agreement_required'  => 'no',
				// Placeholders.
				'name_placeholder'       => '',
				'surname_placeholder'    => '',
				'patronymic_placeholder' => '',
				'phone_placeholder'      => '+7 (___) ___-__-__',
				'email_placeholder'      => '',
				'comment_placeholder'    => '',
				// Extras.
				'select_options' => '',
				'radio_options'  => '',
				'comment_rows'   => '4',
				'hidden_name'    => '',
				'hidden_value'   => '',
			),
			$atts,
			'contact_form'
		);

		/*
		 * Merge back unknown keys discarded by shortcode_atts().
		 * These carry per-indexed-field overrides, e.g. comment_2_label.
		 * Values are sanitised as text since they originate from post content.
		 */
		foreach ( $raw_atts as $raw_key => $raw_val ) {
			if ( ! array_key_exists( $raw_key, $atts ) ) {
				$atts[ sanitize_key( $raw_key ) ] = sanitize_text_field( (string) $raw_val );
			}
		}

		/*
		 * ── Star (*) notation for required fields ──────────────────────────────
		 *
		 * Allows marking fields as required directly in the `fields` attribute:
		 *   fields="name*,phone,email*"  →  name required, phone not, email required
		 *
		 * Works with indexed tokens too:
		 *   fields="name*,name_2,comment_3*"
		 *
		 * Rules:
		 *  1. Parse every field token; strip trailing `*` from the field name.
		 *  2. If at least one `*` was found, the notation is "active":
		 *       - Fields WITH    `*` → {field}_required = 'yes'  (hard override)
		 *       - Fields WITHOUT `*` → {field}_required = 'no'   (hard override)
		 *     This intentionally overrides per-attribute defaults so that
		 *     `fields="name*,phone,email*"` makes phone explicitly not required
		 *     even though phone_required defaults to 'yes'.
		 *  3. If NO `*` is found, all existing {field}_required values are untouched.
		 * ───────────────────────────────────────────────────────────────────────
		 */
		$raw_tokens         = array_map( 'trim', explode( ',', $atts['fields'] ) );
		$star_notation_used = false;
		$required_overrides = array();
		$clean_tokens       = array();

		foreach ( $raw_tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( '*' === substr( $token, -1 ) ) {
				$field_name                        = rtrim( $token, '*' );
				$star_notation_used                = true;
				$required_overrides[ $field_name ] = 'yes';
			} else {
				$field_name = $token;
			}
			$clean_tokens[] = $field_name;
		}

		if ( $star_notation_used ) {
			// Set non-starred fields to 'no'.
			foreach ( $clean_tokens as $field_name ) {
				if ( ! isset( $required_overrides[ $field_name ] ) ) {
					$required_overrides[ $field_name ] = 'no';
				}
			}
			// Apply all overrides to $atts (works for both base and indexed tokens).
			foreach ( $required_overrides as $field_name => $req_value ) {
				$atts[ $field_name . '_required' ] = $req_value;
			}
		}

		// Always keep $atts['fields'] in sync with the clean token list.
		$atts['fields'] = implode( ',', $clean_tokens );

		// Generate form_id if not provided.
		if ( empty( $atts['form_id'] ) ) {
			$atts['form_id'] = 'cfs_' . wp_rand( 1000, 9999 );
		}

		$form_id = sanitize_key( $atts['form_id'] );

		// Register form for in-memory lookups within the current request.
		$this->registered_forms[ $form_id ] = $atts;

		/*
		 * Build per-token maps for the AJAX handler:
		 *
		 *  required   — field_token → 'yes'|'no'
		 *                 Lookups: {token}_required → {base}_required → 'no'
		 *  field_types — field_token → base_type
		 *                 Allows the AJAX handler to sanitise correctly.
		 */
		$required_map = array();
		$field_types  = array();

		foreach ( $clean_tokens as $token ) {
			$parsed     = $this->parse_field_token( $token );
			$base       = $parsed['base'];

			$field_types[ $token ] = $base;

			// text fields are display-only — never submitted, never required.
			if ( 'text' === $base ) {
				$required_map[ $token ] = 'no';
				continue;
			}

			// Resolve required: token-specific → base → hard-coded field default → 'no'.
			$field_req_key = $token . '_required';
			$base_req_key  = $base . '_required';

			if ( array_key_exists( $field_req_key, $atts ) ) {
				$required_map[ $token ] = $atts[ $field_req_key ];
			} elseif ( array_key_exists( $base_req_key, $atts ) ) {
				$required_map[ $token ] = $atts[ $base_req_key ];
			} else {
				$required_map[ $token ] = 'no';
			}
		}

		/*
		 * Cache form configuration in a transient so the AJAX handler can:
		 *  - verify form_id was rendered by this plugin (step 8),
		 *  - perform server-side required-field validation (step 7),
		 *  - validate select values against the registered whitelist (step 7).
		 *
		 * TTL: 1 hour — enough for a typical user session.
		 * Key: cfs_form_config_{form_id}  (see CLAUDE.md § Производительность).
		 */
		// Build per-token radio options map for server-side whitelist validation.
		$radio_options_map = array();
		foreach ( $clean_tokens as $token ) {
			$parsed = $this->parse_field_token( $token );
			if ( 'radio' !== $parsed['base'] ) {
				continue;
			}
			$opts = (string) $this->get_field_attr( $token, 'radio', 'options', $atts, '' );
			if ( '' !== $opts ) {
				$radio_options_map[ $token ] = $opts;
			}
		}

		set_transient(
			'cfs_form_config_' . $form_id,
			array(
				'fields'            => $atts['fields'],
				'required'          => $required_map,
				'field_types'       => $field_types,
				'select_options'    => $atts['select_options'],
				'radio_options_map' => $radio_options_map,
			),
			HOUR_IN_SECONDS
		);

		// Signal that assets are needed.
		$this->assets_needed = true;

		// Allow late enqueue when shortcode runs after wp_head.
		if ( ! did_action( 'wp_enqueue_scripts' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		} else {
			$this->enqueue_assets();
		}

		$fields = apply_filters(
			'cfs_form_fields',
			$clean_tokens,
			$form_id,
			$atts
		);

		$html = (string) $this->build_form_html( $form_id, $fields, $atts );
		$html = (string) apply_filters( 'cfs_form_html', $html, $form_id, $atts );

		return $html;
	}

	/**
	 * Build form HTML.
	 *
	 * When $atts['container'] === 'dialog', renders a native <dialog> element
	 * preceded by a trigger <button>. Otherwise renders a plain <div>.
	 *
	 * @param string $form_id Form ID.
	 * @param array  $fields  List of field tokens.
	 * @param array  $atts    Shortcode attributes.
	 * @return string
	 */
	private function build_form_html( string $form_id, array $fields, array $atts ): string {
		$timestamp = time();
		$is_dialog = 'dialog' === ( $atts['container'] ?? 'div' );
		$wrap_id   = 'cfs-wrap-' . $form_id;

		$wrap_class = 'cfs-form-wrap';
		if ( $is_dialog ) {
			$wrap_class .= ' cfs-form-wrap--dialog';
		}
		if ( ! empty( $atts['class'] ) ) {
			$wrap_class .= ' ' . $atts['class'];
		}

		ob_start();

		if ( $is_dialog ) {
			// Trigger button rendered BEFORE the <dialog> element.
			$mbi_before = $this->render_btn_icon( (string) ( $atts['modal_button_icon_before'] ?? '' ) );
			$mbi_after  = $this->render_btn_icon( (string) ( $atts['modal_button_icon_after'] ?? '' ) );
			?>
			<button
				class="cfs-modal-btn"
				data-dialog="<?php echo esc_attr( $wrap_id ); ?>"
				aria-haspopup="dialog"
				aria-controls="<?php echo esc_attr( $wrap_id ); ?>"
			><?php
				echo $mbi_before; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from internal library
				echo esc_html( $atts['modal_button_text'] );
				echo $mbi_after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from internal library
			?></button>
			<?php
		}

		if ( $is_dialog ) {
			?>
			<dialog class="<?php echo esc_attr( $wrap_class ); ?>" id="<?php echo esc_attr( $wrap_id ); ?>">
			<?php
		} else {
			?>
			<div class="<?php echo esc_attr( $wrap_class ); ?>" id="<?php echo esc_attr( $wrap_id ); ?>">
			<?php
		}

		if ( $is_dialog ) {
			?>
			<button
				class="cfs-modal-close"
				data-dialog="<?php echo esc_attr( $wrap_id ); ?>"
				aria-label="<?php esc_attr_e( 'Закрыть', 'contact-form-submissions' ); ?>"
			>&#x2715;</button>
			<?php
		}
		?>
		<?php if ( ! empty( $atts['title'] ) ) : ?>
			<h3 class="cfs-form-title"><?php echo esc_html( $atts['title'] ); ?></h3>
		<?php endif; ?>

		<div class="cfs-form-message" role="alert" aria-live="polite" style="display:none;"></div>

		<form
			class="cfs-form"
			id="cfs-form-<?php echo esc_attr( $form_id ); ?>"
			method="post"
			novalidate
			data-form-id="<?php echo esc_attr( $form_id ); ?>"
			data-success-message="<?php echo esc_attr( $atts['success_message'] ); ?>"
			data-redirect-url="<?php echo esc_url( $atts['redirect_url'] ); ?>"
			data-redirect-delay="<?php echo esc_attr( $atts['redirect_delay'] ); ?>"
		>
			<?php
			// Honeypot fields — hidden from real users, trap bots.
			?>
			<div class="cfs-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;overflow:hidden;">
				<input type="text" name="cfs_hp_w" value="" tabindex="-1" autocomplete="new-password">
				<input type="text" name="cfs_hp_x" value="" tabindex="-1" autocomplete="new-password">
			</div>

			<input type="hidden" name="action" value="cfs_submit_form">
			<input type="hidden" name="cfs_form_id" value="<?php echo esc_attr( $form_id ); ?>">
			<input type="hidden" name="cfs_timestamp" value="<?php echo esc_attr( (string) $timestamp ); ?>">

			<?php foreach ( $fields as $field ) : ?>
				<?php echo $this->render_field( $form_id, $field, $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>

			<?php
		$btn_icon_before = $this->render_btn_icon( (string) ( $atts['button_icon_before'] ?? '' ) );
		$btn_icon_after  = $this->render_btn_icon( (string) ( $atts['button_icon_after'] ?? '' ) );
		?>
		<div class="cfs-field cfs-field--submit">
				<button
					type="submit"
					class="cfs-btn cfs-btn--submit"
					id="cfs-submit-<?php echo esc_attr( $form_id ); ?>"
				>
					<?php
					echo $btn_icon_before; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from internal library
					echo esc_html( $atts['button_text'] );
					echo $btn_icon_after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from internal library
					?>
				</button>
			</div>

		</form>
		<?php
		if ( $is_dialog ) {
			?>
			</dialog>
			<?php
		} else {
			?>
			</div>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * Dispatch a field token to the correct render method.
	 *
	 * The token may be a plain base type ("name") or an indexed variant
	 * ("name_2", "comment_3"). Dispatching is done on the base type so that
	 * indexed fields use the same renderer as their first instance.
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "comment_2").
	 * @param array  $atts    Shortcode attributes.
	 * @return string HTML.
	 */
	private function render_field( string $form_id, string $field, array $atts ): string {
		$parsed = $this->parse_field_token( $field );
		$base   = $parsed['base'];

		switch ( $base ) {
			case 'name':
			case 'surname':
			case 'patronymic':
				return $this->render_text_field( $form_id, $field, $atts );
			case 'phone':
				return $this->render_phone_field( $form_id, $field, $atts );
			case 'email':
				return $this->render_email_field( $form_id, $field, $atts );
			case 'comment':
				return $this->render_textarea_field( $form_id, $field, $atts );
			case 'select':
				return $this->render_select_field( $form_id, $field, $atts );
			case 'checkbox':
				return $this->render_checkbox_field( $form_id, $field, $atts );
			case 'agreement':
				return $this->render_agreement_field( $form_id, $field, $atts );
			case 'hidden':
				return $this->render_hidden_field( $atts );
			case 'text':
				return $this->render_static_text_field( $field, $atts );
			case 'radio':
				return $this->render_radio_field( $form_id, $field, $atts );
			default:
				return '';
		}
	}

	/**
	 * Render a text input field (name / surname / patronymic and indexed variants).
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "name", "name_2").
	 * @param array  $atts    Attributes.
	 * @return string
	 */
	private function render_text_field( string $form_id, string $field, array $atts ): string {
		$parsed = $this->parse_field_token( $field );
		$base   = $parsed['base'];
		$index  = $parsed['index'];

		$field_id   = 'cfs-' . $form_id . '-' . $field;
		$error_id   = $field_id . '-error';
		$auto_label = $index > 1
			? $this->get_base_label( $base ) . ' ' . $index
			: $this->get_base_label( $base );

		$label       = $this->get_field_attr( $field, $base, 'label', $atts, $auto_label );
		$required    = 'yes' === $this->get_field_attr( $field, $base, 'required', $atts, 'no' );
		$placeholder = (string) $this->get_field_attr( $field, $base, 'placeholder', $atts, '' );

		$icon_name = (string) $this->get_field_attr( $field, $base, 'icon', $atts, '' );
		$icon_html = $this->render_icon( $icon_name );
		$has_icon  = '' !== $icon_html;

		ob_start();
		?>
		<div class="cfs-field cfs-field--<?php echo esc_attr( $base ); ?><?php echo $has_icon ? ' cfs-field--has-icon' : ''; ?>">
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( $required ) : ?>
					<span class="cfs-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<input
				type="text"
				id="<?php echo esc_attr( $field_id ); ?>"
				name="cfs_<?php echo esc_attr( $field ); ?>"
				class="cfs-input"
				<?php if ( $placeholder ) : ?>
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
				<?php endif; ?>
				<?php if ( $required ) : ?>
					aria-required="true"
				<?php endif; ?>
				aria-describedby="<?php echo esc_attr( $error_id ); ?>"
			>
			<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG from internal library ?>
			<span id="<?php echo esc_attr( $error_id ); ?>" class="cfs-error" role="alert" aria-live="polite"></span>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render phone field with mask (supports indexed variants).
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "phone", "phone_2").
	 * @param array  $atts    Attributes.
	 * @return string
	 */
	private function render_phone_field( string $form_id, string $field, array $atts ): string {
		$parsed = $this->parse_field_token( $field );
		$base   = $parsed['base'];
		$index  = $parsed['index'];

		$field_id   = 'cfs-' . $form_id . '-' . $field;
		$error_id   = $field_id . '-error';
		$auto_label = $index > 1
			? $this->get_base_label( $base ) . ' ' . $index
			: $this->get_base_label( $base );

		$label       = $this->get_field_attr( $field, $base, 'label', $atts, $auto_label );
		$required    = 'yes' === $this->get_field_attr( $field, $base, 'required', $atts, 'yes' );
		$placeholder = (string) $this->get_field_attr( $field, $base, 'placeholder', $atts, '+7 (___) ___-__-__' );

		$icon_name = (string) $this->get_field_attr( $field, $base, 'icon', $atts, '' );
		$icon_html = $this->render_icon( $icon_name );
		$has_icon  = '' !== $icon_html;

		ob_start();
		?>
		<div class="cfs-field cfs-field--<?php echo esc_attr( $base ); ?><?php echo $has_icon ? ' cfs-field--has-icon' : ''; ?>">
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( $required ) : ?>
					<span class="cfs-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<input
				type="tel"
				id="<?php echo esc_attr( $field_id ); ?>"
				name="cfs_<?php echo esc_attr( $field ); ?>"
				class="cfs-input cfs-input--phone"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				<?php if ( $required ) : ?>
					aria-required="true"
				<?php endif; ?>
				aria-describedby="<?php echo esc_attr( $error_id ); ?>"
				autocomplete="tel"
			>
			<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG from internal library ?>
			<span id="<?php echo esc_attr( $error_id ); ?>" class="cfs-error" role="alert" aria-live="polite"></span>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render email field (supports indexed variants).
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "email", "email_2").
	 * @param array  $atts    Attributes.
	 * @return string
	 */
	private function render_email_field( string $form_id, string $field, array $atts ): string {
		$parsed = $this->parse_field_token( $field );
		$base   = $parsed['base'];
		$index  = $parsed['index'];

		$field_id   = 'cfs-' . $form_id . '-' . $field;
		$error_id   = $field_id . '-error';
		$auto_label = $index > 1
			? $this->get_base_label( $base ) . ' ' . $index
			: $this->get_base_label( $base );

		$label       = $this->get_field_attr( $field, $base, 'label', $atts, $auto_label );
		$required    = 'yes' === $this->get_field_attr( $field, $base, 'required', $atts, 'no' );
		$placeholder = (string) $this->get_field_attr( $field, $base, 'placeholder', $atts, '' );

		$icon_name = (string) $this->get_field_attr( $field, $base, 'icon', $atts, '' );
		$icon_html = $this->render_icon( $icon_name );
		$has_icon  = '' !== $icon_html;

		ob_start();
		?>
		<div class="cfs-field cfs-field--<?php echo esc_attr( $base ); ?><?php echo $has_icon ? ' cfs-field--has-icon' : ''; ?>">
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( $required ) : ?>
					<span class="cfs-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<input
				type="email"
				id="<?php echo esc_attr( $field_id ); ?>"
				name="cfs_<?php echo esc_attr( $field ); ?>"
				class="cfs-input"
				<?php if ( $placeholder ) : ?>
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
				<?php endif; ?>
				<?php if ( $required ) : ?>
					aria-required="true"
				<?php endif; ?>
				aria-describedby="<?php echo esc_attr( $error_id ); ?>"
				autocomplete="email"
			>
			<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG from internal library ?>
			<span id="<?php echo esc_attr( $error_id ); ?>" class="cfs-error" role="alert" aria-live="polite"></span>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render textarea (comment) field (supports indexed variants).
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "comment", "comment_2").
	 * @param array  $atts    Attributes.
	 * @return string
	 */
	private function render_textarea_field( string $form_id, string $field, array $atts ): string {
		$parsed = $this->parse_field_token( $field );
		$base   = $parsed['base'];
		$index  = $parsed['index'];

		$field_id   = 'cfs-' . $form_id . '-' . $field;
		$error_id   = $field_id . '-error';
		$auto_label = $index > 1
			? $this->get_base_label( $base ) . ' ' . $index
			: $this->get_base_label( $base );

		$label       = $this->get_field_attr( $field, $base, 'label', $atts, $auto_label );
		$required    = 'yes' === $this->get_field_attr( $field, $base, 'required', $atts, 'no' );
		$placeholder = (string) $this->get_field_attr( $field, $base, 'placeholder', $atts, '' );
		$rows        = max( 2, (int) ( $atts['comment_rows'] ?? 4 ) );

		$icon_name = (string) $this->get_field_attr( $field, $base, 'icon', $atts, '' );
		$icon_html = $this->render_icon( $icon_name );
		$has_icon  = '' !== $icon_html;

		ob_start();
		?>
		<div class="cfs-field cfs-field--<?php echo esc_attr( $base ); ?><?php echo $has_icon ? ' cfs-field--has-icon' : ''; ?>">
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( $required ) : ?>
					<span class="cfs-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<textarea
				id="<?php echo esc_attr( $field_id ); ?>"
				name="cfs_<?php echo esc_attr( $field ); ?>"
				class="cfs-input cfs-textarea"
				rows="<?php echo esc_attr( (string) $rows ); ?>"
				<?php if ( $placeholder ) : ?>
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
				<?php endif; ?>
				<?php if ( $required ) : ?>
					aria-required="true"
				<?php endif; ?>
				aria-describedby="<?php echo esc_attr( $error_id ); ?>"
			></textarea>
			<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG from internal library ?>
			<span id="<?php echo esc_attr( $error_id ); ?>" class="cfs-error" role="alert" aria-live="polite"></span>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render select field (supports indexed variants).
	 *
	 * The select field does NOT use a floating label. Instead the label text
	 * is shown as the first disabled empty <option> (placeholder behaviour).
	 * An aria-label is added for screen readers.
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "select", "select_2").
	 * @param array  $atts    Attributes.
	 * @return string
	 */
	private function render_select_field( string $form_id, string $field, array $atts ): string {
		$parsed = $this->parse_field_token( $field );
		$base   = $parsed['base'];
		$index  = $parsed['index'];

		$field_id   = 'cfs-' . $form_id . '-' . $field;
		$error_id   = $field_id . '-error';
		$auto_label = $index > 1
			? $this->get_base_label( $base ) . ' ' . $index
			: $this->get_base_label( $base );

		$label    = $this->get_field_attr( $field, $base, 'label', $atts, $auto_label );
		$required = 'yes' === $this->get_field_attr( $field, $base, 'required', $atts, 'no' );

		// Allow per-field options via "{field}_options" (e.g. "select_2_options");
		// fall back to the global "select_options" shortcode attribute.
		$options_raw = (string) $this->get_field_attr( $field, $base, 'options', $atts, $atts['select_options'] ?? '' );
		$options     = array();
		if ( ! empty( $options_raw ) ) {
			foreach ( explode( ',', $options_raw ) as $opt ) {
				$parts = explode( ':', $opt, 2 );
				if ( 2 === count( $parts ) ) {
					$options[ trim( $parts[1] ) ] = trim( $parts[0] );
				}
			}
		}

		$icon_name = (string) $this->get_field_attr( $field, $base, 'icon', $atts, '' );
		$icon_html = $this->render_icon( $icon_name );
		$has_icon  = '' !== $icon_html;

		ob_start();
		?>
		<div class="cfs-field cfs-field--<?php echo esc_attr( $base ); ?><?php echo $has_icon ? ' cfs-field--has-icon' : ''; ?>">
			<select
				id="<?php echo esc_attr( $field_id ); ?>"
				name="cfs_<?php echo esc_attr( $field ); ?>"
				class="cfs-input cfs-select"
				aria-label="<?php echo esc_attr( $label ); ?>"
				<?php if ( $required ) : ?>
					aria-required="true"
				<?php endif; ?>
				aria-describedby="<?php echo esc_attr( $error_id ); ?>"
			>
				<option value="" disabled selected>— <?php echo esc_html( $label ); ?> —</option>
				<?php foreach ( $options as $val => $text ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG from internal library ?>
			<span id="<?php echo esc_attr( $error_id ); ?>" class="cfs-error" role="alert" aria-live="polite"></span>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render checkbox field (supports indexed variants).
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "checkbox", "checkbox_2").
	 * @param array  $atts    Attributes.
	 * @return string
	 */
	private function render_checkbox_field( string $form_id, string $field, array $atts ): string {
		$parsed = $this->parse_field_token( $field );
		$base   = $parsed['base'];
		$index  = $parsed['index'];

		$field_id   = 'cfs-' . $form_id . '-' . $field;
		$error_id   = $field_id . '-error';
		$auto_label = $index > 1
			? $this->get_base_label( $base ) . ' ' . $index
			: $this->get_base_label( $base );

		$label    = $this->get_field_attr( $field, $base, 'label', $atts, $auto_label );
		$required = 'yes' === $this->get_field_attr( $field, $base, 'required', $atts, 'no' );

		ob_start();
		?>
		<div class="cfs-field cfs-field--checkbox">
			<label class="cfs-checkbox-label">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $field_id ); ?>"
					name="cfs_<?php echo esc_attr( $field ); ?>"
					value="1"
					class="cfs-checkbox"
					<?php if ( $required ) : ?>
						aria-required="true"
					<?php endif; ?>
					aria-describedby="<?php echo esc_attr( $error_id ); ?>"
				>
				<span><?php echo esc_html( $label ); ?></span>
				<?php if ( $required ) : ?>
					<span class="cfs-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<span id="<?php echo esc_attr( $error_id ); ?>" class="cfs-error" role="alert" aria-live="polite"></span>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render hidden field.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	private function render_hidden_field( array $atts ): string {
		if ( empty( $atts['hidden_name'] ) ) {
			return '';
		}
		return sprintf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( $atts['hidden_name'] ),
			esc_attr( $atts['hidden_value'] )
		);
	}

	/**
	 * Render a static text / heading field (display-only, no input element).
	 *
	 * The label content is run through wp_kses() to allow <a>, <strong>,
	 * <em> and <br> but nothing else. Passes through the {field}_label
	 * attribute; supports indexed variants (text_2, text_3).
	 *
	 * @param string $field Full field token (e.g. "text", "text_2").
	 * @param array  $atts  Shortcode attributes.
	 * @return string
	 */
	private function render_static_text_field( string $field, array $atts ): string {
		$parsed = $this->parse_field_token( $field );
		$base   = $parsed['base'];

		$content_raw = (string) $this->get_field_attr( $field, $base, 'label', $atts, '' );
		if ( '' === $content_raw ) {
			return '';
		}

		$allowed_html = array(
			'a'      => array( 'href' => array(), 'target' => array(), 'rel' => array(), 'class' => array() ),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
		);
		$content = wp_kses( $content_raw, $allowed_html );

		return '<div class="cfs-field cfs-field--text">'
			. '<p class="cfs-text-content">' . $content . '</p>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_kses() applied
			. '</div>';
	}

	/**
	 * Render a radio button group (supports indexed variants).
	 *
	 * Options are defined via the {field}_options shortcode attribute in the
	 * same "Label:value,Label2:value2" format as the select field.
	 * The group is wrapped in a <fieldset> + <legend> for accessibility.
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "radio", "radio_2").
	 * @param array  $atts    Shortcode attributes.
	 * @return string
	 */
	private function render_radio_field( string $form_id, string $field, array $atts ): string {
		$parsed = $this->parse_field_token( $field );
		$base   = $parsed['base'];
		$index  = $parsed['index'];

		$error_id   = 'cfs-' . $form_id . '-' . $field . '-error';
		$auto_label = $index > 1
			? $this->get_base_label( $base ) . ' ' . $index
			: $this->get_base_label( $base );

		$label    = $this->get_field_attr( $field, $base, 'label', $atts, $auto_label );
		$required = 'yes' === $this->get_field_attr( $field, $base, 'required', $atts, 'no' );

		// Per-instance options override the global radio_options attribute.
		$options_raw = (string) $this->get_field_attr( $field, $base, 'options', $atts, $atts['radio_options'] ?? '' );
		$options     = array();
		if ( ! empty( $options_raw ) ) {
			foreach ( explode( ',', $options_raw ) as $opt ) {
				$parts = explode( ':', $opt, 2 );
				if ( 2 === count( $parts ) ) {
					$options[ trim( $parts[1] ) ] = trim( $parts[0] );
				}
			}
		}

		$field_name = 'cfs_' . $field;

		ob_start();
		?>
		<fieldset class="cfs-field cfs-field--radio" aria-describedby="<?php echo esc_attr( $error_id ); ?>">
			<legend class="cfs-field-legend">
				<?php echo esc_html( $label ); ?>
				<?php if ( $required ) : ?>
					<span class="cfs-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</legend>
			<div class="cfs-radio-group">
				<?php foreach ( $options as $val => $text ) : ?>
					<?php $radio_id = 'cfs-' . $form_id . '-' . $field . '-' . sanitize_key( $val ); ?>
					<label class="cfs-radio-label" for="<?php echo esc_attr( $radio_id ); ?>">
						<input
							type="radio"
							id="<?php echo esc_attr( $radio_id ); ?>"
							name="<?php echo esc_attr( $field_name ); ?>"
							value="<?php echo esc_attr( $val ); ?>"
							class="cfs-radio"
							<?php if ( $required ) : ?>
								aria-required="true"
							<?php endif; ?>
						>
						<span><?php echo esc_html( $text ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<span id="<?php echo esc_attr( $error_id ); ?>" class="cfs-error" role="alert" aria-live="polite"></span>
		</fieldset>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render agreement field — a checkbox whose label text comes from the
	 * admin setting `cfs_agreement_text` (with HTML links allowed).
	 *
	 * The label may contain <a href="..."> tags; it is run through wp_kses()
	 * so only safe HTML is output. This is intentionally NOT escaped with
	 * esc_html() to allow clickable links in the agreement text.
	 *
	 * Shortcode attribute `agreement_label` overrides the admin option for
	 * this specific form. Indexed variants (agreement_2) are supported.
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "agreement", "agreement_2").
	 * @param array  $atts    Shortcode attributes.
	 * @return string
	 */
	private function render_agreement_field( string $form_id, string $field, array $atts ): string {
		$parsed = $this->parse_field_token( $field );
		$base   = $parsed['base'];

		$field_id = 'cfs-' . $form_id . '-' . $field;
		$error_id = $field_id . '-error';

		/*
		 * Label resolution order:
		 *   1. Shortcode attr {field}_label (e.g. "agreement_2_label")
		 *   2. Shortcode attr {base}_label  (e.g. "agreement_label")
		 *   3. Admin option  cfs_agreement_text
		 *   4. Hard-coded fallback
		 */
		$default_text = (string) get_option( 'cfs_agreement_text', '' );
		if ( '' === $default_text ) {
			$default_text = __( 'Я даю согласие на обработку персональных данных', 'contact-form-submissions' );
		}

		/*
		 * get_field_attr() returns '' when shortcode_atts sets the default to ''.
		 * That empty string beats $default_text, so we must fall back explicitly.
		 */
		$label_raw = (string) $this->get_field_attr( $field, $base, 'label', $atts, '' );
		if ( '' === $label_raw ) {
			$label_raw = $default_text;
		}

		// Allow only anchor tags — no scripts, no other HTML.
		$allowed_html = array(
			'a' => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
				'class'  => array(),
			),
		);
		$label = wp_kses( $label_raw, $allowed_html );

		$required = 'yes' === $this->get_field_attr( $field, $base, 'required', $atts, 'no' );

		ob_start();
		?>
		<div class="cfs-field cfs-field--checkbox cfs-field--agreement">
			<label class="cfs-checkbox-label">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $field_id ); ?>"
					name="cfs_<?php echo esc_attr( $field ); ?>"
					value="1"
					class="cfs-checkbox"
					<?php if ( $required ) : ?>
						aria-required="true"
					<?php endif; ?>
					aria-describedby="<?php echo esc_attr( $error_id ); ?>"
				>
				<span><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_kses() applied above ?></span>
				<?php if ( $required ) : ?>
					<span class="cfs-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<span id="<?php echo esc_attr( $error_id ); ?>" class="cfs-error" role="alert" aria-live="polite"></span>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
