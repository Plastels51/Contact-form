<?php
/**
 * Admin screen: the form editor.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Admin_Form_Editor
 */
class CFS_Admin_Form_Editor {

	/**
	 * Style variants offered for the fields.
	 */
	const THEMES = array( '', 'default', 'underline', 'outlined-top', 'filled', 'contained', 'left-label' );

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_post_cfs_save_form', array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_cfs_preview_form', array( $this, 'ajax_preview' ) );

		// See filter_admin_title() for why this page needs it.
		add_filter( 'admin_title', array( $this, 'filter_admin_title' ), 10, 2 );
	}

	/**
	 * Supply the browser tab title.
	 *
	 * This screen is registered with a null menu parent — the correct way to
	 * give a page no sidebar entry while keeping it reachable by URL and
	 * passing WordPress's own access check (see the comment in
	 * CFS_Admin::register_menus()). The cost is that get_admin_page_title()
	 * finds nothing to show for it: that function locates a page's title by
	 * searching $submenu[$parent], and a null-parent page has no such entry.
	 * admin_title is the filter WordPress itself provides for exactly this.
	 *
	 * @param string $admin_title Full tab text WordPress computed.
	 * @param string $title       Page title WordPress resolved, '' here.
	 * @return string
	 */
	public function filter_admin_title( $admin_title, $title ) {
		if ( '' !== (string) $title || ! isset( $_GET['page'] ) || 'cfs-form' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $admin_title;
		}

		$form_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form    = $form_id > 0 ? CFS_Form::load( $form_id ) : null;
		$screen  = ( $form && '' !== $form->get_title() ) ? $form->get_title() : __( 'Редактирование формы', 'contact-form-submissions' );

		// Same pattern wp-admin/admin-header.php uses, translated by core's
		// own 'default' domain rather than a new string of ours.
		return sprintf( __( '%1$s &lsaquo; %2$s &#8212; WordPress' ), $screen, get_bloginfo( 'name' ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Saving
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Persist the editor form.
	 */
	public function handle_save(): void {
		$form_id = isset( $_POST['cfs_form_id'] ) ? (int) $_POST['cfs_form_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		check_admin_referer( 'cfs_save_form_' . $form_id );

		if ( ! CFS_Post_Type::user_can_manage() ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'contact-form-submissions' ) );
		}

		$form = CFS_Form::load( $form_id );
		if ( ! $form ) {
			wp_die( esc_html__( 'Форма не найдена.', 'contact-form-submissions' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$title = isset( $_POST['cfs_title'] ) ? sanitize_text_field( wp_unslash( $_POST['cfs_title'] ) ) : '';
		if ( '' !== $title ) {
			$form->set_title( $title );
		}

		if ( isset( $_POST['cfs_template'] ) ) {
			// Deliberately not sanitize_textarea_field(): the template is markup
			// plus field tags, and the sanitising it needs is kses inside
			// CFS_Form::set_template().
			$form->set_template( (string) wp_unslash( $_POST['cfs_template'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$form->set_group( CFS_Form::META_AFTER, $this->sanitize_after( (array) ( $_POST['cfs_after'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$form->set_group( CFS_Form::META_MAIL, $this->sanitize_mail( (array) ( $_POST['cfs_mail'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$form->set_group( CFS_Form::META_SETTINGS, $this->sanitize_settings( (array) ( $_POST['cfs_settings'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$form->set_group( CFS_Form::META_INTEGRATIONS, $this->sanitize_integrations( (array) ( $_POST['cfs_integrations'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$tab = isset( $_POST['cfs_tab'] ) ? sanitize_key( wp_unslash( $_POST['cfs_tab'] ) ) : 'template';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$form->save();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'cfs-form',
					'id'       => $form_id,
					'tab'      => $tab,
					'cfs_done' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitise the "after submit" group.
	 *
	 * @param array $raw Raw posted values.
	 * @return array
	 */
	private function sanitize_after( array $raw ): array {
		$defaults = CFS_Form::defaults( CFS_Form::META_AFTER );

		$mode = isset( $raw['mode'] ) ? sanitize_key( (string) $raw['mode'] ) : 'message';
		if ( ! in_array( $mode, array( 'message', 'redirect', 'message_redirect' ), true ) ) {
			$mode = 'message';
		}

		$errors = array();
		foreach ( array_keys( $defaults['errors'] ) as $key ) {
			$errors[ $key ] = isset( $raw['errors'][ $key ] )
				? sanitize_text_field( (string) $raw['errors'][ $key ] )
				: '';
		}

		return array(
			'mode'              => $mode,
			// The success banner is inserted as HTML, so a link back to the
			// site works — kses keeps that from becoming an injection point.
			'message'           => wp_kses( (string) ( $raw['message'] ?? '' ), $this->message_allowed_html() ),
			'redirect_url'      => esc_url_raw( (string) ( $raw['redirect_url'] ?? '' ) ),
			'redirect_delay'    => max( 0, min( 60, (int) ( $raw['redirect_delay'] ?? 2 ) ) ),
			'reset_form'        => ! empty( $raw['reset_form'] ),
			'scroll_to_message' => ! empty( $raw['scroll_to_message'] ),
			'close_modal'       => ! empty( $raw['close_modal'] ),
			'errors'            => $errors,
		);
	}

	/**
	 * Markup allowed in the success message.
	 *
	 * @return array<string, array>
	 */
	private function message_allowed_html(): array {
		return array(
			'a'      => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
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
	 * Sanitise the mail group.
	 *
	 * @param array $raw Raw posted values.
	 * @return array
	 */
	private function sanitize_mail( array $raw ): array {
		$clean = array();

		foreach ( array( 'admin', 'autoreply' ) as $slot ) {
			$values = isset( $raw[ $slot ] ) && is_array( $raw[ $slot ] ) ? $raw[ $slot ] : array();

			$clean[ $slot ] = array(
				'enabled'    => ! empty( $values['enabled'] ),
				// Recipients may contain mail tags like {email}, so they are
				// kept as text here and resolved (and validated) at send time.
				'to'         => sanitize_text_field( (string) ( $values['to'] ?? '' ) ),
				'cc'         => sanitize_text_field( (string) ( $values['cc'] ?? '' ) ),
				'bcc'        => sanitize_text_field( (string) ( $values['bcc'] ?? '' ) ),
				'subject'    => sanitize_text_field( (string) ( $values['subject'] ?? '' ) ),
				'from_name'  => sanitize_text_field( (string) ( $values['from_name'] ?? '' ) ),
				'from_email' => sanitize_text_field( (string) ( $values['from_email'] ?? '' ) ),
				'reply_to'   => sanitize_text_field( (string) ( $values['reply_to'] ?? '' ) ),
				'body'       => wp_kses_post( (string) ( $values['body'] ?? '' ) ),
				'html'       => ! empty( $values['html'] ),
			);
		}

		return $clean;
	}

	/**
	 * Sanitise the presentation group.
	 *
	 * @param array $raw Raw posted values.
	 * @return array
	 */
	private function sanitize_settings( array $raw ): array {
		$container = isset( $raw['container'] ) ? sanitize_key( (string) $raw['container'] ) : 'div';
		if ( ! in_array( $container, array( 'div', 'dialog' ), true ) ) {
			$container = 'div';
		}

		$theme = isset( $raw['style_theme'] ) ? sanitize_key( (string) $raw['style_theme'] ) : '';
		if ( ! in_array( $theme, self::THEMES, true ) ) {
			$theme = '';
		}

		return array(
			'container'                => $container,
			'modal_button_text'        => sanitize_text_field( (string) ( $raw['modal_button_text'] ?? '' ) ),
			'modal_button_icon_before' => sanitize_key( (string) ( $raw['modal_button_icon_before'] ?? '' ) ),
			'modal_button_icon_after'  => sanitize_key( (string) ( $raw['modal_button_icon_after'] ?? '' ) ),
			'modal_button_class'       => sanitize_text_field( (string) ( $raw['modal_button_class'] ?? '' ) ),
			'css_class'                => sanitize_text_field( (string) ( $raw['css_class'] ?? '' ) ),
			'style_theme'              => $theme,
			'save_to_db'               => ! empty( $raw['save_to_db'] ),
			'next_text'                => sanitize_text_field( (string) ( $raw['next_text'] ?? '' ) ),
			'back_text'                => sanitize_text_field( (string) ( $raw['back_text'] ?? '' ) ),
			'next_icon_after'          => sanitize_key( (string) ( $raw['next_icon_after'] ?? '' ) ),
			'back_icon_before'         => sanitize_key( (string) ( $raw['back_icon_before'] ?? '' ) ),
		);
	}

	/**
	 * Sanitise integration settings through the registry.
	 *
	 * @param array $raw Raw posted values.
	 * @return array
	 */
	private function sanitize_integrations( array $raw ): array {
		$clean = array();

		foreach ( CFS_Integrations::all() as $id => $item ) {
			$values = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();

			$clean[ $id ] = array(
				'enabled'  => ! empty( $values['enabled'] ),
				'settings' => CFS_Integrations::sanitize_settings(
					$id,
					isset( $values['settings'] ) && is_array( $values['settings'] ) ? $values['settings'] : array()
				),
			);
		}

		return $clean;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Preview
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Render a template without saving it.
	 */
	public function ajax_preview(): void {
		check_ajax_referer( 'cfs_editor', 'nonce' );

		if ( ! CFS_Post_Type::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'contact-form-submissions' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by CFS_Template_Sanitizer below.
		$template = isset( $_POST['template'] ) ? (string) wp_unslash( $_POST['template'] ) : '';
		$template = CFS_Template_Sanitizer::sanitize( $template );

		$settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? $this->sanitize_settings( (array) wp_unslash( $_POST['settings'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		// A preview of a modal form would render as a closed dialog, which
		// shows nothing at all — force it inline.
		$settings['container'] = 'div';

		$form   = CFS_Form::from_template( $template, array( CFS_Form::META_SETTINGS => $settings ) );
		$errors = $form->get_errors();

		wp_send_json_success(
			array(
				'html'   => $form->is_renderable() ? ( new CFS_Form_Renderer( $form ) )->render() : '',
				'errors' => $errors,
				'fields' => array_keys( $form->get_fields() ),
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Rendering
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Render the editor screen.
	 */
	public function render(): void {
		if ( ! CFS_Post_Type::user_can_manage() ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'contact-form-submissions' ) );
		}

		$form_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form    = CFS_Form::load( $form_id );

		if ( ! $form ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Форма не найдена', 'contact-form-submissions' ) . '</h1><p><a href="'
				. esc_url( CFS_Admin_Forms::list_url() ) . '">' . esc_html__( 'К списку форм', 'contact-form-submissions' ) . '</a></p></div>';
			return;
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'template'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'template'     => __( 'Форма', 'contact-form-submissions' ),
			'after'        => __( 'После отправки', 'contact-form-submissions' ),
			'mail'         => __( 'Письма', 'contact-form-submissions' ),
			'integrations' => __( 'Интеграции', 'contact-form-submissions' ),
			'settings'     => __( 'Дополнительно', 'contact-form-submissions' ),
		);

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'template';
		}

		$saved = isset( $_GET['cfs_done'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap cfs-editor" data-current-tab="<?php echo esc_attr( $tab ); ?>">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cfs-editor-form">
				<?php wp_nonce_field( 'cfs_save_form_' . $form_id ); ?>
				<input type="hidden" name="action" value="cfs_save_form">
				<input type="hidden" name="cfs_form_id" value="<?php echo esc_attr( (string) $form_id ); ?>">
				<input type="hidden" name="cfs_tab" value="<?php echo esc_attr( $tab ); ?>" class="cfs-active-tab-input">

				<div class="cfs-editor-header">
					<div class="cfs-editor-title">
						<label class="screen-reader-text" for="cfs-title"><?php esc_html_e( 'Название формы', 'contact-form-submissions' ); ?></label>
						<input
							type="text"
							id="cfs-title"
							name="cfs_title"
							value="<?php echo esc_attr( $form->get_title() ); ?>"
							placeholder="<?php esc_attr_e( 'Название формы', 'contact-form-submissions' ); ?>"
						>
						<code class="cfs-shortcode" tabindex="0" title="<?php esc_attr_e( 'Нажмите, чтобы скопировать', 'contact-form-submissions' ); ?>"><?php echo esc_html( $form->get_shortcode() ); ?></code>
					</div>
					<div class="cfs-editor-actions">
						<a href="<?php echo esc_url( CFS_Admin_Forms::list_url() ); ?>" class="button"><?php esc_html_e( 'К списку', 'contact-form-submissions' ); ?></a>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Сохранить', 'contact-form-submissions' ); ?></button>
					</div>
				</div>

				<?php if ( $saved ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Форма сохранена.', 'contact-form-submissions' ); ?></p></div>
				<?php endif; ?>

				<?php $this->render_errors( $form ); ?>

				<h2 class="nav-tab-wrapper cfs-tabs">
					<?php foreach ( $tabs as $slug => $label ) : ?>
						<button
							type="button"
							class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"
							data-tab="<?php echo esc_attr( $slug ); ?>"
						><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</h2>

				<div class="cfs-tab-panels">
					<?php
					$this->render_tab_template( $form, 'template' === $tab );
					$this->render_tab_after( $form, 'after' === $tab );
					$this->render_tab_mail( $form, 'mail' === $tab );
					$this->render_tab_integrations( $form, 'integrations' === $tab );
					$this->render_tab_settings( $form, 'settings' === $tab );
					?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Compilation errors and warnings.
	 *
	 * @param CFS_Form $form Form being edited.
	 */
	private function render_errors( CFS_Form $form ): void {
		$errors = $form->get_errors();
		if ( empty( $errors ) ) {
			return;
		}

		$fatal = $form->has_fatal_errors();
		?>
		<div class="notice notice-<?php echo $fatal ? 'error' : 'warning'; ?> cfs-compile-errors">
			<p><strong>
				<?php
				echo esc_html(
					$fatal
						? __( 'В шаблоне есть ошибки:', 'contact-form-submissions' )
						: __( 'Замечания по шаблону:', 'contact-form-submissions' )
				);
				?>
			</strong></p>
			<ul>
				<?php foreach ( $errors as $error ) : ?>
					<li>
						<?php if ( ! empty( $error['line'] ) ) : ?>
							<code>
								<?php
								/* translators: %d: line number in the template */
								printf( esc_html__( 'строка %d', 'contact-form-submissions' ), (int) $error['line'] );
								?>
							</code>
						<?php endif; ?>
						<?php echo esc_html( (string) $error['message'] ); ?>
						<?php if ( ! empty( $error['raw'] ) ) : ?>
							<code class="cfs-error-raw"><?php echo esc_html( (string) $error['raw'] ); ?></code>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Open a tab panel.
	 *
	 * @param string $slug   Tab slug.
	 * @param bool   $active Whether the tab is the active one.
	 */
	private function open_panel( string $slug, bool $active ): void {
		printf(
			'<div class="cfs-tab-panel" data-panel="%s"%s>',
			esc_attr( $slug ),
			$active ? '' : ' hidden'
		);
	}

	/**
	 * Tab: the template itself.
	 *
	 * @param CFS_Form $form   Form.
	 * @param bool     $active Whether the tab is active.
	 */
	private function render_tab_template( CFS_Form $form, bool $active ): void {
		$this->open_panel( 'template', $active );
		?>
		<div class="cfs-editor-columns">
			<div class="cfs-editor-main">
				<div class="cfs-tag-bar">
					<span class="cfs-tag-bar-label"><?php esc_html_e( 'Вставить поле:', 'contact-form-submissions' ); ?></span>
					<?php foreach ( $this->tag_buttons() as $type => $label ) : ?>
						<button type="button" class="button cfs-tag-btn" data-type="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>

				<label class="screen-reader-text" for="cfs-template"><?php esc_html_e( 'Шаблон формы', 'contact-form-submissions' ); ?></label>
				<textarea id="cfs-template" name="cfs_template" rows="22" spellcheck="false"><?php echo esc_textarea( $form->get_template() ); ?></textarea>

				<p class="description">
					<?php esc_html_e( 'Каждое поле — тег в квадратных скобках: [тип* имя атрибут="значение"]. Звёздочка делает поле обязательным. Всё вне скобок — обычный HTML.', 'contact-form-submissions' ); ?>
				</p>
			</div>

			<div class="cfs-editor-side">
				<div class="cfs-panel">
					<h3><?php esc_html_e( 'Предпросмотр', 'contact-form-submissions' ); ?></h3>
					<button type="button" class="button cfs-preview-btn"><?php esc_html_e( 'Обновить предпросмотр', 'contact-form-submissions' ); ?></button>
					<div class="cfs-preview-area"></div>
				</div>

				<div class="cfs-panel">
					<h3><?php esc_html_e( 'Поля формы', 'contact-form-submissions' ); ?></h3>
					<ul class="cfs-field-list">
						<?php foreach ( $form->get_fields() as $name => $field ) : ?>
							<li>
								<code><?php echo esc_html( $name ); ?></code>
								<span class="cfs-muted"><?php echo esc_html( (string) $field['type'] ); ?></span>
								<?php if ( ! empty( $field['required'] ) ) : ?>
									<span class="cfs-req">*</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
						<?php if ( empty( $form->get_fields() ) ) : ?>
							<li class="cfs-muted"><?php esc_html_e( 'Пока ни одного поля.', 'contact-form-submissions' ); ?></li>
						<?php endif; ?>
					</ul>
				</div>

				<?php $this->render_history( $form ); ?>
			</div>
		</div>
		</div>
		<?php
	}

	/**
	 * Previous template versions, with a restore button.
	 *
	 * @param CFS_Form $form Form.
	 */
	private function render_history( CFS_Form $form ): void {
		$history = $form->get_history();
		if ( empty( $history ) ) {
			return;
		}
		?>
		<div class="cfs-panel">
			<h3><?php esc_html_e( 'История шаблона', 'contact-form-submissions' ); ?></h3>
			<ul class="cfs-history">
				<?php foreach ( $history as $index => $entry ) : ?>
					<li>
						<span><?php echo esc_html( mysql2date( 'd.m.Y H:i', (string) $entry['time'] ) ); ?></span>
						<button
							type="button"
							class="button-link cfs-restore"
							data-template="<?php echo esc_attr( (string) $entry['template'] ); ?>"
						><?php esc_html_e( 'Вернуть', 'contact-form-submissions' ); ?></button>
					</li>
					<?php if ( $index >= 4 ) : ?>
						<?php break; ?>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<p class="description"><?php esc_html_e( 'Вернуть — подставит старый текст в редактор. Сохранение остаётся за вами.', 'contact-form-submissions' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Tab: what happens after a submission.
	 *
	 * @param CFS_Form $form   Form.
	 * @param bool     $active Whether the tab is active.
	 */
	private function render_tab_after( CFS_Form $form, bool $active ): void {
		$after = $form->get_after();
		$this->open_panel( 'after', $active );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Что делать', 'contact-form-submissions' ); ?></th>
				<td>
					<?php
					$modes = array(
						'message'          => __( 'Показать сообщение', 'contact-form-submissions' ),
						'redirect'         => __( 'Перенаправить на страницу', 'contact-form-submissions' ),
						'message_redirect' => __( 'Показать сообщение, затем перенаправить', 'contact-form-submissions' ),
					);
					foreach ( $modes as $value => $label ) :
						?>
						<label class="cfs-radio-row">
							<input type="radio" name="cfs_after[mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $after['mode'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cfs-after-message"><?php esc_html_e( 'Сообщение', 'contact-form-submissions' ); ?></label></th>
				<td>
					<textarea id="cfs-after-message" name="cfs_after[message]" rows="3" class="large-text"><?php echo esc_textarea( (string) $after['message'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Допустимы ссылки и простое форматирование: <a>, <strong>, <em>, <br>.', 'contact-form-submissions' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cfs-after-url"><?php esc_html_e( 'Куда перенаправить', 'contact-form-submissions' ); ?></label></th>
				<td>
					<input type="url" id="cfs-after-url" name="cfs_after[redirect_url]" value="<?php echo esc_attr( (string) $after['redirect_url'] ); ?>" class="large-text" placeholder="https://">
					<?php
					$pages = get_pages( array( 'number' => 100 ) );
					if ( ! empty( $pages ) ) :
						?>
						<p>
							<label>
								<?php esc_html_e( 'или выбрать страницу:', 'contact-form-submissions' ); ?>
								<select class="cfs-page-picker" data-target="cfs-after-url">
									<option value=""><?php esc_html_e( '— страница сайта —', 'contact-form-submissions' ); ?></option>
									<?php foreach ( $pages as $page ) : ?>
										<option value="<?php echo esc_url( (string) get_permalink( $page->ID ) ); ?>"><?php echo esc_html( $page->post_title ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cfs-after-delay"><?php esc_html_e( 'Задержка, сек', 'contact-form-submissions' ); ?></label></th>
				<td><input type="number" id="cfs-after-delay" name="cfs_after[redirect_delay]" value="<?php echo esc_attr( (string) $after['redirect_delay'] ); ?>" min="0" max="60" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Поведение', 'contact-form-submissions' ); ?></th>
				<td>
					<label class="cfs-check-row"><input type="checkbox" name="cfs_after[reset_form]" value="1" <?php checked( ! empty( $after['reset_form'] ) ); ?>> <?php esc_html_e( 'Очистить поля', 'contact-form-submissions' ); ?></label>
					<label class="cfs-check-row"><input type="checkbox" name="cfs_after[scroll_to_message]" value="1" <?php checked( ! empty( $after['scroll_to_message'] ) ); ?>> <?php esc_html_e( 'Прокрутить к сообщению', 'contact-form-submissions' ); ?></label>
					<label class="cfs-check-row"><input type="checkbox" name="cfs_after[close_modal]" value="1" <?php checked( ! empty( $after['close_modal'] ) ); ?>> <?php esc_html_e( 'Закрыть модальное окно', 'contact-form-submissions' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Тексты ошибок', 'contact-form-submissions' ); ?></th>
				<td>
					<?php
					$error_labels = array(
						'validation' => __( 'Ошибка в полях', 'contact-form-submissions' ),
						'spam'       => __( 'Заявка отклонена как спам', 'contact-form-submissions' ),
						'rate_limit' => __( 'Слишком много попыток', 'contact-form-submissions' ),
						'server'     => __( 'Ошибка сервера', 'contact-form-submissions' ),
					);
					foreach ( $error_labels as $key => $label ) :
						?>
						<p>
							<label>
								<span class="cfs-inline-label"><?php echo esc_html( $label ); ?></span>
								<input type="text" name="cfs_after[errors][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) ( $after['errors'][ $key ] ?? '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'по умолчанию', 'contact-form-submissions' ); ?>">
							</label>
						</p>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
		</div>
		<?php
	}

	/**
	 * Tab: notification and auto-reply mail.
	 *
	 * @param CFS_Form $form   Form.
	 * @param bool     $active Whether the tab is active.
	 */
	private function render_tab_mail( CFS_Form $form, bool $active ): void {
		$mail = $form->get_mail();
		$this->open_panel( 'mail', $active );
		?>
		<div class="cfs-mailtags">
			<strong><?php esc_html_e( 'Доступные подстановки:', 'contact-form-submissions' ); ?></strong>
			<?php foreach ( $this->mail_tags( $form ) as $tag => $hint ) : ?>
				<code class="cfs-mailtag" title="<?php echo esc_attr( $hint ); ?>"><?php echo esc_html( $tag ); ?></code>
			<?php endforeach; ?>
		</div>

		<?php
		$slots = array(
			'admin'     => __( 'Письмо администратору', 'contact-form-submissions' ),
			'autoreply' => __( 'Автоответ отправителю', 'contact-form-submissions' ),
		);

		foreach ( $slots as $slot => $slot_label ) :
			$values = $mail[ $slot ];
			?>
			<div class="cfs-panel cfs-mail-slot">
				<h3>
					<label>
						<input type="checkbox" name="cfs_mail[<?php echo esc_attr( $slot ); ?>][enabled]" value="1" <?php checked( ! empty( $values['enabled'] ) ); ?>>
						<?php echo esc_html( $slot_label ); ?>
					</label>
				</h3>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Кому', 'contact-form-submissions' ); ?></th>
						<td>
							<input type="text" name="cfs_mail[<?php echo esc_attr( $slot ); ?>][to]" value="<?php echo esc_attr( (string) $values['to'] ); ?>" class="large-text"
								placeholder="<?php echo esc_attr( 'admin' === $slot ? get_option( 'admin_email' ) : '{email}' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Копия / скрытая копия', 'contact-form-submissions' ); ?></th>
						<td>
							<input type="text" name="cfs_mail[<?php echo esc_attr( $slot ); ?>][cc]" value="<?php echo esc_attr( (string) $values['cc'] ); ?>" class="regular-text" placeholder="Cc">
							<input type="text" name="cfs_mail[<?php echo esc_attr( $slot ); ?>][bcc]" value="<?php echo esc_attr( (string) $values['bcc'] ); ?>" class="regular-text" placeholder="Bcc">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Тема', 'contact-form-submissions' ); ?></th>
						<td><input type="text" name="cfs_mail[<?php echo esc_attr( $slot ); ?>][subject]" value="<?php echo esc_attr( (string) $values['subject'] ); ?>" class="large-text"
							placeholder="<?php esc_attr_e( 'Новая заявка с сайта {site_name}', 'contact-form-submissions' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'От кого', 'contact-form-submissions' ); ?></th>
						<td>
							<input type="text" name="cfs_mail[<?php echo esc_attr( $slot ); ?>][from_name]" value="<?php echo esc_attr( (string) $values['from_name'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Имя отправителя', 'contact-form-submissions' ); ?>">
							<input type="text" name="cfs_mail[<?php echo esc_attr( $slot ); ?>][from_email]" value="<?php echo esc_attr( (string) $values['from_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Обратный адрес', 'contact-form-submissions' ); ?></th>
						<td>
							<input type="text" name="cfs_mail[<?php echo esc_attr( $slot ); ?>][reply_to]" value="<?php echo esc_attr( (string) $values['reply_to'] ); ?>" class="regular-text" placeholder="{email}">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Текст письма', 'contact-form-submissions' ); ?></th>
						<td>
							<textarea name="cfs_mail[<?php echo esc_attr( $slot ); ?>][body]" rows="8" class="large-text cfs-mail-body" placeholder="{all_fields}"><?php echo esc_textarea( (string) $values['body'] ); ?></textarea>
							<label class="cfs-check-row">
								<input type="checkbox" name="cfs_mail[<?php echo esc_attr( $slot ); ?>][html]" value="1" <?php checked( ! empty( $values['html'] ) ); ?>>
								<?php esc_html_e( 'Отправлять как HTML', 'contact-form-submissions' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Tab: integrations registered by add-ons.
	 *
	 * @param CFS_Form $form   Form.
	 * @param bool     $active Whether the tab is active.
	 */
	private function render_tab_integrations( CFS_Form $form, bool $active ): void {
		$this->open_panel( 'integrations', $active );

		$items = CFS_Integrations::all();

		if ( empty( $items ) ) {
			?>
			<div class="cfs-panel">
				<p><?php esc_html_e( 'Интеграции не установлены.', 'contact-form-submissions' ); ?></p>
				<p class="description">
					<?php esc_html_e( 'Каждая интеграция — отдельный плагин. Установите его, и он сам появится в этом списке со своими настройками.', 'contact-form-submissions' ); ?>
				</p>
			</div>
			</div>
			<?php
			return;
		}

		foreach ( $items as $id => $item ) :
			$state = CFS_Integrations::form_settings( $form, $id );
			?>
			<div class="cfs-panel cfs-integration" data-integration="<?php echo esc_attr( $id ); ?>">
				<h3>
					<label>
						<input type="checkbox" name="cfs_integrations[<?php echo esc_attr( $id ); ?>][enabled]" value="1" <?php checked( $state['enabled'] ); ?>>
						<?php echo esc_html( (string) $item['label'] ); ?>
					</label>
				</h3>

				<?php if ( '' !== (string) $item['description'] ) : ?>
					<p class="description"><?php echo esc_html( (string) $item['description'] ); ?></p>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<?php foreach ( (array) $item['fields'] as $key => $field ) : ?>
						<?php
						$key   = sanitize_key( (string) $key );
						$field = CFS_Integrations::normalize_field( (array) $field );
						$name  = sprintf( 'cfs_integrations[%s][settings][%s]', $id, $key );
						$value = $state['settings'][ $key ] ?? $field['default'];
						?>
						<tr>
							<th scope="row"><?php echo esc_html( (string) $field['label'] ); ?><?php echo ! empty( $field['required'] ) ? ' <span class="cfs-req">*</span>' : ''; ?></th>
							<td>
								<?php $this->render_integration_field( $name, $field, $value, $form ); ?>
								<?php if ( '' !== (string) $field['description'] ) : ?>
									<p class="description"><?php echo esc_html( (string) $field['description'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render one integration setting control.
	 *
	 * @param string   $name  Input name.
	 * @param array    $field Normalised field descriptor.
	 * @param mixed    $value Current value.
	 * @param CFS_Form $form  Form being edited (for field_map choices).
	 */
	private function render_integration_field( string $name, array $field, $value, CFS_Form $form ): void {
		switch ( $field['type'] ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" name="%s" value="1"%s> %s</label>',
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					esc_html( (string) $field['placeholder'] )
				);
				return;

			case 'textarea':
				printf(
					'<textarea name="%s" rows="4" class="large-text" placeholder="%s">%s</textarea>',
					esc_attr( $name ),
					esc_attr( (string) $field['placeholder'] ),
					esc_textarea( (string) $value )
				);
				return;

			case 'select':
				printf( '<select name="%s">', esc_attr( $name ) );
				foreach ( (array) $field['options'] as $option_value => $option_label ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( (string) $option_value ),
						selected( (string) $value, (string) $option_value, false ),
						esc_html( (string) $option_label )
					);
				}
				echo '</select>';
				return;

			case 'field_map':
				echo '<table class="cfs-field-map"><tbody>';
				foreach ( (array) $field['targets'] as $target => $target_label ) {
					$selected = is_array( $value ) ? (string) ( $value[ $target ] ?? '' ) : '';
					printf(
						'<tr><th>%s</th><td><select name="%s[%s]"><option value="">%s</option>',
						esc_html( (string) $target_label ),
						esc_attr( $name ),
						esc_attr( (string) $target ),
						esc_html__( '— не передавать —', 'contact-form-submissions' )
					);
					foreach ( $form->get_fields() as $field_name => $definition ) {
						printf(
							'<option value="%s"%s>%s (%s)</option>',
							esc_attr( (string) $field_name ),
							selected( $selected, (string) $field_name, false ),
							esc_html( (string) $definition['label'] ),
							esc_html( (string) $field_name )
						);
					}
					echo '</select></td></tr>';
				}
				echo '</tbody></table>';
				return;

			case 'password':
				printf(
					'<input type="password" name="%s" value="%s" class="regular-text" autocomplete="new-password">',
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				return;

			case 'number':
				printf(
					'<input type="number" name="%s" value="%s" class="small-text">',
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				return;

			case 'url':
			case 'text':
			default:
				printf(
					'<input type="%s" name="%s" value="%s" class="large-text" placeholder="%s">',
					'url' === $field['type'] ? 'url' : 'text',
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) $field['placeholder'] )
				);
		}
	}

	/**
	 * Tab: presentation and storage.
	 *
	 * @param CFS_Form $form   Form.
	 * @param bool     $active Whether the tab is active.
	 */
	private function render_tab_settings( CFS_Form $form, bool $active ): void {
		$settings = $form->get_settings();
		$this->open_panel( 'settings', $active );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Контейнер', 'contact-form-submissions' ); ?></th>
				<td>
					<label class="cfs-radio-row"><input type="radio" name="cfs_settings[container]" value="div" <?php checked( $settings['container'], 'div' ); ?>> <?php esc_html_e( 'Обычная форма на странице', 'contact-form-submissions' ); ?></label>
					<label class="cfs-radio-row"><input type="radio" name="cfs_settings[container]" value="dialog" <?php checked( $settings['container'], 'dialog' ); ?>> <?php esc_html_e( 'Модальное окно по кнопке', 'contact-form-submissions' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Кнопка модального окна', 'contact-form-submissions' ); ?></th>
				<td>
					<input type="text" name="cfs_settings[modal_button_text]" value="<?php echo esc_attr( (string) $settings['modal_button_text'] ); ?>" class="regular-text">
					<?php $this->render_icon_select( 'cfs_settings[modal_button_icon_before]', (string) $settings['modal_button_icon_before'], __( 'иконка слева', 'contact-form-submissions' ) ); ?>
					<?php $this->render_icon_select( 'cfs_settings[modal_button_icon_after]', (string) $settings['modal_button_icon_after'], __( 'иконка справа', 'contact-form-submissions' ) ); ?>
					<input type="text" name="cfs_settings[modal_button_class]" value="<?php echo esc_attr( (string) $settings['modal_button_class'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'CSS-классы', 'contact-form-submissions' ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cfs-css-class"><?php esc_html_e( 'CSS-класс формы', 'contact-form-submissions' ); ?></label></th>
				<td>
					<input type="text" id="cfs-css-class" name="cfs_settings[css_class]" value="<?php echo esc_attr( (string) $settings['css_class'] ); ?>" class="regular-text" placeholder="cfs-form--cols-2">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cfs-style-theme"><?php esc_html_e( 'Оформление полей', 'contact-form-submissions' ); ?></label></th>
				<td>
					<select id="cfs-style-theme" name="cfs_settings[style_theme]">
						<option value=""><?php esc_html_e( '— как в общих настройках —', 'contact-form-submissions' ); ?></option>
						<?php
						$themes = array(
							'default'      => __( 'Обычное', 'contact-form-submissions' ),
							'underline'    => __( 'Подчёркнутое', 'contact-form-submissions' ),
							'outlined-top' => __( 'Рамка с вырезом', 'contact-form-submissions' ),
							'filled'       => __( 'Заливка', 'contact-form-submissions' ),
							'contained'    => __( 'Карточка', 'contact-form-submissions' ),
							'left-label'   => __( 'Метка слева', 'contact-form-submissions' ),
						);
						foreach ( $themes as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['style_theme'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Кнопки мастера', 'contact-form-submissions' ); ?></th>
				<td>
					<input type="text" name="cfs_settings[back_text]" value="<?php echo esc_attr( (string) $settings['back_text'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Назад', 'contact-form-submissions' ); ?>">
					<input type="text" name="cfs_settings[next_text]" value="<?php echo esc_attr( (string) $settings['next_text'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Далее', 'contact-form-submissions' ); ?>">
					<p class="description"><?php esc_html_e( 'Используются, когда в шаблоне есть тег [step].', 'contact-form-submissions' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Хранение', 'contact-form-submissions' ); ?></th>
				<td>
					<label class="cfs-check-row">
						<input type="checkbox" name="cfs_settings[save_to_db]" value="1" <?php checked( ! empty( $settings['save_to_db'] ) ); ?>>
						<?php esc_html_e( 'Сохранять заявки в базу', 'contact-form-submissions' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Выключите, если заявки нужны только в письме или во внешней системе.', 'contact-form-submissions' ); ?></p>
				</td>
			</tr>
		</table>
		</div>
		<?php
	}

	/**
	 * Icon picker.
	 *
	 * @param string $name    Input name.
	 * @param string $current Selected icon key.
	 * @param string $label   Placeholder label.
	 */
	private function render_icon_select( string $name, string $current, string $label ): void {
		printf( '<select name="%s" aria-label="%s"><option value="">— %s —</option>', esc_attr( $name ), esc_attr( $label ), esc_html( $label ) );
		foreach ( CFS_Icons::keys() as $key ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				esc_html( $key )
			);
		}
		echo '</select>';
	}

	/**
	 * Field types offered by the tag bar.
	 *
	 * @return array<string, string>
	 */
	private function tag_buttons(): array {
		$buttons = array();

		foreach ( CFS_Field_Types::all() as $type => $descriptor ) {
			$buttons[ $type ] = (string) $descriptor['label'];
		}

		return $buttons;
	}

	/**
	 * Mail tags available for this form.
	 *
	 * @param CFS_Form $form Form.
	 * @return array<string, string> Tag => hint.
	 */
	private function mail_tags( CFS_Form $form ): array {
		$tags = array();

		foreach ( $form->get_fields() as $name => $field ) {
			if ( empty( $field['submits'] ) ) {
				continue;
			}
			$tags[ '{' . $name . '}' ] = (string) wp_strip_all_tags( (string) $field['label'] );
		}

		$tags['{all_fields}']    = __( 'таблица всех полей', 'contact-form-submissions' );
		$tags['{site_name}']     = __( 'название сайта', 'contact-form-submissions' );
		$tags['{site_url}']      = __( 'адрес сайта', 'contact-form-submissions' );
		$tags['{form_title}']    = __( 'название формы', 'contact-form-submissions' );
		$tags['{form_id}']       = __( 'ID формы', 'contact-form-submissions' );
		$tags['{submission_id}'] = __( 'номер заявки', 'contact-form-submissions' );
		$tags['{admin_url}']     = __( 'ссылка на заявку в админке', 'contact-form-submissions' );
		$tags['{date}']          = __( 'дата и время', 'contact-form-submissions' );
		$tags['{ip}']            = __( 'IP-адрес', 'contact-form-submissions' );
		$tags['{page_url}']      = __( 'страница отправки', 'contact-form-submissions' );
		$tags['{user_agent}']    = __( 'браузер', 'contact-form-submissions' );

		return $tags;
	}

	/**
	 * Data handed to the editor script.
	 *
	 * @return array
	 */
	public static function script_data(): array {
		$types = array();

		foreach ( CFS_Field_Types::all() as $type => $descriptor ) {
			$types[ $type ] = array(
				'label'    => (string) $descriptor['label'],
				'supports' => array_values( (array) $descriptor['supports'] ),
				'options'  => ! empty( $descriptor['needs_options'] ),
				'submits'  => ! empty( $descriptor['submits'] ),
			);
		}

		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cfs_editor' ),
			'types'   => $types,
			'icons'   => CFS_Icons::keys(),
			'i18n'    => array(
				'insert'        => __( 'Вставить', 'contact-form-submissions' ),
				'cancel'        => __( 'Отмена', 'contact-form-submissions' ),
				'name'          => __( 'Имя поля (латиницей)', 'contact-form-submissions' ),
				'label'         => __( 'Метка', 'contact-form-submissions' ),
				'placeholder'   => __( 'Подсказка в поле', 'contact-form-submissions' ),
				'required'      => __( 'Обязательное', 'contact-form-submissions' ),
				'options'       => __( 'Варианты: Метка:значение через запятую', 'contact-form-submissions' ),
				'icon'          => __( 'Иконка', 'contact-form-submissions' ),
				'help'          => __( 'Подсказка под полем', 'contact-form-submissions' ),
				'width'         => __( 'Ширина', 'contact-form-submissions' ),
				'text'          => __( 'Текст кнопки', 'contact-form-submissions' ),
				'newTag'        => __( 'Новый тег', 'contact-form-submissions' ),
				'copied'        => __( 'Скопировано', 'contact-form-submissions' ),
				'previewFailed' => __( 'Не удалось построить предпросмотр.', 'contact-form-submissions' ),
				'noFields'      => __( 'В шаблоне нет полей.', 'contact-form-submissions' ),
				'nameTaken'     => __( 'Такое имя уже занято в шаблоне.', 'contact-form-submissions' ),
				'nameInvalid'   => __( 'Имя: латинские буквы, цифры, дефис и подчёркивание.', 'contact-form-submissions' ),
			),
		);
	}
}
