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
	 * Constructor.
	 *
	 * @param CFS_DB $db DB instance.
	 */
	public function __construct( CFS_DB $db ) {
		$this->db = $db;
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// AJAX status update.
		add_action( 'wp_ajax_cfs_update_status', array( $this, 'ajax_update_status' ) );
		add_action( 'wp_ajax_cfs_delete_submission', array( $this, 'ajax_delete_submission' ) );
	}

	/**
	 * Enqueue admin assets on plugin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		$pages = array( 'toplevel_page_cfs-submissions', 'zajavki_page_cfs-settings', 'zajavki_page_cfs-help' );
		if ( ! in_array( $hook, $pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'cfs-admin',
			CFS_PLUGIN_URL . 'assets/css/cfs-form.css',
			array(),
			CFS_VERSION
		);

		wp_enqueue_script(
			'cfs-admin',
			CFS_PLUGIN_URL . 'assets/js/cfs-form.js',
			array( 'jquery' ),
			CFS_VERSION,
			true
		);

		wp_localize_script(
			'cfs-admin',
			'cfsAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'cfs_admin_action' ),
				'confirmDelete' => __( 'Удалить эту заявку? Действие необратимо.', 'contact-form-submissions' ),
			)
		);
	}

	/**
	 * Register admin menus.
	 */
	public function register_menus(): void {
		$new_count = $this->db->get_new_count();
		$badge     = $new_count > 0
			? ' <span class="awaiting-mod">' . number_format_i18n( $new_count ) . '</span>'
			: '';

		add_menu_page(
			__( 'Заявки', 'contact-form-submissions' ),
			__( 'Заявки', 'contact-form-submissions' ) . $badge,
			'manage_options',
			'cfs-submissions',
			array( $this, 'page_submissions' ),
			'dashicons-email-alt',
			30
		);

		add_submenu_page(
			'cfs-submissions',
			__( 'Все заявки', 'contact-form-submissions' ),
			__( 'Все заявки', 'contact-form-submissions' ),
			'manage_options',
			'cfs-submissions',
			array( $this, 'page_submissions' )
		);

		add_submenu_page(
			'cfs-submissions',
			__( 'Настройки', 'contact-form-submissions' ),
			__( 'Настройки', 'contact-form-submissions' ),
			'manage_options',
			'cfs-settings',
			array( $this, 'page_settings' )
		);

		add_submenu_page(
			'cfs-submissions',
			__( 'Помощь', 'contact-form-submissions' ),
			__( 'Помощь', 'contact-form-submissions' ),
			'manage_options',
			'cfs-help',
			array( $this, 'page_help' )
		);
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
	 * AJAX handler: delete submission.
	 */
	public function ajax_delete_submission(): void {
		check_ajax_referer( 'cfs_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Нет прав.', 'contact-form-submissions' ) ) );
			return;
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Неверный ID.', 'contact-form-submissions' ) ) );
			return;
		}

		$result = $this->db->delete_submission( $id );
		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Заявка удалена.', 'contact-form-submissions' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Ошибка удаления.', 'contact-form-submissions' ) ) );
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
		$total       = $this->db->count_submissions( array(
			'status'  => $status_filter,
			'form_id' => $form_id_filter,
		) );

		$count_all       = $this->db->count_submissions();
		$count_new       = $this->db->count_submissions( array( 'status' => 'new' ) );
		$count_processed = $this->db->count_submissions( array( 'status' => 'processed' ) );
		$count_spam      = $this->db->count_submissions( array( 'status' => 'spam' ) );

		$total_pages = (int) ceil( $total / 20 );
		$form_ids    = $this->db->get_form_ids();

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
						<option value="<?php echo esc_attr( $fid ); ?>" <?php selected( $form_id_filter, $fid ); ?>><?php echo esc_html( $fid ); ?></option>
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
							$form_data = array();
							if ( ! empty( $row->form_data_json ) ) {
								$decoded   = json_decode( $row->form_data_json, true );
								$form_data = is_array( $decoded ) ? $decoded : array();
							}
							$full_name = trim( implode( ' ', array_filter( array(
								$form_data['surname'] ?? '',
								$row->name ?? '',
								$form_data['patronymic'] ?? '',
							) ) ) );
							if ( empty( $full_name ) ) {
								$full_name = '—';
							}
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
								<td><?php echo esc_html( $row->phone ?? '—' ); ?></td>
								<td><?php echo esc_html( $row->email ?? '—' ); ?></td>
								<td><?php echo esc_html( $row->form_id ); ?></td>
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

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var selectAll = document.getElementById('cfs-select-all');
			if (selectAll) {
				selectAll.addEventListener('change', function() {
					var boxes = document.querySelectorAll('input[name="submission_ids[]"]');
					boxes.forEach(function(box) { box.checked = selectAll.checked; });
				});
			}
		});
		</script>
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

		$form_data = array();
		if ( ! empty( $submission->form_data_json ) ) {
			$decoded   = json_decode( $submission->form_data_json, true );
			$form_data = is_array( $decoded ) ? $decoded : array();
		}

		$list_url   = admin_url( 'admin.php?page=cfs-submissions' );
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=cfs-submissions&action=delete&id=' . $id ),
			'cfs_delete_' . $id
		);

		/*
		 * Collect extra fields from form_data_json, splitting them into
		 * contact info (name/surname/patronymic/phone/email indexed variants)
		 * and other form fields. Skip agreement/checkbox — not useful in detail view.
		 */
		$extra_contact = array();
		$extra_fields  = array();
		$skip_bases    = array( 'agreement', 'checkbox', 'hidden' );

		if ( ! empty( $form_data['extra'] ) && is_array( $form_data['extra'] ) ) {
			foreach ( $form_data['extra'] as $extra_key => $extra_value ) {
				if ( '' === (string) $extra_value ) {
					continue;
				}
				$base_type = (string) preg_replace( '/(_\d+)$/', '', $extra_key );
				if ( in_array( $base_type, $skip_bases, true ) ) {
					continue;
				}
				$contact_bases = array( 'name', 'surname', 'patronymic', 'phone', 'email' );
				if ( in_array( $base_type, $contact_bases, true ) ) {
					$extra_contact[ $extra_key ] = (string) $extra_value;
				} else {
					$extra_fields[ $extra_key ] = (string) $extra_value;
				}
			}
		}
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

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">

					<!-- ═══ Main column ═══ -->
					<div id="post-body-content">

						<!-- ── Section: Applicant info ── -->
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Заявитель', 'contact-form-submissions' ); ?></span></h2>
							<div class="inside">
								<table class="form-table" style="margin-top:0;">
									<?php if ( ! empty( $form_data['surname'] ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'Фамилия', 'contact-form-submissions' ); ?></th>
										<td><?php echo esc_html( $form_data['surname'] ); ?></td>
									</tr>
									<?php endif; ?>
									<tr>
										<th><?php esc_html_e( 'Имя', 'contact-form-submissions' ); ?></th>
										<td><?php echo esc_html( $submission->name ?? '—' ); ?></td>
									</tr>
									<?php if ( ! empty( $form_data['patronymic'] ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'Отчество', 'contact-form-submissions' ); ?></th>
										<td><?php echo esc_html( $form_data['patronymic'] ); ?></td>
									</tr>
									<?php endif; ?>
									<tr>
										<th><?php esc_html_e( 'Телефон', 'contact-form-submissions' ); ?></th>
										<td>
											<?php if ( $submission->phone ) : ?>
												<a href="tel:<?php echo esc_attr( $submission->phone ); ?>"><?php echo esc_html( $submission->phone ); ?></a>
											<?php else : ?>
												—
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Email', 'contact-form-submissions' ); ?></th>
										<td><?php echo $submission->email ? '<a href="mailto:' . esc_attr( $submission->email ) . '">' . esc_html( $submission->email ) . '</a>' : '—'; ?></td>
									</tr>
									<?php foreach ( $extra_contact as $ck => $cv ) : ?>
									<tr>
										<th><?php echo esc_html( $this->format_extra_field_label( $ck ) ); ?></th>
										<td><?php echo esc_html( $cv ); ?></td>
									</tr>
									<?php endforeach; ?>
								</table>
							</div>
						</div>

						<!-- ── Section: Form fields ── -->
						<?php
						$has_form_fields = ! empty( $submission->comment )
							|| ! empty( $form_data['select'] )
							|| ! empty( $extra_fields );
						if ( $has_form_fields ) :
						?>
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Данные формы', 'contact-form-submissions' ); ?></span></h2>
							<div class="inside">
								<table class="form-table" style="margin-top:0;">
									<?php if ( ! empty( $form_data['select'] ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'Выбор', 'contact-form-submissions' ); ?></th>
										<td><?php echo esc_html( $form_data['select'] ); ?></td>
									</tr>
									<?php endif; ?>
									<?php if ( ! empty( $submission->comment ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'Комментарий', 'contact-form-submissions' ); ?></th>
										<td><?php echo nl2br( esc_html( $submission->comment ) ); ?></td>
									</tr>
									<?php endif; ?>
									<?php foreach ( $extra_fields as $ek => $ev ) : ?>
									<tr>
										<th><?php echo esc_html( $this->format_extra_field_label( $ek ) ); ?></th>
										<td><?php echo nl2br( esc_html( $ev ) ); ?></td>
									</tr>
									<?php endforeach; ?>
								</table>
							</div>
						</div>
						<?php endif; ?>

					</div>

					<!-- ═══ Sidebar ═══ -->
					<div id="postbox-container-1" class="postbox-container" style="width:35%;">

						<!-- ── Section: Status & meta ── -->
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Информация о заявке', 'contact-form-submissions' ); ?></span></h2>
							<div class="inside">
								<table class="form-table" style="margin-top:0;">
									<tr>
										<th><?php esc_html_e( 'ID', 'contact-form-submissions' ); ?></th>
										<td><strong><?php echo (int) $submission->id; ?></strong></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Форма', 'contact-form-submissions' ); ?></th>
										<td><code><?php echo esc_html( $submission->form_id ); ?></code></td>
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

					</div>
				</div>
			</div>

			<script>
			document.addEventListener('DOMContentLoaded', function() {
				var btn = document.getElementById('cfs-save-status');
				if (!btn) return;
				btn.addEventListener('click', function() {
					var sel = document.getElementById('cfs-status-select');
					var msg = document.getElementById('cfs-status-msg');
					var data = new FormData();
					data.append('action', 'cfs_update_status');
					data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'cfs_admin_action' ) ); ?>');
					data.append('id', sel.dataset.id);
					data.append('status', sel.value);
					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: data })
						.then(function(r){ return r.json(); })
						.then(function(res){
							msg.textContent = res.data && res.data.message ? res.data.message : '';
						});
				});
			});
			</script>
		</div>
		<?php
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
							<p class="description"><?php esc_html_e( 'По одному слову на строку.', 'contact-form-submissions' ); ?></p>
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
		?>
		<div class="wrap cfs-admin-wrap">
			<h1><?php esc_html_e( 'Помощь', 'contact-form-submissions' ); ?></h1>
			<h2><?php esc_html_e( 'Использование шорткода', 'contact-form-submissions' ); ?></h2>
			<pre style="background:#f5f5f5;padding:12px;border:1px solid #ddd;">
