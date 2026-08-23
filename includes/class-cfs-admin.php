<?php
/**
 * Admin panel — menus, list, detail view, settings.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Admin
 */
class CFS_Admin {

	/**
	 * DB instance.
	 *
	 * @var CFS_DB
	 */
	private $db;

	/**
	 * Hook suffixes returned by add_menu_page()/add_submenu_page().
	 *
	 * Collected at registration time rather than hard-coded: WordPress derives
	 * submenu hooks from sanitize_title() of the PARENT menu title, which here
	 * is Cyrillic and carries the "new submissions" badge — so the value is
	 * neither guessable nor stable.
	 *
	 * @var array<int, string>
	 */
	private $page_hooks = array();

	/**
	 * Forms list screen.
	 *
	 * @var CFS_Admin_Forms
	 */
	private $forms;

	/**
	 * Form editor screen.
	 *
	 * @var CFS_Admin_Form_Editor
	 */
	private $editor;

	/**
	 * Hook suffixes of the two form screens, which load the editor assets.
	 *
	 * @var array<int, string>
	 */
	private $form_hooks = array();

	/**
	 * Constructor.
	 *
	 * @param CFS_DB $db DB instance.
	 */
	public function __construct( CFS_DB $db ) {
		$this->db     = $db;
		$this->forms  = new CFS_Admin_Forms( $db );
		$this->editor = new CFS_Admin_Form_Editor();

		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// AJAX status update.
		add_action( 'wp_ajax_cfs_update_status', array( $this, 'ajax_update_status' ) );
	}

	/**
	 * Enqueue admin assets on plugin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'cfs-admin',
			CFS_PLUGIN_URL . 'assets/css/cfs-admin.css',
			array(),
			CFS_VERSION
		);

		wp_enqueue_script(
			'cfs-admin',
			CFS_PLUGIN_URL . 'assets/js/cfs-admin.js',
			array(),
			CFS_VERSION,
			true
		);

		wp_localize_script(
			'cfs-admin',
			'cfsAdminData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cfs_admin_action' ),
				'i18n'    => array(
					'networkError' => __( 'Ошибка сети.', 'contact-form-submissions' ),
				),
			)
		);

		if ( ! in_array( $hook, $this->form_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'cfs-editor',
			CFS_PLUGIN_URL . 'assets/css/cfs-editor.css',
			array( 'cfs-admin' ),
			CFS_VERSION
		);

		// The preview renders a real form, so it needs the real front-end CSS.
		wp_enqueue_style( 'cfs-form', CFS_PLUGIN_URL . 'assets/css/cfs-form.css', array(), CFS_VERSION );
		wp_enqueue_style( 'cfs-buttons', CFS_PLUGIN_URL . 'assets/css/cfs-buttons.css', array( 'cfs-form' ), CFS_VERSION );

		wp_enqueue_script(
			'cfs-form-editor',
			CFS_PLUGIN_URL . 'assets/js/cfs-form-editor.js',
			array(),
			CFS_VERSION,
			true
		);

		wp_localize_script( 'cfs-form-editor', 'cfsEditor', CFS_Admin_Form_Editor::script_data() );
	}

	/**
	 * Register admin menus.
	 */
	public function register_menus(): void {
		$new_count = $this->db->get_new_count();
		$badge     = $new_count > 0
			? ' <span class="awaiting-mod">' . number_format_i18n( $new_count ) . '</span>'
			: '';

		$this->page_hooks[] = add_menu_page(
			__( 'Заявки', 'contact-form-submissions' ),
			__( 'Заявки', 'contact-form-submissions' ) . $badge,
			'manage_options',
			'cfs-submissions',
			array( $this, 'page_submissions' ),
			'dashicons-email-alt',
			30
		);

		$this->page_hooks[] = add_submenu_page(
			'cfs-submissions',
			__( 'Все заявки', 'contact-form-submissions' ),
			__( 'Все заявки', 'contact-form-submissions' ),
			'manage_options',
			'cfs-submissions',
			array( $this, 'page_submissions' )
		);

		$forms_hook = add_submenu_page(
			'cfs-submissions',
			__( 'Формы', 'contact-form-submissions' ),
			__( 'Формы', 'contact-form-submissions' ),
			CFS_Post_Type::capability(),
			'cfs-forms',
			array( $this->forms, 'render' )
		);

		/*
		 * The editor has no menu entry — reached only from the forms list, and
		 * a second "Формы" item would just be noise. Registering it with a
		 * null parent is the pattern WordPress itself expects for this: the
		 * page still passes user_can_access_admin_page().
		 *
		 * Registering it as a normal submenu and then hiding the item with
		 * remove_submenu_page() looks equivalent but is not: that call only
		 * edits the $submenu global used to draw the sidebar. WordPress's own
		 * access check (wp-admin/includes/plugin.php) determines the page's
		 * parent by searching that SAME $submenu array for the page's slug —
		 * once the entry is gone, the search finds nothing, the parent
		 * resolves to '', the hookname computed from it no longer matches the
		 * one recorded at registration, and every visitor is turned away with
		 * "Sorry, you are not allowed to access this page." regardless of
		 * their capabilities.
		 */
		$editor_hook = add_submenu_page(
			null,
			__( 'Редактирование формы', 'contact-form-submissions' ),
			__( 'Редактирование формы', 'contact-form-submissions' ),
			CFS_Post_Type::capability(),
			'cfs-form',
			array( $this->editor, 'render' )
		);

		$this->form_hooks   = array_filter( array( $forms_hook, $editor_hook ) );
		$this->page_hooks   = array_merge( $this->page_hooks, $this->form_hooks );

		$this->page_hooks[] = add_submenu_page(
			'cfs-submissions',
			__( 'Настройки', 'contact-form-submissions' ),
			__( 'Настройки', 'contact-form-submissions' ),
			'manage_options',
			'cfs-settings',
			array( $this, 'page_settings' )
		);

		$this->page_hooks[] = add_submenu_page(
			'cfs-submissions',
			__( 'Помощь', 'contact-form-submissions' ),
			__( 'Помощь', 'contact-form-submissions' ),
			'manage_options',
			'cfs-help',
			array( $this, 'page_help' )
		);

		$this->page_hooks = array_filter( $this->page_hooks );
	}

