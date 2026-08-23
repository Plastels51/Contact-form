<?php
/**
 * The [contact_form] shortcode and front-end assets.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Shortcode
 */
class CFS_Shortcode {

	/**
	 * Whether a form has been rendered in this request.
	 *
	 * @var bool
	 */
	private $assets_needed = false;

	/**
	 * Whether the assets have already been enqueued.
	 *
	 * @var bool
	 */
	private $assets_done = false;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_shortcode( 'contact_form', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$raw = is_array( $atts ) ? $atts : array();

		$atts = shortcode_atts(
			array(
				'id'        => '',
				'slug'      => '',
				'class'     => '',
				'container' => '',
			),
			$raw,
			'contact_form'
		);

		$form       = $this->resolve_form( $atts );
		$names_form = '' !== (string) $atts['id'] || '' !== (string) $atts['slug'];

		// Only a shortcode that names no form at all can be a 2.x one. A
		// shortcode that does name one and fails to resolve is a broken
		// reference, and silently rendering some default form instead would
		// hide a deleted form behind a working-looking page.
		if ( null === $form && ! $names_form ) {
			/**
			 * Give the compatibility module a chance to render a 2.x shortcode.
			 *
			 * The core knows nothing about the legacy attribute syntax: the
			 * module in includes/legacy/ hooks this filter, and deleting that
			 * folder simply stops the filter from being answered.
			 *
			 * @param string|null $html Rendered HTML, or null when unhandled.
			 * @param array       $atts Raw shortcode attributes.
			 */
			$legacy = apply_filters( 'cfs_render_legacy_form', null, $raw );

			if ( is_string( $legacy ) && '' !== $legacy ) {
				$this->assets_needed = true;
				return $legacy;
			}
		}

		if ( null === $form ) {
			return $this->render_missing_notice( $atts );
		}

		$this->enqueue_assets();

		$renderer = new CFS_Form_Renderer(
			$form,
			array(
				'css_class' => (string) $atts['class'],
				'container' => (string) $atts['container'],
			)
		);

		return $renderer->render();
	}

	/**
	 * Find the form referenced by the shortcode.
	 *
	 * @param array $atts Parsed attributes.
	 * @return CFS_Form|null
	 */
	private function resolve_form( array $atts ) {
		if ( '' !== (string) $atts['id'] ) {
			return CFS_Form::load( (int) $atts['id'] );
		}

		if ( '' !== (string) $atts['slug'] ) {
			return CFS_Form::load_by_slug( (string) $atts['slug'] );
		}

		return null;
	}

	/**
	 * Placeholder shown when the shortcode points at nothing.
	 *
	 * @param array $atts Parsed attributes.
	 * @return string
	 */
	private function render_missing_notice( array $atts ): string {
		if ( ! CFS_Post_Type::user_can_manage() ) {
			return '<!-- CFS: форма не найдена -->';
		}

		$reference = '' !== (string) $atts['id'] ? $atts['id'] : $atts['slug'];

		if ( '' === (string) $reference ) {
			return '<div class="cfs-form-broken notice notice-error"><p>'
				. esc_html__( 'В шорткоде [contact_form] не указана форма. Используйте [contact_form id="12"].', 'contact-form-submissions' )
				. '</p></div>';
		}

		return '<div class="cfs-form-broken notice notice-error"><p>'
			. sprintf(
				/* translators: %s: form id or slug from the shortcode */
				esc_html__( 'Форма «%s» не найдена — возможно, она была удалена.', 'contact-form-submissions' ),
				esc_html( (string) $reference )
			)
			. '</p></div>';
	}

