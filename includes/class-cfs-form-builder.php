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
					'invalid_name'   => __( 'Допустимы только буквы, дефис и пробел.', 'contact-form-submissions' ),
					'invalid_date'   => __( 'Некорректная дата.', 'contact-form-submissions' ),
					'date_min'       => __( 'Дата не может быть раньше ', 'contact-form-submissions' ),
					'date_max'       => __( 'Дата не может быть позже ', 'contact-form-submissions' ),
					'invalid_number' => __( 'Введите числовое значение.', 'contact-form-submissions' ),
					'num_min'        => __( 'Минимальное значение: ', 'contact-form-submissions' ),
					'num_max'        => __( 'Максимальное значение: ', 'contact-form-submissions' ),
					'num_step'       => __( 'Значение не соответствует шагу.', 'contact-form-submissions' ),
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
			'date'   => __( 'Дата', 'contact-form-submissions' ),
			'number' => __( 'Число', 'contact-form-submissions' ),
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
			'user'     => '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 11.9999C13.1867 11.9999 14.3467 11.648 15.3334 10.9888C16.3201 10.3295 17.0892 9.3924 17.5433 8.29604C17.9974 7.19969 18.1162 5.99329 17.8847 4.8294C17.6532 3.66551 17.0818 2.59642 16.2426 1.7573C15.4035 0.918186 14.3344 0.346741 13.1705 0.11523C12.0067 -0.116281 10.8003 0.00253868 9.7039 0.456664C8.60754 0.91079 7.67047 1.67983 7.01118 2.66652C6.35189 3.65322 6 4.81325 6 5.99994C6.00159 7.59075 6.63424 9.11595 7.75911 10.2408C8.88399 11.3657 10.4092 11.9984 12 11.9999ZM12 1.99994C12.7911 1.99994 13.5645 2.23454 14.2223 2.67406C14.8801 3.11359 15.3928 3.7383 15.6955 4.46921C15.9983 5.20011 16.0775 6.00438 15.9231 6.7803C15.7688 7.55623 15.3878 8.26896 14.8284 8.82837C14.269 9.38778 13.5563 9.76874 12.7804 9.92308C12.0044 10.0774 11.2002 9.99821 10.4693 9.69546C9.73836 9.39271 9.11365 8.88002 8.67412 8.22222C8.2346 7.56443 8 6.79107 8 5.99994C8 4.93908 8.42143 3.92166 9.17157 3.17151C9.92172 2.42137 10.9391 1.99994 12 1.99994V1.99994Z"></path><path d="M12 14.0006C9.61386 14.0033 7.32622 14.9523 5.63896 16.6396C3.95171 18.3268 3.00265 20.6145 3 23.0006C3 23.2658 3.10536 23.5202 3.29289 23.7077C3.48043 23.8953 3.73478 24.0006 4 24.0006C4.26522 24.0006 4.51957 23.8953 4.70711 23.7077C4.89464 23.5202 5 23.2658 5 23.0006C5 21.1441 5.7375 19.3636 7.05025 18.0509C8.36301 16.7381 10.1435 16.0006 12 16.0006C13.8565 16.0006 15.637 16.7381 16.9497 18.0509C18.2625 19.3636 19 21.1441 19 23.0006C19 23.2658 19.1054 23.5202 19.2929 23.7077C19.4804 23.8953 19.7348 24.0006 20 24.0006C20.2652 24.0006 20.5196 23.8953 20.7071 23.7077C20.8946 23.5202 21 23.2658 21 23.0006C20.9974 20.6145 20.0483 18.3268 18.361 16.6396C16.6738 14.9523 14.3861 14.0033 12 14.0006V14.0006Z"></path></svg>',	
			'phone'    => '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M22.9602 16.6527L20.6202 14.8527C20.0428 14.303 19.2757 13.997 18.4785 13.9981C17.6813 13.9992 16.9151 14.3074 16.3392 14.8587L14.8702 16.0267C13.3144 15.3827 11.9012 14.4376 10.7117 13.2458C9.52228 12.0539 8.58006 10.6388 7.93921 9.08169L9.09721 7.62569C9.64893 7.04987 9.95751 6.28357 9.95881 5.4861C9.96012 4.68862 9.65404 3.92132 9.10421 3.34369L7.30621 0.999694C7.2798 0.964484 7.25107 0.931077 7.22021 0.899694C6.64913 0.325879 5.87515 -0.000108385 5.06562 -0.00778077C4.25609 -0.0154531 3.47607 0.295806 2.89421 0.858694L1.74421 1.85869C-5.97479 10.0687 13.9442 30.0037 22.1442 22.1587L23.0542 21.1097C23.6337 20.5286 23.9592 19.7414 23.9592 18.9207C23.9592 18.1 23.6337 17.3128 23.0542 16.7317C23.0243 16.7037 22.993 16.6773 22.9602 16.6527ZM21.5962 19.7527L20.6852 20.8027C14.7482 26.4177 -2.53979 10.1137 3.10721 3.32469L4.25721 2.32469C4.45636 2.12666 4.7237 2.01229 5.00446 2.00504C5.28522 1.99779 5.55811 2.0982 5.76721 2.28569L7.55321 4.60869C7.5794 4.64409 7.60814 4.67751 7.63921 4.70869C7.84348 4.91471 7.95809 5.19308 7.95809 5.48319C7.95809 5.77331 7.84348 6.05168 7.63921 6.25769C7.61107 6.28323 7.58468 6.31062 7.56021 6.33969L6.00421 8.29969C5.89343 8.43849 5.8216 8.60428 5.79612 8.78003C5.77063 8.95578 5.79241 9.13515 5.85921 9.29969C6.61236 11.3148 7.78988 13.1444 9.31198 14.6646C10.8341 16.1848 12.6652 17.3601 14.6812 18.1107C14.8435 18.1726 15.0192 18.1911 15.1909 18.1645C15.3625 18.1378 15.5243 18.0669 15.6602 17.9587L17.6202 16.3997C17.6496 16.376 17.6777 16.3506 17.7042 16.3237C17.8155 16.2163 17.9477 16.1329 18.0926 16.0787C18.2375 16.0246 18.3919 16.0008 18.5464 16.0088C18.7009 16.0169 18.852 16.0567 18.9905 16.1256C19.1289 16.1946 19.2517 16.2913 19.3512 16.4097L21.6772 18.1967C21.8679 18.4165 21.9656 18.7018 21.9498 18.9924C21.9339 19.283 21.8057 19.556 21.5922 19.7537L21.5962 19.7527Z"></path></svg>',
			'email'    => '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 0.999878H5C3.67441 1.00147 2.40356 1.52876 1.46622 2.4661C0.528882 3.40344 0.00158786 4.67428 0 5.99988L0 17.9999C0.00158786 19.3255 0.528882 20.5963 1.46622 21.5337C2.40356 22.471 3.67441 22.9983 5 22.9999H19C20.3256 22.9983 21.5964 22.471 22.5338 21.5337C23.4711 20.5963 23.9984 19.3255 24 17.9999V5.99988C23.9984 4.67428 23.4711 3.40344 22.5338 2.4661C21.5964 1.52876 20.3256 1.00147 19 0.999878ZM5 2.99988H19C19.5988 3.00106 20.1835 3.18139 20.679 3.51768C21.1744 3.85397 21.5579 4.33082 21.78 4.88688L14.122 12.5459C13.5584 13.1072 12.7954 13.4223 12 13.4223C11.2046 13.4223 10.4416 13.1072 9.878 12.5459L2.22 4.88688C2.44215 4.33082 2.82561 3.85397 3.32105 3.51768C3.81648 3.18139 4.40121 3.00106 5 2.99988ZM19 20.9999H5C4.20435 20.9999 3.44129 20.6838 2.87868 20.1212C2.31607 19.5586 2 18.7955 2 17.9999V7.49988L8.464 13.9599C9.40263 14.8961 10.6743 15.4219 12 15.4219C13.3257 15.4219 14.5974 14.8961 15.536 13.9599L22 7.49988V17.9999C22 18.7955 21.6839 19.5586 21.1213 20.1212C20.5587 20.6838 19.7956 20.9999 19 20.9999Z"></path></svg>',
			'comment'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
			'select'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',
			'write'	   => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M18.6562 0.930194L6.4642 13.1222C5.99855 13.5853 5.62938 14.1363 5.37808 14.743C5.12679 15.3498 4.99835 16.0004 5.0002 16.6572V18.0002C5.0002 18.2654 5.10556 18.5198 5.2931 18.7073C5.48063 18.8948 5.73499 19.0002 6.0002 19.0002H7.3432C7.99997 19.002 8.65057 18.8736 9.25736 18.6223C9.86415 18.371 10.4151 18.0019 10.8782 17.5362L23.0702 5.34419C23.6546 4.75836 23.9828 3.96467 23.9828 3.13719C23.9828 2.30972 23.6546 1.51602 23.0702 0.930194C22.4759 0.362088 21.6854 0.0450439 20.8632 0.0450439C20.041 0.0450439 19.2505 0.362088 18.6562 0.930194ZM21.6562 3.93019L9.4642 16.1222C8.90033 16.6826 8.13821 16.9981 7.3432 17.0002H7.0002V16.6572C7.00229 15.8622 7.31777 15.1001 7.8782 14.5362L20.0702 2.34419C20.2838 2.14015 20.5678 2.02629 20.8632 2.02629C21.1586 2.02629 21.4426 2.14015 21.6562 2.34419C21.8661 2.55471 21.984 2.83989 21.984 3.13719C21.984 3.4345 21.8661 3.71968 21.6562 3.93019Z"></path><path border-block="" glass="" d="M23 8.979C22.7348 8.979 22.4804 9.08436 22.2929 9.27189C22.1054 9.45943 22 9.71379 22 9.979V15H18C17.2044 15 16.4413 15.3161 15.8787 15.8787C15.3161 16.4413 15 17.2044 15 18V22H5C4.20435 22 3.44129 21.6839 2.87868 21.1213C2.31607 20.5587 2 19.7957 2 19V5C2 4.20435 2.31607 3.44129 2.87868 2.87868C3.44129 2.31607 4.20435 2 5 2H14.042C14.3072 2 14.5616 1.89464 14.7491 1.70711C14.9366 1.51957 15.042 1.26522 15.042 1C15.042 0.734784 14.9366 0.48043 14.7491 0.292893C14.5616 0.105357 14.3072 0 14.042 0L5 0C3.67441 0.00158786 2.40356 0.528882 1.46622 1.46622C0.528882 2.40356 0.00158786 3.67441 0 5L0 19C0.00158786 20.3256 0.528882 21.5964 1.46622 22.5338C2.40356 23.4711 3.67441 23.9984 5 24H16.343C16.9999 24.0019 17.6507 23.8735 18.2576 23.6222C18.8646 23.3709 19.4157 23.0017 19.879 22.536L22.535 19.878C23.0008 19.4149 23.37 18.864 23.6215 18.2572C23.873 17.6504 24.0016 16.9998 24 16.343V9.979C24 9.71379 23.8946 9.45943 23.7071 9.27189C23.5196 9.08436 23.2652 8.979 23 8.979ZM18.465 21.122C18.063 21.523 17.5547 21.8006 17 21.922V18C17 17.7348 17.1054 17.4804 17.2929 17.2929C17.4804 17.1054 17.7348 17 18 17H21.925C21.8013 17.5535 21.524 18.0609 21.125 18.464L18.465 21.122Z"></path></svg>',
			'check'    => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M22.3189 4.43107L8.49988 18.2491C8.40697 18.3423 8.29655 18.4164 8.17497 18.4669C8.05339 18.5174 7.92303 18.5434 7.79138 18.5434C7.65972 18.5434 7.52937 18.5174 7.40778 18.4669C7.2862 18.4164 7.17579 18.3423 7.08288 18.2491L1.73888 12.9001C1.64597 12.8068 1.53555 12.7328 1.41397 12.6823C1.29239 12.6318 1.16203 12.6058 1.03038 12.6058C0.898723 12.6058 0.768365 12.6318 0.646783 12.6823C0.5252 12.7328 0.414787 12.8068 0.321877 12.9001V12.9001C0.2286 12.993 0.154588 13.1034 0.104086 13.225C0.0535845 13.3466 0.0275879 13.4769 0.0275879 13.6086C0.0275879 13.7402 0.0535845 13.8706 0.104086 13.9922C0.154588 14.1137 0.2286 14.2242 0.321877 14.3171L5.66788 19.6621C6.23183 20.225 6.99607 20.5411 7.79288 20.5411C8.58968 20.5411 9.35393 20.225 9.91788 19.6621L23.7359 5.84707C23.829 5.75418 23.9029 5.64383 23.9533 5.52234C24.0037 5.40085 24.0297 5.2706 24.0297 5.13907C24.0297 5.00753 24.0037 4.87729 23.9533 4.7558C23.9029 4.63431 23.829 4.52396 23.7359 4.43107C23.643 4.33779 23.5326 4.26378 23.411 4.21328C23.2894 4.16278 23.159 4.13678 23.0274 4.13678C22.8957 4.13678 22.7654 4.16278 22.6438 4.21328C22.5222 4.26378 22.4118 4.33779 22.3189 4.43107Z"></path></svg>',
			
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
				// Submit button icons and extra CSS class.
				'button_icon_before' => '',
				'button_icon_after'  => '',
				'button_class'       => '',
				'modal_button_class' => '',
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
				// Date field.
				'date_label'         => __( 'Дата', 'contact-form-submissions' ),
				'date_placeholder'   => '',
				'date_required'      => 'no',
				'date_min'           => '',
				'date_max'           => '',
				// Number field.
				'number_label'       => __( 'Число', 'contact-form-submissions' ),
				'number_placeholder' => '',
				'number_required'    => 'no',
				'number_min'         => '',
				'number_max'         => '',
				'number_step'        => '',
				// NOTE: {field}_pattern is NOT registered here intentionally.
				// Picked up from $raw_atts merge-back only when user sets it explicitly.
				// Built-in default for name/surname/patronymic lives in render_text_field().
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
				'constraints'       => $this->build_constraints_map( $clean_tokens, $atts ),
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
				class="cfs-modal-btn<?php echo $atts['modal_button_class'] ? ' ' . esc_attr( implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $atts['modal_button_class'] ), -1, PREG_SPLIT_NO_EMPTY ) ) ) ) : ''; ?>"
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
					class="cfs-btn cfs-btn--submit<?php echo $atts['button_class'] ? ' ' . esc_attr( implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $atts['button_class'] ), -1, PREG_SPLIT_NO_EMPTY ) ) ) ) : ''; ?>"
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
	 * Build a per-token constraints map for date and number fields.
	 * Stored in the form config transient so the AJAX handler can validate server-side.
	 *
	 * @param array $tokens Field tokens.
	 * @param array $atts   Shortcode attributes.
	 * @return array
	 */
	private function build_constraints_map( array $tokens, array $atts ): array {
		$map = array();
		foreach ( $tokens as $token ) {
			$parsed = $this->parse_field_token( $token );
			$base   = $parsed['base'];
			if ( 'date' === $base ) {
				$map[ $token ] = array(
					'type' => 'date',
					'min'  => (string) $this->get_field_attr( $token, 'date', 'min', $atts, '' ),
					'max'  => (string) $this->get_field_attr( $token, 'date', 'max', $atts, '' ),
				);
			} elseif ( 'number' === $base ) {
				$map[ $token ] = array(
					'type' => 'number',
					'min'  => (string) $this->get_field_attr( $token, 'number', 'min', $atts, '' ),
					'max'  => (string) $this->get_field_attr( $token, 'number', 'max', $atts, '' ),
					'step' => (string) $this->get_field_attr( $token, 'number', 'step', $atts, '' ),
				);
			}
		}
		return $map;
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
			case 'date':
				return $this->render_date_field( $form_id, $field, $atts );
			case 'number':
				return $this->render_number_field( $form_id, $field, $atts );
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
		// Pattern for browser constraint validation (enables CSS :invalid + JS validity API).
		// Built-in default covers Cyrillic + Latin letters, spaces, hyphens, apostrophes.
		$default_pattern = "[A-Za-zА-ЯЁа-яёЁ\s\-']+";
		$pattern         = (string) $this->get_field_attr( $field, $base, 'pattern', $atts, $default_pattern );

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
			<?php if ( $pattern ) : ?>
				pattern="<?php echo esc_attr( $pattern ); ?>"
			<?php endif; ?>
			>
			<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG from internal library ?>
			<span id="<?php echo esc_attr( $error_id ); ?>" class="cfs-error" role="alert" aria-live="polite"></span>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a date input field.
	 *
	 * Supports optional min/max attributes for constraint validation.
	 * The label is always rendered in the floated state (class "focused") because
	 * the browser always shows the date picker placeholder UI.
	 *
	 * Shortcode example:
	 *   [contact_form fields="name*,date*" date_label="Дата рождения"
	 *                 date_min="1924-01-01" date_max="2006-12-31"]
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "date", "date_2").
	 * @param array  $atts    Shortcode attributes.
	 * @return string
	 */
	private function render_date_field( string $form_id, string $field, array $atts ): string {
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
		$min      = (string) $this->get_field_attr( $field, $base, 'min', $atts, '' );
		$max      = (string) $this->get_field_attr( $field, $base, 'max', $atts, '' );

		$icon_name = (string) $this->get_field_attr( $field, $base, 'icon', $atts, '' );
		$icon_html = $this->render_icon( $icon_name );
		$has_icon  = '' !== $icon_html;

		ob_start();
		?>
		<div class="cfs-field cfs-field--date focused<?php echo $has_icon ? ' cfs-field--has-icon' : ''; ?>">
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( $required ) : ?>
					<span class="cfs-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<input
				type="date"
				id="<?php echo esc_attr( $field_id ); ?>"
				name="cfs_<?php echo esc_attr( $field ); ?>"
				class="cfs-input"
				<?php if ( $min ) : ?>
					min="<?php echo esc_attr( $min ); ?>"
				<?php endif; ?>
				<?php if ( $max ) : ?>
					max="<?php echo esc_attr( $max ); ?>"
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
	 * Render a number input field.
	 *
	 * Supports min, max, and step attributes.
	 * The label is always rendered in the floated state (class "focused") because
	 * the browser always shows the spinner UI.
	 *
	 * Shortcode example:
	 *   [contact_form fields="name*,number*" number_label="Возраст"
	 *                 number_min="18" number_max="99" number_step="1"]
	 *
	 * @param string $form_id Form ID.
	 * @param string $field   Full field token (e.g. "number", "number_2").
	 * @param array  $atts    Shortcode attributes.
	 * @return string
	 */
	private function render_number_field( string $form_id, string $field, array $atts ): string {
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
		$min         = (string) $this->get_field_attr( $field, $base, 'min', $atts, '' );
		$max         = (string) $this->get_field_attr( $field, $base, 'max', $atts, '' );
		$step        = (string) $this->get_field_attr( $field, $base, 'step', $atts, '' );

		$icon_name = (string) $this->get_field_attr( $field, $base, 'icon', $atts, '' );
		$icon_html = $this->render_icon( $icon_name );
		$has_icon  = '' !== $icon_html;

		ob_start();
		?>
		<div class="cfs-field cfs-field--number focused<?php echo $has_icon ? ' cfs-field--has-icon' : ''; ?>">
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( $required ) : ?>
					<span class="cfs-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<input
				type="number"
				id="<?php echo esc_attr( $field_id ); ?>"
				name="cfs_<?php echo esc_attr( $field ); ?>"
				class="cfs-input"
				<?php if ( $placeholder ) : ?>
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
				<?php endif; ?>
				<?php if ( $min !== '' ) : ?>
					min="<?php echo esc_attr( $min ); ?>"
				<?php endif; ?>
				<?php if ( $max !== '' ) : ?>
					max="<?php echo esc_attr( $max ); ?>"
				<?php endif; ?>
				<?php if ( $step !== '' ) : ?>
					step="<?php echo esc_attr( $step ); ?>"
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
				<p><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_kses() applied above ?></p>
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