	/**
	 * Register settings fields.
	 */
	public function register_settings(): void {
		register_setting( 'cfs_settings_group', 'cfs_extra_emails', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'cfs_settings_group', 'cfs_email_subject', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'cfs_settings_group', 'cfs_banned_words', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( 'cfs_settings_group', 'cfs_save_ip', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'cfs_settings_group', 'cfs_save_ua', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'cfs_settings_group', 'cfs_style_theme', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'cfs_settings_group', 'cfs_disable_styles', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'cfs_settings_group', 'cfs_disable_btn_styles', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'cfs_settings_group', 'cfs_debug_mode', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting(
			'cfs_settings_group',
			'cfs_agreement_text',
			array( 'sanitize_callback' => array( $this, 'sanitize_agreement_text' ) )
		);
		register_setting(
			'cfs_settings_group',
			'cfs_max_comment_length',
			array( 'sanitize_callback' => array( $this, 'sanitize_max_comment_length' ) )
		);
	}

	/**
	 * Sanitize the maximum comment length.
	 *
	 * @param mixed $value Raw option value.
	 * @return int
	 */
	public function sanitize_max_comment_length( $value ): int {
		$value = (int) $value;
		return $value > 0 ? min( $value, 65000 ) : 1000;
	}

	/**
	 * Handle bulk actions on submissions list.
	 */
	public function handle_bulk_actions(): void {
		if ( ! isset( $_POST['cfs_bulk_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'cfs_bulk_action' );

		$action = sanitize_key( $_POST['cfs_bulk_action'] );
		$ids    = isset( $_POST['submission_ids'] ) ? array_map( 'intval', (array) $_POST['submission_ids'] ) : array();

		if ( empty( $ids ) ) {
			return;
		}

		switch ( $action ) {
			case 'mark_processed':
				$this->db->bulk_update_status( $ids, 'processed' );
				break;
			case 'mark_spam':
				$this->db->bulk_update_status( $ids, 'spam' );
				break;
			case 'delete':
				$this->db->bulk_delete( $ids );
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=cfs-submissions&cfs_done=1' ) );
		exit;
	}

	/**
	 * Handle CSV export request.
	 */
	public function handle_export(): void {
		if (
			! isset( $_GET['cfs_export'] ) ||
			$_GET['cfs_export'] !== '1' ||
			! current_user_can( 'manage_options' )
		) {
			return;
		}

		check_admin_referer( 'cfs_export' );

		$filters = array();
		if ( ! empty( $_GET['status'] ) ) {
			$filters['status'] = sanitize_key( $_GET['status'] );
		}
		if ( ! empty( $_GET['form_id'] ) ) {
			$filters['form_id'] = sanitize_key( $_GET['form_id'] );
		}

		$exporter = new CFS_Exporter( $this->db );
		$exporter->export_csv( $filters );
	}

	/**
	 * AJAX handler: update submission status.
	 */
	public function ajax_update_status(): void {
		check_ajax_referer( 'cfs_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Нет прав.', 'contact-form-submissions' ) ) );
			return;
		}

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';

		if ( ! $id || ! $status ) {
			wp_send_json_error( array( 'message' => __( 'Неверные параметры.', 'contact-form-submissions' ) ) );
			return;
		}

		$result = $this->db->update_status( $id, $status );
		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Статус обновлён.', 'contact-form-submissions' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Ошибка обновления.', 'contact-form-submissions' ) ) );
		}
	}

	/**
	 * Render the submissions list/detail page.
	 */
	public function page_submissions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

		if ( 'view' === $action && isset( $_GET['id'] ) ) {
			$this->render_detail_page( (int) $_GET['id'] );
			return;
		}

		if ( 'delete' === $action && isset( $_GET['id'] ) ) {
			check_admin_referer( 'cfs_delete_' . (int) $_GET['id'] );
			$this->db->delete_submission( (int) $_GET['id'] );
			wp_safe_redirect( admin_url( 'admin.php?page=cfs-submissions&cfs_done=1' ) );
			exit;
		}

		$this->render_list_page();
	}

	/**
	 * Render submissions list page.
	 */
	private function render_list_page(): void {
		$status_filter  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$form_id_filter = isset( $_GET['form_id'] ) ? sanitize_key( $_GET['form_id'] ) : '';
		$orderby        = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'submitted_at';
		$order          = isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ? 'ASC' : 'DESC';
		$page_num       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$args = array(
			'status'   => $status_filter,
			'form_id'  => $form_id_filter,
			'orderby'  => $orderby,
			'order'    => $order,
			'page'     => $page_num,
			'per_page' => 20,
		);

		$submissions = $this->db->get_submissions( $args );

		// One GROUP BY replaces the four separate COUNT(*) queries this used to run.
		$counts          = $this->db->count_all_by_status( $form_id_filter );
		$count_all       = $counts['all'];
		$count_new       = $counts['new'];
		$count_processed = $counts['processed'];
		$count_spam      = $counts['spam'];

		$total = '' === $status_filter ? $count_all : ( $counts[ $status_filter ] ?? 0 );

		$total_pages = (int) ceil( $total / 20 );
		$form_ids    = $this->db->get_form_ids();

		/*
		 * form_id is a post ID for 3.x submissions and a hashed slug for 2.x
		 * ones, so the filter and the column both resolve it to a title where
		 * they can and fall back to the raw value where they cannot.
		 */
		$form_titles = array();
		foreach ( $form_ids as $known_id ) {
			$known_form = ctype_digit( (string) $known_id ) ? CFS_Form::load( (int) $known_id ) : null;

			$form_titles[ (string) $known_id ] = $known_form
				? $known_form->get_title()
				: sprintf(
					/* translators: %s: legacy form identifier */
					__( '%s (форма 2.x)', 'contact-form-submissions' ),
					(string) $known_id
				);
		}

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'       => 'cfs-submissions',
					'cfs_export' => '1',
					'status'     => $status_filter,
					'form_id'    => $form_id_filter,
				),
				admin_url( 'admin.php' )
			),
			'cfs_export'
		);

		$base_url = admin_url( 'admin.php?page=cfs-submissions' );
		?>
		<div class="wrap cfs-admin-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Заявки', 'contact-form-submissions' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Экспорт CSV', 'contact-form-submissions' ); ?></a>

			<?php if ( isset( $_GET['cfs_done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Действие выполнено.', 'contact-form-submissions' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_list_notices(); ?>

			<!-- Stats -->
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $base_url ); ?>" <?php echo '' === $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Все', 'contact-form-submissions' ); ?> <span class="count">(<?php echo (int) $count_all; ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( $base_url . '&status=new' ); ?>" <?php echo 'new' === $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Новые', 'contact-form-submissions' ); ?> <span class="count">(<?php echo (int) $count_new; ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( $base_url . '&status=processed' ); ?>" <?php echo 'processed' === $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Обработанные', 'contact-form-submissions' ); ?> <span class="count">(<?php echo (int) $count_processed; ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( $base_url . '&status=spam' ); ?>" <?php echo 'spam' === $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Спам', 'contact-form-submissions' ); ?> <span class="count">(<?php echo (int) $count_spam; ?>)</span></a></li>
			</ul>

			<!-- Filters -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="cfs-submissions">
				<?php if ( ! empty( $status_filter ) ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
				<?php endif; ?>
				<select name="form_id">
					<option value=""><?php esc_html_e( '— Все формы —', 'contact-form-submissions' ); ?></option>
					<?php foreach ( $form_ids as $fid ) : ?>
						<option value="<?php echo esc_attr( $fid ); ?>" <?php selected( $form_id_filter, $fid ); ?>><?php echo esc_html( $form_titles[ (string) $fid ] ?? $fid ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Фильтр', 'contact-form-submissions' ), 'secondary', '', false ); ?>
			</form>

			<!-- Bulk actions form -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=cfs-submissions' ) ); ?>">
				<?php wp_nonce_field( 'cfs_bulk_action' ); ?>
				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<select name="cfs_bulk_action">
							<option value=""><?php esc_html_e( 'Массовые действия', 'contact-form-submissions' ); ?></option>
							<option value="mark_processed"><?php esc_html_e( 'Отметить обработанными', 'contact-form-submissions' ); ?></option>
							<option value="mark_spam"><?php esc_html_e( 'Пометить спамом', 'contact-form-submissions' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Удалить', 'contact-form-submissions' ); ?></option>
						</select>
						<?php submit_button( __( 'Применить', 'contact-form-submissions' ), 'action', '', false ); ?>
					</div>
					<div class="tablenav-pages">
						<?php if ( $total_pages > 1 ) : ?>
							<?php echo $this->pagination_html( $page_num, $total_pages, $base_url, $status_filter, $form_id_filter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox" id="cfs-select-all"></td>
							<th><?php esc_html_e( 'ID', 'contact-form-submissions' ); ?></th>
							<th><?php esc_html_e( 'ФИО', 'contact-form-submissions' ); ?></th>
							<th><?php esc_html_e( 'Телефон', 'contact-form-submissions' ); ?></th>
							<th><?php esc_html_e( 'Email', 'contact-form-submissions' ); ?></th>
							<th><?php esc_html_e( 'Форма', 'contact-form-submissions' ); ?></th>
							<th><?php esc_html_e( 'Дата', 'contact-form-submissions' ); ?></th>
							<th><?php esc_html_e( 'Статус', 'contact-form-submissions' ); ?></th>
							<th><?php esc_html_e( 'Действия', 'contact-form-submissions' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $submissions ) ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'Заявок не найдено.', 'contact-form-submissions' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $submissions as $row ) : ?>
							<?php
							$item      = CFS_Submission::from_row( $row );
							$full_name = $item->get_display_name();
							$view_url   = admin_url( 'admin.php?page=cfs-submissions&action=view&id=' . (int) $row->id );
							$delete_url = wp_nonce_url(
								admin_url( 'admin.php?page=cfs-submissions&action=delete&id=' . (int) $row->id ),
								'cfs_delete_' . (int) $row->id
							);
							?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" name="submission_ids[]" value="<?php echo (int) $row->id; ?>">
								</th>
								<td><?php echo (int) $row->id; ?></td>
								<td><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $full_name ); ?></a></td>
								<td><?php echo esc_html( '' !== (string) $row->phone ? $row->phone : '—' ); ?></td>
								<td><?php echo esc_html( '' !== (string) $row->email ? $row->email : '—' ); ?></td>
								<td><?php echo esc_html( $form_titles[ (string) $row->form_id ] ?? (string) $row->form_id ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->submitted_at ) ); ?></td>
								<td>
									<span class="cfs-status cfs-status--<?php echo esc_attr( $row->status ); ?>">
										<?php echo esc_html( $this->status_label( $row->status ) ); ?>
									</span>
								</td>
								<td>
									<a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Просмотр', 'contact-form-submissions' ); ?></a>
									&nbsp;|&nbsp;
									<a href="<?php echo esc_url( $delete_url ); ?>" class="cfs-delete-link" onclick="return confirm('<?php esc_attr_e( 'Удалить эту заявку?', 'contact-form-submissions' ); ?>')"><?php esc_html_e( 'Удалить', 'contact-form-submissions' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>

				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php if ( $total_pages > 1 ) : ?>
							<?php echo $this->pagination_html( $page_num, $total_pages, $base_url, $status_filter, $form_id_filter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render detail page for a single submission.
	 *
	 * @param int $id Submission ID.
	 */
	private function render_detail_page( int $id ): void {
		$submission = $this->db->get_submission( $id );
		if ( ! $submission ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Заявка не найдена.', 'contact-form-submissions' ) . '</p></div>';
			return;
		}

		/*
		 * Everything the card shows comes from the submission itself, through
		 * CFS_Submission: a row written by 2.x and one written by 3.x arrive
		 * here in the same shape, and neither depends on the form still
		 * existing — or on a cache entry that has long expired.
		 */
		$item           = CFS_Submission::from_row( $submission );
		$contact_fields = $item->get_contact_fields();
		$data_fields    = $item->get_data_fields();
		$hidden_fields  = $item->get_hidden_fields();

		$list_url   = admin_url( 'admin.php?page=cfs-submissions' );
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=cfs-submissions&action=delete&id=' . $id ),
			'cfs_delete_' . $id
		);

		$form_post_id = $item->get_form_post_id();
		$form_edit_url = $form_post_id > 0 && class_exists( 'CFS_Admin_Forms' )
			? CFS_Admin_Forms::edit_url( $form_post_id )
			: '';
		?>
		<div class="wrap cfs-admin-wrap">
			<h1>
				<?php
				/* translators: %d: submission ID */
				printf( esc_html__( 'Заявка #%d', 'contact-form-submissions' ), $id );
				?>
			</h1>
			<p>
				<a href="<?php echo esc_url( $list_url ); ?>" class="button">&larr; <?php esc_html_e( 'Назад к списку', 'contact-form-submissions' ); ?></a>
				&nbsp;
				<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Удалить эту заявку?', 'contact-form-submissions' ); ?>')"><?php esc_html_e( 'Удалить', 'contact-form-submissions' ); ?></a>
			</p>

			<div class="cfs-detail-body">

				<!-- ═══ Main column ═══ -->
				<div class="cfs-detail-main">

						<!-- ── Section: Applicant info ── -->
						<?php if ( ! empty( $contact_fields ) ) : ?>
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Заявитель', 'contact-form-submissions' ); ?></span></h2>
							<div class="inside">
								<table class="form-table">
									<?php foreach ( $contact_fields as $field ) : ?>
									<tr>
										<th><?php echo esc_html( $field['label'] ); ?></th>
										<td>
											<?php if ( '' !== (string) $field['display'] ) : ?>
												<?php if ( 'phone' === $field['type'] ) : ?>
													<a href="tel:<?php echo esc_attr( (string) $field['value'] ); ?>"><?php echo esc_html( (string) $field['display'] ); ?></a>
												<?php elseif ( 'email' === $field['type'] ) : ?>
													<a href="mailto:<?php echo esc_attr( (string) $field['display'] ); ?>"><?php echo esc_html( (string) $field['display'] ); ?></a>
												<?php else : ?>
													<?php echo esc_html( (string) $field['display'] ); ?>
												<?php endif; ?>
											<?php else : ?>
												—
											<?php endif; ?>
										</td>
									</tr>
									<?php endforeach; ?>
								</table>
							</div>
						</div>
						<?php endif; ?>

						<!-- ── Section: Form fields ── -->
						<?php if ( ! empty( $data_fields ) ) : ?>
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Данные формы', 'contact-form-submissions' ); ?></span></h2>
							<div class="inside">
								<table class="form-table">
									<?php foreach ( $data_fields as $field ) : ?>
									<tr>
										<th><?php echo esc_html( $field['label'] ); ?></th>
										<td><?php echo ( '' !== (string) $field['display'] ) ? nl2br( esc_html( (string) $field['display'] ) ) : '—'; ?></td>
									</tr>
									<?php endforeach; ?>
								</table>
							</div>
						</div>
						<?php endif; ?>

						<!-- ── Section: Hidden fields (UTM and friends) ── -->
						<?php if ( ! empty( $hidden_fields ) ) : ?>
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Технические данные', 'contact-form-submissions' ); ?></span></h2>
							<div class="inside">
								<table class="form-table">
									<?php foreach ( $hidden_fields as $field ) : ?>
									<tr>
										<th><code><?php echo esc_html( $field['name'] ); ?></code></th>
										<td><?php echo esc_html( (string) $field['display'] ); ?></td>
									</tr>
									<?php endforeach; ?>
								</table>
							</div>
						</div>
						<?php endif; ?>

						<?php $this->render_panels( $submission, 'main' ); ?>

				</div>

				<!-- ═══ Sidebar ═══ -->
				<div class="cfs-detail-sidebar">

						<!-- ── Section: Status & meta ── -->
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Информация о заявке', 'contact-form-submissions' ); ?></span></h2>
							<div class="inside">
								<table class="form-table">
									<tr>
										<th><?php esc_html_e( 'ID', 'contact-form-submissions' ); ?></th>
										<td><strong><?php echo (int) $submission->id; ?></strong></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Форма', 'contact-form-submissions' ); ?></th>
										<td>
											<?php if ( '' !== $form_edit_url ) : ?>
												<a href="<?php echo esc_url( $form_edit_url ); ?>"><?php echo esc_html( $item->get_form_title() ); ?></a>
											<?php else : ?>
												<code><?php echo esc_html( $item->get_form_id() ); ?></code>
											<?php endif; ?>
											<?php if ( $item->is_stale() ) : ?>
												<p class="description" style="margin:4px 0 0;">
													<?php esc_html_e( 'Отправлено со страницы, где форма была старой версии.', 'contact-form-submissions' ); ?>
												</p>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Статус', 'contact-form-submissions' ); ?></th>
										<td>
											<select id="cfs-status-select" data-id="<?php echo (int) $submission->id; ?>" style="width:100%;">
												<option value="new" <?php selected( $submission->status, 'new' ); ?>><?php esc_html_e( 'Новая', 'contact-form-submissions' ); ?></option>
												<option value="processed" <?php selected( $submission->status, 'processed' ); ?>><?php esc_html_e( 'Обработана', 'contact-form-submissions' ); ?></option>
												<option value="spam" <?php selected( $submission->status, 'spam' ); ?>><?php esc_html_e( 'Спам', 'contact-form-submissions' ); ?></option>
											</select>
											<p style="margin:6px 0 0;">
												<button class="button button-primary" id="cfs-save-status"><?php esc_html_e( 'Сохранить', 'contact-form-submissions' ); ?></button>
												<span id="cfs-status-msg" style="margin-left:6px;"></span>
											</p>
										</td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Дата', 'contact-form-submissions' ); ?></th>
										<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submission->submitted_at ) ); ?></td>
									</tr>
									<?php if ( ! empty( $submission->processed_at ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'Обработана', 'contact-form-submissions' ); ?></th>
										<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submission->processed_at ) ); ?></td>
									</tr>
									<?php endif; ?>
									<?php if ( ! empty( $submission->ip_address ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'IP', 'contact-form-submissions' ); ?></th>
										<td><?php echo esc_html( $submission->ip_address ); ?></td>
									</tr>
									<?php endif; ?>
									<?php if ( ! empty( $submission->page_url ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'Страница', 'contact-form-submissions' ); ?></th>
										<td style="word-break:break-all;"><a href="<?php echo esc_url( $submission->page_url ); ?>" target="_blank"><?php echo esc_html( $submission->page_url ); ?></a></td>
									</tr>
									<?php endif; ?>
									<?php if ( ! empty( $submission->user_agent ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'User Agent', 'contact-form-submissions' ); ?></th>
										<td><small style="word-break:break-all;"><?php echo esc_html( $submission->user_agent ); ?></small></td>
									</tr>
									<?php endif; ?>
								</table>
							</div>
						</div>

						<?php $this->render_panels( $submission, 'side' ); ?>

				</div>

			</div>

		</div>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════════════════
	   EXTENSION API — panels and notices contributed by add-on plugins
	   ═══════════════════════════════════════════════════════════════════════ */

	/**
	 * Render add-on panels for one context of the submission detail screen.
	 *
	 * Add-ons describe a panel as data and the core renders and escapes it, so
	 * an integration needs no markup, no CSS and no JavaScript of its own — and
	 * a site with no add-ons pays nothing, because the filter returns an empty
	 * array and nothing is emitted.
	 *
	 * Panel shape:
	 *
	 *   array(
	 *     'id'       => 'bitrix24',                  // required, unique slug
	 *     'title'    => 'Битрикс24',                 // required, panel heading
	 *     'context'  => 'side',                      // 'side' (default) or 'main'
	 *     'priority' => 10,                          // lower renders first
	 *     'rows'     => array(
	 *        array(
	 *          'label' => 'Статус',
	 *          'value' => 'Передана',                // plain text, escaped by core
	 *          'url'   => 'https://…',               // optional — renders a link
	 *          'color' => '#00a32a',                 // optional
	 *          'small' => true,                      // optional — smaller type
	 *        ),
	 *     ),
	 *     'actions'  => array(
	 *        array(
	 *          'action'     => 'my_addon_retry',     // wp_ajax_{action} handler
	 *          'label'      => 'Отправить повторно',
	 *          'busy_label' => 'Отправляем…',        // optional
	 *          'reload'     => true,                 // optional — reload on success
	 *          'payload'    => array( 'foo' => 1 ),  // optional extra POST fields
	 *        ),
	 *     ),
	 *   )
	 *
	 * Action handlers receive the submission ID as POST "id" and must verify the
	 * shared nonce with check_ajax_referer( 'cfs_admin_action', 'nonce' ) plus
	 * their own capability check.
	 *
	 * @param object $submission Raw DB row.
	 * @param string $context    'side' or 'main'.
	 */
	private function render_panels( $submission, string $context ): void {
		/**
		 * Filter the add-on panels shown on the submission detail screen.
		 *
		 * @param array  $panels     List of panel definitions.
		 * @param object $submission Raw submission row.
		 */
		$panels = (array) apply_filters( 'cfs_submission_panels', array(), $submission );

		if ( empty( $panels ) ) {
			return;
		}

		// Stable ordering: by priority, then by declaration order.
		$ordered = array();
		foreach ( array_values( $panels ) as $index => $panel ) {
			if ( ! is_array( $panel ) || empty( $panel['title'] ) ) {
				continue;
			}
			if ( ( $panel['context'] ?? 'side' ) !== $context ) {
				continue;
			}
			$ordered[] = array(
				'priority' => (int) ( $panel['priority'] ?? 10 ),
				'index'    => $index,
				'panel'    => $panel,
			);
		}

		if ( empty( $ordered ) ) {
			return;
		}

		usort(
			$ordered,
			static function ( array $a, array $b ): int {
				return ( $a['priority'] <=> $b['priority'] ) ?: ( $a['index'] <=> $b['index'] );
			}
		);

		foreach ( $ordered as $entry ) {
			$this->render_single_panel( $entry['panel'], $submission );
		}
	}

	/**
	 * Render one add-on panel.
	 *
	 * @param array  $panel      Panel definition.
	 * @param object $submission Raw DB row.
	 */
	private function render_single_panel( array $panel, $submission ): void {
		$rows    = isset( $panel['rows'] ) && is_array( $panel['rows'] ) ? $panel['rows'] : array();
		$actions = isset( $panel['actions'] ) && is_array( $panel['actions'] ) ? $panel['actions'] : array();
		$panel_id = isset( $panel['id'] ) ? sanitize_html_class( (string) $panel['id'] ) : '';
		?>
		<div class="postbox<?php echo '' !== $panel_id ? ' cfs-panel--' . esc_attr( $panel_id ) : ''; ?>">
			<h2 class="hndle"><span><?php echo esc_html( (string) $panel['title'] ); ?></span></h2>
			<div class="inside">
				<?php if ( ! empty( $rows ) ) : ?>
					<table class="form-table">
						<?php
						foreach ( $rows as $row ) :
							if ( ! is_array( $row ) ) {
								continue;
							}
							$value = (string) ( $row['value'] ?? '' );
							$url   = (string) ( $row['url'] ?? '' );
							$color = (string) ( $row['color'] ?? '' );
							$small = ! empty( $row['small'] );
							$style = '' !== $color ? 'color:' . $color . ';' : '';
							if ( $small ) {
								$style .= 'word-break:break-word;';
							}
							?>
							<tr>
								<th><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></th>
								<td>
									<?php
									$tag_open  = $small ? '<small style="' . esc_attr( $style ) . '">' : ( '' !== $style ? '<strong style="' . esc_attr( $style ) . '">' : '' );
									$tag_close = $small ? '</small>' : ( '' !== $style ? '</strong>' : '' );

									echo wp_kses_post( $tag_open );
									if ( '' === $value ) {
										echo '—';
									} elseif ( '' !== $url ) {
										printf(
											'<a href="%s" target="_blank" rel="noopener">%s</a>',
											esc_url( $url ),
											esc_html( $value )
										);
									} else {
										echo esc_html( $value );
									}
									echo wp_kses_post( $tag_close );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>

				<?php if ( ! empty( $actions ) ) : ?>
					<p style="margin:6px 0 0;">
						<?php
						foreach ( $actions as $action ) :
							if ( ! is_array( $action ) || empty( $action['action'] ) || empty( $action['label'] ) ) {
								continue;
							}
							$payload = ! empty( $action['payload'] ) && is_array( $action['payload'] )
								? (string) wp_json_encode( $action['payload'] )
								: '';
							?>
							<button
								type="button"
								class="button cfs-panel-action"
								data-action="<?php echo esc_attr( sanitize_key( (string) $action['action'] ) ); ?>"
								data-id="<?php echo (int) $submission->id; ?>"
								data-busy-label="<?php echo esc_attr( (string) ( $action['busy_label'] ?? '…' ) ); ?>"
								data-reload="<?php echo ! empty( $action['reload'] ) ? '1' : '0'; ?>"
								<?php if ( '' !== $payload ) : ?>
									data-payload="<?php echo esc_attr( $payload ); ?>"
								<?php endif; ?>
							><?php echo esc_html( (string) $action['label'] ); ?></button>
						<?php endforeach; ?>
						<span class="cfs-panel-action-msg" style="margin-left:6px;"></span>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render admin notices contributed by add-ons on the submissions list.
	 *
	 * Notice shape: array( 'type' => 'warning'|'error'|'info'|'success',
	 *                      'message' => 'text', 'url' => '…', 'link_text' => '…' )
	 */
	private function render_list_notices(): void {
		/**
		 * Filter the notices shown above the submissions list.
		 *
		 * @param array $notices List of notice definitions.
		 */
		$notices = (array) apply_filters( 'cfs_submission_notices', array() );

		foreach ( $notices as $notice ) {
			if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
				continue;
			}
			$type = (string) ( $notice['type'] ?? 'info' );
			if ( ! in_array( $type, array( 'info', 'warning', 'error', 'success' ), true ) ) {
				$type = 'info';
			}
			?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?>">
				<p>
					<?php echo esc_html( (string) $notice['message'] ); ?>
					<?php if ( ! empty( $notice['url'] ) ) : ?>
						<a href="<?php echo esc_url( (string) $notice['url'] ); ?>">
							<?php echo esc_html( (string) ( $notice['link_text'] ?? __( 'Подробнее', 'contact-form-submissions' ) ) ); ?>
						</a>
					<?php endif; ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Render settings page.
	 */
	public function page_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap cfs-admin-wrap">
			<h1><?php esc_html_e( 'Настройки заявок', 'contact-form-submissions' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'cfs_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="cfs_extra_emails"><?php esc_html_e( 'Доп. получатели email', 'contact-form-submissions' ); ?></label></th>
						<td>
							<input type="text" id="cfs_extra_emails" name="cfs_extra_emails" value="<?php echo esc_attr( get_option( 'cfs_extra_emails', '' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Email через запятую.', 'contact-form-submissions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cfs_email_subject"><?php esc_html_e( 'Тема письма', 'contact-form-submissions' ); ?></label></th>
						<td>
							<input type="text" id="cfs_email_subject" name="cfs_email_subject" value="<?php echo esc_attr( get_option( 'cfs_email_subject', '' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Доступны {site_name} и {form_id}.', 'contact-form-submissions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cfs_banned_words"><?php esc_html_e( 'Запрещённые слова', 'contact-form-submissions' ); ?></label></th>
						<td>
							<textarea id="cfs_banned_words" name="cfs_banned_words" rows="5" class="large-text"><?php echo esc_textarea( get_option( 'cfs_banned_words', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'По одному слову на строку. Проверяются все текстовые поля заявки.', 'contact-form-submissions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cfs_max_comment_length"><?php esc_html_e( 'Максимальная длина комментария', 'contact-form-submissions' ); ?></label></th>
						<td>
							<input type="number" min="1" max="65000" step="1" id="cfs_max_comment_length" name="cfs_max_comment_length" value="<?php echo esc_attr( (string) (int) get_option( 'cfs_max_comment_length', 1000 ) ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Символов в поле «Комментарий». По умолчанию 1000.', 'contact-form-submissions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cfs_agreement_text"><?php esc_html_e( 'Текст поля согласия', 'contact-form-submissions' ); ?></label></th>
						<td>
							<textarea id="cfs_agreement_text" name="cfs_agreement_text" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'cfs_agreement_text', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Текст для поля agreement. Поддерживаются HTML-ссылки: <a href="...">текст</a>.', 'contact-form-submissions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Сохранять IP', 'contact-form-submissions' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="cfs_save_ip" value="yes" <?php checked( get_option( 'cfs_save_ip', 'yes' ), 'yes' ); ?>>
								<?php esc_html_e( 'Да', 'contact-form-submissions' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Сохранять User Agent', 'contact-form-submissions' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="cfs_save_ua" value="yes" <?php checked( get_option( 'cfs_save_ua', 'yes' ), 'yes' ); ?>>
								<?php esc_html_e( 'Да', 'contact-form-submissions' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Стиль полей', 'contact-form-submissions' ); ?></th>
						<td>
							<?php $current_style = get_option( 'cfs_style_theme', 'default' ); ?>
							<fieldset>
								<label style="display:block;margin-bottom:0.35rem;">
									<input type="radio" name="cfs_style_theme" value="default" <?php checked( $current_style, 'default' ); ?>>
									<?php esc_html_e( 'Outlined (адаптивная метка)', 'contact-form-submissions' ); ?>
								</label>
								<label style="display:block;margin-bottom:0.35rem;">
									<input type="radio" name="cfs_style_theme" value="underline" <?php checked( $current_style, 'underline' ); ?>>
									<?php esc_html_e( 'Underline (подчёркивание)', 'contact-form-submissions' ); ?>
								</label>
								<label style="display:block;margin-bottom:0.35rem;">
									<input type="radio" name="cfs_style_theme" value="outlined-top" <?php checked( $current_style, 'outlined-top' ); ?>>
									<?php esc_html_e( 'Outlined (метка сверху)', 'contact-form-submissions' ); ?>
								</label>
								<label style="display:block;margin-bottom:0.35rem;">
									<input type="radio" name="cfs_style_theme" value="filled" <?php checked( $current_style, 'filled' ); ?>>
									<?php esc_html_e( 'Filled (заливка)', 'contact-form-submissions' ); ?>
								</label>
								<label style="display:block;margin-bottom:0.35rem;">
									<input type="radio" name="cfs_style_theme" value="contained" <?php checked( $current_style, 'contained' ); ?>>
									<?php esc_html_e( 'Outlined (метка внутри)', 'contact-form-submissions' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="cfs_style_theme" value="left-label" <?php checked( $current_style, 'left-label' ); ?>>
									<?php esc_html_e( 'Метка слева', 'contact-form-submissions' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Отключить стили', 'contact-form-submissions' ); ?></th>
						<td>
							<label style="display: block; margin-bottom: 0.4rem;">
								<input type="checkbox" name="cfs_disable_styles" value="yes" <?php checked( get_option( 'cfs_disable_styles', 'no' ), 'yes' ); ?>>
								<?php esc_html_e( 'Отключить все стили плагина', 'contact-form-submissions' ); ?>
							</label>
							<label style="display: block;">
								<input type="checkbox" name="cfs_disable_btn_styles" value="yes" <?php checked( get_option( 'cfs_disable_btn_styles', 'no' ), 'yes' ); ?>>
								<?php esc_html_e( 'Отключить стили кнопок (отправить / модальная)', 'contact-form-submissions' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Режим отладки', 'contact-form-submissions' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="cfs_debug_mode" value="yes" <?php checked( get_option( 'cfs_debug_mode', 'no' ), 'yes' ); ?>>
								<?php esc_html_e( 'Включить подробное логирование в консоль браузера', 'contact-form-submissions' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Выводит в консоль (F12) все этапы: валидацию, AJAX-запрос, ответ сервера, привязку ошибок к полям. Отключите на проде.', 'contact-form-submissions' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render help page.
	 */
	public function page_help(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$types = CFS_Field_Types::all();
		?>
		<div class="wrap cfs-help">
			<h1><?php esc_html_e( 'Помощь', 'contact-form-submissions' ); ?></h1>

			<h2><?php esc_html_e( 'Как это устроено', 'contact-form-submissions' ); ?></h2>
			<p>
				<?php esc_html_e( 'Форма создаётся в разделе «Заявки → Формы». Её содержимое — обычный текст: всё, что в квадратных скобках, становится полем ввода, всё остальное выводится как HTML.', 'contact-form-submissions' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Готовую форму выводит шорткод с её номером:', 'contact-form-submissions' ); ?>
				<code>[contact_form id="12"]</code> <?php esc_html_e( 'или', 'contact-form-submissions' ); ?> <code>[contact_form slug="callback"]</code>.
			</p>

			<h2><?php esc_html_e( 'Синтаксис тега', 'contact-form-submissions' ); ?></h2>
			<pre class="cfs-help-code">[тип* имя атрибут="значение"]</pre>
			<ul class="cfs-help-list">
				<li><code>тип</code> — <?php esc_html_e( 'какое это поле; список ниже.', 'contact-form-submissions' ); ?></li>
				<li><code>*</code> — <?php esc_html_e( 'поле обязательное. Без звёздочки — необязательное.', 'contact-form-submissions' ); ?></li>
				<li><code>имя</code> — <?php esc_html_e( 'латиницей; под этим именем значение попадёт в заявку, письмо и CRM. Если не указать — плагин подставит имя по типу.', 'contact-form-submissions' ); ?></li>
				<li><?php esc_html_e( 'Атрибуты — в любом порядке. Значение в кавычках может содержать пробелы и скобки.', 'contact-form-submissions' ); ?></li>
				<li><code>[# текст]</code> — <?php esc_html_e( 'комментарий, в форму не попадает.', 'contact-form-submissions' ); ?></li>
				<li><code>\[</code> — <?php esc_html_e( 'обычная квадратная скобка в тексте.', 'contact-form-submissions' ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'Типы полей', 'contact-form-submissions' ); ?></h2>
			<table class="widefat striped cfs-help-table">
				<thead>
					<tr>
						<th style="width:18%"><?php esc_html_e( 'Тип', 'contact-form-submissions' ); ?></th>
						<th style="width:26%"><?php esc_html_e( 'Что это', 'contact-form-submissions' ); ?></th>
						<th><?php esc_html_e( 'Свои атрибуты', 'contact-form-submissions' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $types as $type => $descriptor ) : ?>
						<?php
						$own = array_values(
							array_diff(
								(array) $descriptor['supports'],
								CFS_Field_Types::COMMON_SUPPORTS
							)
						);
						?>
						<tr>
							<td><code><?php echo esc_html( $type ); ?></code></td>
							<td><?php echo esc_html( (string) $descriptor['label'] ); ?></td>
							<td><?php echo '' !== implode( '', $own ) ? '<code>' . esc_html( implode( '</code>, <code>', $own ) ) . '</code>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: %s: list of common attributes */
					esc_html__( 'Общие атрибуты для всех полей ввода: %s.', 'contact-form-submissions' ),
					'<code>' . esc_html( implode( '</code>, <code>', CFS_Field_Types::COMMON_SUPPORTS ) ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Примеры', 'contact-form-submissions' ); ?></h2>

			<p><strong><?php esc_html_e( 'Обратный звонок:', 'contact-form-submissions' ); ?></strong></p>
			<pre class="cfs-help-code">[name* first_name label="Имя" icon="user"]
[phone* phone label="Телефон" icon="phone"]
[submit "Жду звонка"]</pre>

			<p><strong><?php esc_html_e( 'Два поля в строку:', 'contact-form-submissions' ); ?></strong></p>
			<pre class="cfs-help-code">&lt;div class="cfs-row"&gt;
	[name* first_name label="Имя" width="1/2"]
	[phone* phone label="Телефон" width="1/2"]
&lt;/div&gt;
[submit "Отправить"]</pre>

			<p><strong><?php esc_html_e( 'Список и согласие:', 'contact-form-submissions' ); ?></strong></p>
			<pre class="cfs-help-code">[select* topic label="Тема" options="Консультация:consult,Расчёт:calc"]
[textarea comment label="Комментарий" rows="4"]
[agreement* consent label="Я согласен с &lt;a href='/privacy/'&gt;политикой&lt;/a&gt;"]
[submit "Отправить"]</pre>

			<p><strong><?php esc_html_e( 'Мастер в два шага:', 'contact-form-submissions' ); ?></strong></p>
			<pre class="cfs-help-code">[step label="Контакты"]
[name* first_name label="Имя"]
[phone* phone label="Телефон"]

[step label="Детали"]
[date when label="Удобная дата"]
[textarea comment label="Комментарий"]

[submit "Готово"]</pre>

			<p><strong><?php esc_html_e( 'UTM-метка из адреса страницы:', 'contact-form-submissions' ); ?></strong></p>
			<pre class="cfs-help-code">[hidden utm_source source="query:utm_source"]</pre>
			<p class="description">
				<?php esc_html_e( 'Источники: query:параметр, cookie:имя, page:url|title|id, user:email|login|name|id.', 'contact-form-submissions' ); ?>
			</p>

			<h2><?php esc_html_e( 'Подстановки в письмах', 'contact-form-submissions' ); ?></h2>
			<p>
				<?php esc_html_e( 'В теме и тексте письма работают подстановки в фигурных скобках: имя поля подставит его значение, {all_fields} — таблицу всех полей. Полный список доступных подстановок показан прямо над полями на вкладке «Письма» той формы, которую вы редактируете.', 'contact-form-submissions' ); ?>
			</p>
			<pre class="cfs-help-code">Тема: Заявка от {first_name}
Текст: {all_fields}

Служебные: {site_name} {form_title} {submission_id} {admin_url} {date} {ip} {page_url}</pre>

			<h2><?php esc_html_e( 'HTML в шаблоне', 'contact-form-submissions' ); ?></h2>
			<p>
				<?php esc_html_e( 'Вокруг полей можно писать обычную разметку: заголовки, абзацы, списки, таблицы, ссылки, картинки. Опасные теги (script, iframe) и обработчики событий вырезаются при сохранении. Поля формы (input, select) вручную вставлять нельзя — их значения всё равно не будут приняты.', 'contact-form-submissions' ); ?>
			</p>

			<h2><?php esc_html_e( 'Хуки для разработчиков', 'contact-form-submissions' ); ?></h2>
			<pre class="cfs-help-code">// Приём заявки.
apply_filters( 'cfs_before_save',      $data, $form_id )
do_action(    'cfs_after_save',        $submission_id, $data )
apply_filters( 'cfs_validate_field',   $error, $name, $value, $form_id )
apply_filters( 'cfs_spam_check',       $is_spam, $data, $form_id )
apply_filters( 'cfs_rate_limit',       $is_limited, $ip, $form_id )
apply_filters( 'cfs_success_response', $response, $data )

// Форма.
apply_filters( 'cfs_field_types',      $types )
apply_filters( 'cfs_icon_library',     $icons )
apply_filters( 'cfs_compiled_schema',  $schema, $template )
apply_filters( 'cfs_template_allowed_html', $tags )
apply_filters( 'cfs_render_field',     $html, $field, $renderer )
apply_filters( 'cfs_form_html',        $html, $form_id, $form, $instance )

// Письма и действия.
apply_filters( 'cfs_mail_context',     $context, $data, $form )
apply_filters( 'cfs_email_recipients', $recipients, $data, $slot )
apply_filters( 'cfs_email_headers',    $headers, $data )
apply_filters( 'cfs_email_body',       $body, $data )
apply_filters( 'cfs_integrations',     $items )
apply_filters( 'cfs_action_response',  $overrides, $form, $data, $submission_id )

// Админка (для дополнений).
apply_filters( 'cfs_submission_panels',  $panels, $submission )
apply_filters( 'cfs_submission_notices', $notices )
apply_filters( 'cfs_manage_capability',  $capability )</pre>
		</div>
		<style>
			.cfs-help-code{background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:12px;overflow-x:auto;}
			.cfs-help-list li{margin-bottom:4px;}
			.cfs-help-table{margin-bottom:8px;}
		</style>
		<?php
	}

	/**
	 * Get human-readable status label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function status_label( string $status ): string {
		$labels = array(
			'new'       => __( 'Новая', 'contact-form-submissions' ),
			'processed' => __( 'Обработана', 'contact-form-submissions' ),
			'spam'      => __( 'Спам', 'contact-form-submissions' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Build pagination HTML.
	 *
	 * @param int    $current  Current page.
	 * @param int    $total    Total pages.
	 * @param string $base_url Base URL.
	 * @param string $status   Status filter.
	 * @param string $form_id  Form ID filter.
	 * @return string
	 */
	private function pagination_html( int $current, int $total, string $base_url, string $status, string $form_id ): string {
		$html = '<span class="displaying-num">' . sprintf(
			/* translators: 1: current page number, 2: total pages */
			esc_html__( 'Страница %1$d из %2$d', 'contact-form-submissions' ),
			$current,
			$total
		) . '</span> ';

		$make_url = function( int $p ) use ( $base_url, $status, $form_id ): string {
			$url = $base_url . '&paged=' . $p;
			if ( $status ) {
				$url .= '&status=' . rawurlencode( $status );
			}
			if ( $form_id ) {
				$url .= '&form_id=' . rawurlencode( $form_id );
			}
			return $url;
		};

		if ( $current > 1 ) {
			$html .= '<a class="prev-page button" href="' . esc_url( $make_url( $current - 1 ) ) . '">&laquo;</a> ';
		}
		if ( $current < $total ) {
			$html .= '<a class="next-page button" href="' . esc_url( $make_url( $current + 1 ) ) . '">&raquo;</a>';
		}

		return $html;
	}

	/**
	 * Sanitize the agreement text: allow only anchor tags with safe attributes.
	 *
	 * Used as the sanitize_callback for the cfs_agreement_text option so that
	 * admins can include clickable links (e.g. to a privacy policy page) without
	 * being able to inject arbitrary HTML.
	 *
	 * @param mixed $value Raw option value from the settings form.
	 * @return string
	 */
	public function sanitize_agreement_text( $value ): string {
		$allowed = array(
			'a' => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
				'class'  => array(),
			),
		);
		return wp_kses( (string) $value, $allowed );
	}
}