[contact_form]
[contact_form fields="name,phone,email" title="Обратная связь"]
[contact_form fields="name,phone,select" select_label="Тема" select_options="Вопрос:question,Другое:other"]
[contact_form form_id="quick" fields="name,phone" button_text="Перезвоните мне"]
			</pre>
			<h2><?php esc_html_e( 'Поля формы', 'contact-form-submissions' ); ?></h2>
			<p><?php esc_html_e( 'Доступны: name, surname, patronymic, phone, email, comment, select, checkbox, hidden.', 'contact-form-submissions' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Format a display label for an extra / indexed field key.
	 *
	 * Converts known indexed tokens to translated strings:
	 *   "comment_2"  → "Комментарий 2"
	 *   "name_2"     → "Имя 2"
	 *   "phone_3"    → "Телефон 3"
	 *
	 * Unknown / custom keys (e.g. "utm_source") are returned as-is.
	 *
	 * @param string $key Field key from form_data_json extra array.
	 * @return string
	 */
	private function format_extra_field_label( string $key ): string {
		$base_labels = array(
			'name'       => __( 'Имя', 'contact-form-submissions' ),
			'surname'    => __( 'Фамилия', 'contact-form-submissions' ),
			'patronymic' => __( 'Отчество', 'contact-form-submissions' ),
			'phone'      => __( 'Телефон', 'contact-form-submissions' ),
			'email'      => __( 'Email', 'contact-form-submissions' ),
			'comment'    => __( 'Комментарий', 'contact-form-submissions' ),
			'select'     => __( 'Выберите', 'contact-form-submissions' ),
			'checkbox'   => __( 'Согласен', 'contact-form-submissions' ),
			'agreement'  => __( 'Согласие', 'contact-form-submissions' ),
			'radio'      => __( 'Выбор', 'contact-form-submissions' ),
		);

		if ( preg_match( '/^([a-z]+)_(\d+)$/', $key, $m ) ) {
			$base  = $m[1];
			$index = (int) $m[2];
			if ( isset( $base_labels[ $base ] ) ) {
				return $base_labels[ $base ] . ' ' . $index;
			}
		}

		// Unknown key (custom hidden field, etc.) — return as-is.
		return $key;
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
			/* translators: %d: page number */
			esc_html__( 'Страница %d из %d', 'contact-form-submissions' ),
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
