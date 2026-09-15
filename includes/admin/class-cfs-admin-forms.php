<?php
/**
 * Admin screen: the list of forms.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Admin_Forms
 */
class CFS_Admin_Forms {

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
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * URL of the forms list.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function list_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'cfs-forms' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL of the editor for one form.
	 *
	 * @param int $form_id Form post ID.
	 * @return string
	 */
	public static function edit_url( int $form_id ): string {
		return add_query_arg(
			array(
				'page' => 'cfs-form',
				'id'   => $form_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Handle create / duplicate / delete / export / import.
	 */
	public function handle_actions(): void {
		if ( ! isset( $_GET['page'] ) || 'cfs-forms' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! CFS_Post_Type::user_can_manage() ) {
			return;
		}

		if ( isset( $_POST['cfs_import_form'] ) ) {
			$this->handle_import();
			return;
		}

		$action = isset( $_GET['cfs_action'] ) ? sanitize_key( wp_unslash( $_GET['cfs_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $action ) {
			return;
		}

		$form_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'cfs_form_action_' . $action . '_' . $form_id );

		switch ( $action ) {
			case 'new':
				$form = CFS_Form::create( __( 'Новая форма', 'contact-form-submissions' ) );
				if ( $form ) {
					wp_safe_redirect( self::edit_url( $form->get_id() ) );
					exit;
				}
				break;

			case 'duplicate':
				$form = CFS_Form::load( $form_id );
				if ( $form ) {
					$copy = $form->duplicate();
					if ( $copy ) {
						wp_safe_redirect( self::edit_url( $copy->get_id() ) );
						exit;
					}
				}
				break;

			case 'delete':
				$form = CFS_Form::load( $form_id );
				if ( $form ) {
					$form->delete();
				}
				wp_safe_redirect( self::list_url( array( 'cfs_done' => 'deleted' ) ) );
				exit;

			case 'export':
				$form = CFS_Form::load( $form_id );
				if ( $form ) {
					$this->send_export( $form );
				}
				break;
		}

		wp_safe_redirect( self::list_url() );
		exit;
	}

	/**
	 * Stream one form as a JSON download.
	 *
	 * @param CFS_Form $form Form to export.
	 */
	private function send_export( CFS_Form $form ): void {
		$filename = 'cfs-form-' . ( '' !== $form->get_slug() ? $form->get_slug() : (string) $form->get_id() ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo (string) wp_json_encode( $form->to_array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Import a form from an uploaded JSON file.
	 */
	private function handle_import(): void {
		check_admin_referer( 'cfs_import_form' );

		if ( empty( $_FILES['cfs_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( self::list_url( array( 'cfs_error' => 'import_empty' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tmp_name = $_FILES['cfs_import_file']['tmp_name'];

		if ( ! is_uploaded_file( $tmp_name ) ) {
			wp_safe_redirect( self::list_url( array( 'cfs_error' => 'import_failed' ) ) );
			exit;
		}

		$contents = (string) file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data     = json_decode( $contents, true );

		if ( ! is_array( $data ) || ! isset( $data['template'] ) ) {
			wp_safe_redirect( self::list_url( array( 'cfs_error' => 'import_invalid' ) ) );
			exit;
		}

		$form = CFS_Form::from_array( $data );

		if ( ! $form ) {
			wp_safe_redirect( self::list_url( array( 'cfs_error' => 'import_failed' ) ) );
			exit;
		}

		wp_safe_redirect( self::edit_url( $form->get_id() ) );
		exit;
	}

	/**
	 * Render the list screen.
	 */
	public function render(): void {
		if ( ! CFS_Post_Type::user_can_manage() ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'contact-form-submissions' ) );
		}

		$forms  = CFS_Form::all();
		$counts = $this->db->count_by_form_post();

		?>
		<div class="wrap cfs-forms">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Формы', 'contact-form-submissions' ); ?></h1>
			<a href="<?php echo esc_url( wp_nonce_url( self::list_url( array( 'cfs_action' => 'new' ) ), 'cfs_form_action_new_0' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Добавить форму', 'contact-form-submissions' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Название', 'contact-form-submissions' ); ?></th>
						<th scope="col" style="width:22%"><?php esc_html_e( 'Шорткод', 'contact-form-submissions' ); ?></th>
						<th scope="col" style="width:8%"><?php esc_html_e( 'Полей', 'contact-form-submissions' ); ?></th>
						<th scope="col" style="width:10%"><?php esc_html_e( 'Заявок', 'contact-form-submissions' ); ?></th>
						<th scope="col" style="width:16%"><?php esc_html_e( 'Изменена', 'contact-form-submissions' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $forms ) ) : ?>
						<tr>
							<td colspan="5">
								<?php esc_html_e( 'Форм пока нет. Создайте первую — она сразу появится здесь с готовым шорткодом.', 'contact-form-submissions' ); ?>
							</td>
						</tr>
					<?php endif; ?>

					<?php foreach ( $forms as $form ) : ?>
						<?php
						$form_id  = $form->get_id();
						$errors   = $form->get_errors();
						$fatal    = $form->has_fatal_errors();
						$count    = (int) ( $counts[ $form_id ] ?? 0 );
						$modified = get_post_field( 'post_modified', $form_id );
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( self::edit_url( $form_id ) ); ?>"><?php echo esc_html( $form->get_title() ); ?></a></strong>
								<?php if ( ! empty( $errors ) ) : ?>
									<span class="cfs-badge cfs-badge--<?php echo $fatal ? 'error' : 'warning'; ?>">
										<?php
										echo esc_html(
											$fatal
												? __( 'ошибки', 'contact-form-submissions' )
												: __( 'предупреждения', 'contact-form-submissions' )
										);
										?>
									</span>
								<?php endif; ?>

								<div class="row-actions">
									<span class="edit"><a href="<?php echo esc_url( self::edit_url( $form_id ) ); ?>"><?php esc_html_e( 'Изменить', 'contact-form-submissions' ); ?></a> | </span>
									<span><a href="<?php echo esc_url( wp_nonce_url( self::list_url( array( 'cfs_action' => 'duplicate', 'id' => $form_id ) ), 'cfs_form_action_duplicate_' . $form_id ) ); ?>"><?php esc_html_e( 'Дублировать', 'contact-form-submissions' ); ?></a> | </span>
									<span><a href="<?php echo esc_url( wp_nonce_url( self::list_url( array( 'cfs_action' => 'export', 'id' => $form_id ) ), 'cfs_form_action_export_' . $form_id ) ); ?>"><?php esc_html_e( 'Экспорт', 'contact-form-submissions' ); ?></a> | </span>
									<span><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'cfs-submissions', 'form_id' => $form_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Заявки', 'contact-form-submissions' ); ?></a> | </span>
									<span class="trash">
										<a
											href="<?php echo esc_url( wp_nonce_url( self::list_url( array( 'cfs_action' => 'delete', 'id' => $form_id ) ), 'cfs_form_action_delete_' . $form_id ) ); ?>"
											class="cfs-confirm"
											data-confirm="<?php esc_attr_e( 'Удалить форму? Заявки останутся, но форма перестанет выводиться.', 'contact-form-submissions' ); ?>"
										><?php esc_html_e( 'Удалить', 'contact-form-submissions' ); ?></a>
									</span>
								</div>
							</td>
							<td><code class="cfs-shortcode" tabindex="0" title="<?php esc_attr_e( 'Нажмите, чтобы скопировать', 'contact-form-submissions' ); ?>"><?php echo esc_html( $form->get_shortcode() ); ?></code></td>
							<td><?php echo esc_html( (string) count( $form->get_fields() ) ); ?></td>
							<td>
								<?php if ( $count > 0 ) : ?>
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'cfs-submissions', 'form_id' => $form_id ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></a>
								<?php else : ?>
									<span class="cfs-muted">0</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', (string) $modified ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 class="cfs-import-title"><?php esc_html_e( 'Импорт формы', 'contact-form-submissions' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Загрузите JSON-файл, полученный через «Экспорт» на другом сайте — форма приедет вместе с шаблоном и всеми настройками.', 'contact-form-submissions' ); ?>
			</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( self::list_url() ); ?>">
				<?php wp_nonce_field( 'cfs_import_form' ); ?>
				<input type="file" name="cfs_import_file" accept="application/json,.json" required>
				<button type="submit" name="cfs_import_form" value="1" class="button"><?php esc_html_e( 'Импортировать', 'contact-form-submissions' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Result notices after an action.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$done  = isset( $_GET['cfs_done'] ) ? sanitize_key( wp_unslash( $_GET['cfs_done'] ) ) : '';
		$error = isset( $_GET['cfs_error'] ) ? sanitize_key( wp_unslash( $_GET['cfs_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'deleted'        => __( 'Форма удалена.', 'contact-form-submissions' ),
			'import_empty'   => __( 'Файл не выбран.', 'contact-form-submissions' ),
			'import_invalid' => __( 'Файл не похож на экспорт формы.', 'contact-form-submissions' ),
			'import_failed'  => __( 'Не удалось импортировать форму.', 'contact-form-submissions' ),
		);

		if ( '' !== $done && isset( $messages[ $done ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $done ] ) );
		}

		if ( '' !== $error && isset( $messages[ $error ] ) ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $messages[ $error ] ) );
		}
	}
}
