<?php
/**
 * Form model — template, compiled schema and per-form settings.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Form
 */
class CFS_Form {

	const META_TEMPLATE     = '_cfs_template';
	const META_COMPILED     = '_cfs_compiled';
	const META_HASH         = '_cfs_compiled_hash';
	const META_ERRORS       = '_cfs_compile_errors';
	const META_AFTER        = '_cfs_after';
	const META_MAIL         = '_cfs_mail';
	const META_INTEGRATIONS = '_cfs_integrations';
	const META_SETTINGS     = '_cfs_settings';
	const META_HISTORY      = '_cfs_history';

	/**
	 * How many previous template versions to keep.
	 */
	const HISTORY_LIMIT = 10;

	/**
	 * Post ID; 0 for an unsaved form built from a raw template.
	 *
	 * @var int
	 */
	private $id = 0;

	/**
	 * Form title.
	 *
	 * @var string
	 */
	private $title = '';

	/**
	 * Form slug.
	 *
	 * @var string
	 */
	private $slug = '';

	/**
	 * Template text.
	 *
	 * @var string
	 */
	private $template = '';

	/**
	 * Compiled schema, or null until it is needed.
	 *
	 * @var array|null
	 */
	private $schema = null;

	/**
	 * Compilation diagnostics.
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Settings groups, lazily loaded from post meta.
	 *
	 * @var array<string, array|null>
	 */
	private $groups = array(
		self::META_AFTER        => null,
		self::META_MAIL         => null,
		self::META_INTEGRATIONS => null,
		self::META_SETTINGS     => null,
	);

	/**
	 * Per-request render counter, keyed by form ID.
	 *
	 * @var array<int, int>
	 */
	private static $instances = array();

	/**
	 * Loaded forms, keyed by ID — one form is often rendered several times.
	 *
	 * @var array<int, CFS_Form>
	 */
	private static $cache = array();

	/**
	 * Load a form by post ID.
	 *
	 * @param int $id Post ID.
	 * @return CFS_Form|null
	 */
	public static function load( int $id ) {
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || CFS_Post_Type::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$form            = new self();
		$form->id        = (int) $post->ID;
		$form->title     = (string) $post->post_title;
		$form->slug      = (string) $post->post_name;
		$form->template  = (string) get_post_meta( $post->ID, self::META_TEMPLATE, true );

		self::$cache[ $id ] = $form;

		return $form;
	}

	/**
	 * Load a form by slug.
	 *
	 * @param string $slug Post slug.
	 * @return CFS_Form|null
	 */
	public static function load_by_slug( string $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'        => CFS_Post_Type::POST_TYPE,
				'name'             => $slug,
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		);