	/**
	 * Enqueue assets early when the current post contains the shortcode.
	 *
	 * Shortcodes run during the_content, i.e. after wp_enqueue_scripts, so the
	 * stylesheet would otherwise be emitted mid-content and land in the footer,
	 * flashing unstyled markup. Scanning the post up front puts the CSS in
	 * <head> where it belongs; forms rendered from widgets, blocks or a theme
	 * call to do_shortcode() still fall back to the late enqueue in render().
	 */
	public function maybe_enqueue_assets(): void {
		if ( $this->assets_needed || $this->content_has_shortcode() ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Whether the post being displayed contains the shortcode.
	 *
	 * @return bool
	 */
	private function content_has_shortcode(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || '' === (string) $post->post_content ) {
			return false;
		}

		return has_shortcode( (string) $post->post_content, 'contact_form' );
	}

	/**
	 * Enqueue the front-end stylesheet and script.
	 */
	public function enqueue_assets(): void {
		$this->assets_needed = true;

		if ( $this->assets_done ) {
			return;
		}

		// Late shortcodes (widgets, do_shortcode() in a template) run after
		// wp_enqueue_scripts; enqueueing then still works, it just lands lower
		// in the document.
		if ( ! did_action( 'wp_enqueue_scripts' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			return;
		}

		$this->assets_done = true;

		if ( get_option( 'cfs_disable_styles', 'no' ) !== 'yes' ) {
			wp_enqueue_style( 'cfs-form', CFS_PLUGIN_URL . 'assets/css/cfs-form.css', array(), CFS_VERSION );

			if ( get_option( 'cfs_style_theme', 'default' ) !== 'default' ) {
				wp_enqueue_style(
					'cfs-field-styles',
					CFS_PLUGIN_URL . 'assets/css/cfs-field-styles.css',
					array( 'cfs-form' ),
					CFS_VERSION
				);
			}

			if ( get_option( 'cfs_disable_btn_styles', 'no' ) !== 'yes' ) {
				wp_enqueue_style(
					'cfs-buttons',
					CFS_PLUGIN_URL . 'assets/css/cfs-buttons.css',
					array( 'cfs-form' ),
					CFS_VERSION
				);
			}
		}

		wp_enqueue_script( 'cfs-form', CFS_PLUGIN_URL . 'assets/js/cfs-form.js', array(), CFS_VERSION, true );

		wp_localize_script( 'cfs-form', 'cfsData', self::script_data() );
	}

	/**
	 * Data handed to the front-end script.
	 *
	 * @return array
	 */
	public static function script_data(): array {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cfs_submit_form' ),
			'debug'   => get_option( 'cfs_debug_mode', 'no' ) === 'yes',
			'i18n'    => array(
				'sending'        => __( 'Отправка...', 'contact-form-submissions' ),
				'error_general'  => __( 'Произошла ошибка. Попробуйте ещё раз.', 'contact-form-submissions' ),
				'required'       => __( 'Обязательное поле', 'contact-form-submissions' ),
				'invalid_email'  => __( 'Некорректный email', 'contact-form-submissions' ),
				'invalid_phone'  => __( 'Некорректный номер телефона', 'contact-form-submissions' ),
				'invalid_name'   => __( 'Допустимы только буквы, дефис и пробел.', 'contact-form-submissions' ),
				'select_one'     => __( 'Выберите хотя бы один вариант.', 'contact-form-submissions' ),
				'invalid_url'    => __( 'Введите корректный URL (например, https://...).', 'contact-form-submissions' ),
				'invalid_date'   => __( 'Некорректная дата.', 'contact-form-submissions' ),
				'date_min'       => __( 'Дата не может быть раньше ', 'contact-form-submissions' ),
				'date_max'       => __( 'Дата не может быть позже ', 'contact-form-submissions' ),
				'invalid_number' => __( 'Введите числовое значение.', 'contact-form-submissions' ),
				'num_min'        => __( 'Минимальное значение: ', 'contact-form-submissions' ),
				'num_max'        => __( 'Максимальное значение: ', 'contact-form-submissions' ),
				'num_step'       => __( 'Значение не соответствует шагу.', 'contact-form-submissions' ),
				'too_long'       => __( 'Слишком длинное значение.', 'contact-form-submissions' ),
				'too_short'      => __( 'Слишком короткое значение.', 'contact-form-submissions' ),
			),
		);
	}
}