		return empty( $posts ) ? null : self::load( (int) $posts[0]->ID );
	}

	/**
	 * Build an unsaved form from a raw template.
	 *
	 * Used by the admin preview and by the legacy shortcode adapter, both of
	 * which need a working form object without a database record.
	 *
	 * @param string $template Template text.
	 * @param array  $groups   Optional settings groups, keyed by meta key.
	 * @param string $title    Optional title.
	 * @return CFS_Form
	 */
	public static function from_template( string $template, array $groups = array(), string $title = '' ): CFS_Form {
		$form           = new self();
		$form->template = $template;
		$form->title    = $title;

		foreach ( $groups as $key => $values ) {
			if ( array_key_exists( $key, $form->groups ) ) {
				$form->groups[ $key ] = array_merge( self::defaults( $key ), (array) $values );
			}
		}

		return $form;
	}

	/**
	 * List every form, newest first.
	 *
	 * @param array $args Extra get_posts() arguments.
	 * @return CFS_Form[]
	 */
	public static function all( array $args = array() ): array {
		$posts = get_posts(
			array_merge(
				array(
					'post_type'        => CFS_Post_Type::POST_TYPE,
					'post_status'      => array( 'publish', 'draft' ),
					'numberposts'      => 200,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'suppress_filters' => false,
				),
				$args
			)
		);

		$forms = array();
		foreach ( $posts as $post ) {
			$form = self::load( (int) $post->ID );
			if ( $form ) {
				$forms[] = $form;
			}
		}

		return $forms;
	}

	/**
	 * Create and persist a new form.
	 *
	 * @param string $title    Form title.
	 * @param string $template Initial template.
	 * @return CFS_Form|null
	 */
	public static function create( string $title, string $template = '' ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => CFS_Post_Type::POST_TYPE,
				'post_title'  => wp_slash( '' !== $title ? $title : __( 'Новая форма', 'contact-form-submissions' ) ),
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return null;
		}

		$form = self::load( (int) $post_id );
		if ( ! $form ) {
			return null;
		}

		$form->set_template( '' !== $template ? $template : self::starter_template() );
		$form->save();

		return $form;
	}

	/**
	 * Template used for a brand new form.
	 *
	 * @return string
	 */
	public static function starter_template(): string {
		return "[name* first_name label=\"Имя\" icon=\"user\"]\n"
			. "[phone* phone label=\"Телефон\" icon=\"phone\"]\n"
			. "[textarea comment label=\"Комментарий\" rows=\"4\"]\n"
			. "[submit \"Отправить\"]\n";
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Identity
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Post ID (0 for unsaved forms).
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Form title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return $this->title;
	}

	/**
	 * Set the title (persisted by save()).
	 *
	 * @param string $title New title.
	 */
	public function set_title( string $title ): void {
		$this->title = sanitize_text_field( $title );
	}

	/**
	 * Form slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * The shortcode that renders this form.
	 *
	 * @return string
	 */
	public function get_shortcode(): string {
		return sprintf( '[contact_form id="%d"]', $this->id );
	}

	/**
	 * Next render instance number for this form within the current request.
	 *
	 * Two copies of the same form on one page must not share element IDs.
	 *
	 * @return int
	 */
	public function next_instance(): int {
		$key = $this->id;
		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = 0;
		}
		return ++self::$instances[ $key ];
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Template and schema
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Raw template text.
	 *
	 * @return string
	 */
	public function get_template(): string {
		return $this->template;
	}

	/**
	 * Replace the template. Sanitisation happens here, once.
	 *
	 * @param string $template Raw template as typed by the author.
	 */
	public function set_template( string $template ): void {
		$clean = CFS_Template_Sanitizer::sanitize( $template );

		if ( $clean === $this->template ) {
			return;
		}

		$this->template = $clean;
		$this->schema   = null;
		$this->errors   = array();
	}

	/**
	 * Compiled schema.
	 *
	 * @return array
	 */
	public function get_schema(): array {
		if ( null !== $this->schema ) {
			return $this->schema;
		}

		if ( $this->id > 0 ) {
			$stored = get_post_meta( $this->id, self::META_COMPILED, true );
			$hash   = (string) get_post_meta( $this->id, self::META_HASH, true );

			// The stored hash guards against a template edited directly in the
			// database, or a site copied without its meta staying in sync. The
			// version guards against the plugin itself moving on: an update that
			// adds a key to the compiled field leaves every cached schema
			// without it, and the hash still matches because the template never
			// changed. Without this check those forms keep serving the old shape
			// until somebody re-saves them by hand.
			if ( is_array( $stored ) && ! empty( $stored )
				&& md5( $this->template ) === $hash
				&& CFS_Form_Compiler::SCHEMA_VERSION === (int) ( $stored['version'] ?? 0 ) ) {
				$this->schema = $stored;
				$this->errors = (array) get_post_meta( $this->id, self::META_ERRORS, true );
				return $this->schema;
			}
		}

		$this->compile();

		// Persist the freshly compiled schema so the next request skips this.
		if ( $this->id > 0 ) {
			$this->store_schema();
		}

		return $this->schema;
	}

	/**
	 * Compilation diagnostics (errors and warnings).
	 *
	 * @return array
	 */
	public function get_errors(): array {
		$this->get_schema();
		return $this->errors;
	}

	/**
	 * Whether the form has blocking errors.
	 *
	 * @return bool
	 */
	public function has_fatal_errors(): bool {
		foreach ( $this->get_errors() as $error ) {
			if ( 'error' === ( $error['level'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the form can be rendered at all.
	 *
	 * @return bool
	 */
	public function is_renderable(): bool {
		$schema = $this->get_schema();
		return ! empty( $schema['fields'] );
	}

	/**
	 * All compiled fields, keyed by name.
	 *
	 * @return array<string, array>
	 */
	public function get_fields(): array {
		$schema = $this->get_schema();
		return (array) ( $schema['fields'] ?? array() );
	}

	/**
	 * One compiled field.
	 *
	 * @param string $name Field name.
	 * @return array|null
	 */
	public function get_field( string $name ) {
		$fields = $this->get_fields();
		return $fields[ $name ] ?? null;
	}

	/**
	 * Field name holding a given role, or '' when the form has none.
	 *
	 * @param string $role One of name|email|phone|comment.
	 * @return string
	 */
	public function get_role_field( string $role ): string {
		$schema = $this->get_schema();
		return (string) ( $schema['roles'][ $role ] ?? '' );
	}

	/**
	 * Template hash, used to detect submissions from a stale page.
	 *
	 * @return string
	 */
	public function get_hash(): string {
		$schema = $this->get_schema();
		return (string) ( $schema['hash'] ?? md5( $this->template ) );
	}

	/**
	 * Whether the form renders as a multi-step wizard.
	 *
	 * @return bool
	 */
	public function is_multi_step(): bool {
		$schema = $this->get_schema();
		return count( (array) ( $schema['steps'] ?? array() ) ) > 1;
	}

	/**
	 * Compile the template into $this->schema / $this->errors.
	 */
	private function compile(): void {
		$result       = CFS_Form_Compiler::compile( $this->template );
		$this->schema = $result['schema'];
		$this->errors = $result['errors'];
	}

	/**
	 * Write the compiled schema to post meta.
	 *
	 * Every meta value goes through wp_slash() first. update_post_meta() runs
	 * wp_unslash() on whatever it is handed — it expects slashed data, the way
	 * $_POST arrives — so an unslashed value silently loses one level of
	 * backslashes on the way into the database. That ate the escapes out of the
	 * default HTML5 patterns: "\+7 \(\d{3}\)…" was stored as "+7 (d{3})…",
	 * which is no longer a regex a browser can compile, so the pattern was
	 * dropped and the field lost its client-side validation entirely.
	 */
	private function store_schema(): void {
		update_post_meta( $this->id, self::META_COMPILED, wp_slash( $this->schema ) );
		update_post_meta( $this->id, self::META_HASH, md5( $this->template ) );
		update_post_meta( $this->id, self::META_ERRORS, wp_slash( $this->errors ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Settings groups
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Default values for a settings group.
	 *
	 * @param string $key Meta key of the group.
	 * @return array
	 */
	public static function defaults( string $key ): array {
		switch ( $key ) {
			case self::META_AFTER:
				return array(
					'mode'              => 'message',
					'message'           => __( 'Спасибо! Мы свяжемся с вами.', 'contact-form-submissions' ),
					'redirect_url'      => '',
					'redirect_delay'    => 2,
					'reset_form'        => true,
					'scroll_to_message' => true,
					'close_modal'       => false,
					'errors'            => array(
						'validation' => '',
						'spam'       => '',
						'rate_limit' => '',
						'server'     => '',
					),
				);

			case self::META_MAIL:
				return array(
					'admin'     => array(
						'enabled'    => true,
						'to'         => '',
						'cc'         => '',
						'bcc'        => '',
						'subject'    => '',
						'from_name'  => '',
						'from_email' => '',
						'reply_to'   => '',
						'body'       => '',
						'html'       => true,
					),
					'autoreply' => array(
						'enabled'    => false,
						'to'         => '',
						'cc'         => '',
						'bcc'        => '',
						'subject'    => '',
						'from_name'  => '',
						'from_email' => '',
						'reply_to'   => '',
						'body'       => '',
						'html'       => true,
					),
				);

			case self::META_INTEGRATIONS:
				return array();

			case self::META_SETTINGS:
				return array(
					'container'                => 'div',
					'modal_button_text'        => __( 'Открыть форму', 'contact-form-submissions' ),
					'modal_button_icon_before' => '',
					'modal_button_icon_after'  => '',
					'modal_button_class'       => '',
					'css_class'                => '',
					'style_theme'              => '',
					'save_to_db'               => true,
					'next_text'                => __( 'Далее', 'contact-form-submissions' ),
					'back_text'                => __( 'Назад', 'contact-form-submissions' ),
					'next_icon_after'          => '',
					'back_icon_before'         => '',
				);
		}

		return array();
	}

	/**
	 * Read a settings group, merged over its defaults.
	 *
	 * @param string $key Meta key of the group.
	 * @return array
	 */
	public function get_group( string $key ): array {
		if ( ! array_key_exists( $key, $this->groups ) ) {
			return array();
		}

		if ( null === $this->groups[ $key ] ) {
			$stored = $this->id > 0 ? get_post_meta( $this->id, $key, true ) : array();
			$stored = is_array( $stored ) ? $stored : array();

			// Merge one level deep so a stored group missing a nested key (a
			// new option shipped in an update) still picks up its default.
			$defaults = self::defaults( $key );
			$merged   = array_merge( $defaults, $stored );
			foreach ( $defaults as $sub_key => $sub_default ) {
				if ( is_array( $sub_default ) && isset( $stored[ $sub_key ] ) && is_array( $stored[ $sub_key ] ) ) {
					$merged[ $sub_key ] = array_merge( $sub_default, $stored[ $sub_key ] );
				}
			}

			$this->groups[ $key ] = $merged;
		}

		return $this->groups[ $key ];
	}

	/**
	 * Replace a settings group in memory (persisted by save()).
	 *
	 * @param string $key    Meta key of the group.
	 * @param array  $values New values.
	 */
	public function set_group( string $key, array $values ): void {
		if ( array_key_exists( $key, $this->groups ) ) {
			$this->groups[ $key ] = $values;
		}
	}

	/**
	 * "After submit" settings.
	 *
	 * @return array
	 */
	public function get_after(): array {
		return $this->get_group( self::META_AFTER );
	}

	/**
	 * Mail settings.
	 *
	 * @return array
	 */
	public function get_mail(): array {
		return $this->get_group( self::META_MAIL );
	}

	/**
	 * Integration settings.
	 *
	 * @return array
	 */
	public function get_integrations(): array {
		return $this->get_group( self::META_INTEGRATIONS );
	}

	/**
	 * Presentation settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		return $this->get_group( self::META_SETTINGS );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Persistence
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Persist title, template, compiled schema and every loaded settings group.
	 *
	 * @return bool
	 */
	public function save(): bool {
		if ( $this->id <= 0 ) {
			return false;
		}

		$post = get_post( $this->id );
		if ( $post instanceof WP_Post && $post->post_title !== $this->title ) {
			wp_update_post(
				array(
					'ID'         => $this->id,
					'post_title' => wp_slash( $this->title ),
				)
			);
			$this->slug = (string) get_post_field( 'post_name', $this->id );
		}

		$previous = (string) get_post_meta( $this->id, self::META_TEMPLATE, true );
		if ( $previous !== $this->template ) {
			$this->push_history( $previous );
			update_post_meta( $this->id, self::META_TEMPLATE, wp_slash( $this->template ) );
		}

		// Recompile on every save: a warning list can change because of an
		// add-on registering a new field type, not only because of an edit.
		$this->compile();
		$this->store_schema();

		foreach ( $this->groups as $key => $values ) {
			if ( null !== $values ) {
				update_post_meta( $this->id, $key, wp_slash( $values ) );
			}
		}

		unset( self::$cache[ $this->id ] );

		/**
		 * Fires after a form is saved.
		 *
		 * @param CFS_Form $form Saved form.
		 */
		do_action( 'cfs_form_saved', $this );

		return true;
	}

	/**
	 * Delete the form.
	 *
	 * @return bool
	 */
	public function delete(): bool {
		if ( $this->id <= 0 ) {
			return false;
		}
		unset( self::$cache[ $this->id ] );
		return (bool) wp_delete_post( $this->id, true );
	}

	/**
	 * Duplicate the form, including every settings group.
	 *
	 * @return CFS_Form|null
	 */
	public function duplicate() {
		/* translators: %s: original form title */
		$title = sprintf( __( '%s (копия)', 'contact-form-submissions' ), $this->title );

		$copy = self::create( $title, $this->template );
		if ( ! $copy ) {
			return null;
		}

		foreach ( array_keys( $this->groups ) as $key ) {
			$copy->set_group( $key, $this->get_group( $key ) );
		}
		$copy->save();

		return $copy;
	}

	/**
	 * Push a template version onto the history stack.
	 *
	 * @param string $template Template being replaced.
	 */
	private function push_history( string $template ): void {
		if ( '' === $template ) {
			return;
		}

		$history = get_post_meta( $this->id, self::META_HISTORY, true );
		$history = is_array( $history ) ? $history : array();

		array_unshift(
			$history,
			array(
				'template' => $template,
				'time'     => current_time( 'mysql' ),
				'user'     => get_current_user_id(),
			)
		);

		update_post_meta( $this->id, self::META_HISTORY, wp_slash( array_slice( $history, 0, self::HISTORY_LIMIT ) ) );
	}

	/**
	 * Stored template history, newest first.
	 *
	 * @return array
	 */
	public function get_history(): array {
		if ( $this->id <= 0 ) {
			return array();
		}
		$history = get_post_meta( $this->id, self::META_HISTORY, true );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Export the whole form as a portable array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'_type'        => 'cfs_form',
			'_version'     => CFS_VERSION,
			'title'        => $this->title,
			'template'     => $this->template,
			'after'        => $this->get_after(),
			'mail'         => $this->get_mail(),
			'integrations' => $this->get_integrations(),
			'settings'     => $this->get_settings(),
		);
	}

	/**
	 * Create a form from an exported array.
	 *
	 * @param array $data Exported structure.
	 * @return CFS_Form|null
	 */
	public static function from_array( array $data ) {
		if ( ! isset( $data['template'] ) ) {
			return null;
		}

		$form = self::create(
			(string) ( $data['title'] ?? __( 'Импортированная форма', 'contact-form-submissions' ) ),
			(string) $data['template']
		);

		if ( ! $form ) {
			return null;
		}

		$map = array(
			'after'        => self::META_AFTER,
			'mail'         => self::META_MAIL,
			'integrations' => self::META_INTEGRATIONS,
			'settings'     => self::META_SETTINGS,
		);

		foreach ( $map as $source => $meta_key ) {
			if ( isset( $data[ $source ] ) && is_array( $data[ $source ] ) ) {
				$form->set_group( $meta_key, array_merge( self::defaults( $meta_key ), $data[ $source ] ) );
			}
		}

		$form->save();

		return $form;
	}
}
