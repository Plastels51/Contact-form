<?php
/**
 * The migration screen: 2.x shortcodes → 3.x forms.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Legacy_Wizard
 */
class CFS_Legacy_Wizard {

	/**
	 * Post meta holding the pre-migration content.
	 */
	const META_BACKUP = '_cfs_migration_backup';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Priority 20: the parent menu is registered by the core at 10.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Borrow the editor's stylesheet and script for this screen.
	 *
	 * Both are general-purpose core assets; the handful of rules this screen
	 * adds live here so that deleting the module takes them with it.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'cfs-migration' ) ) {
			return;
		}

		wp_enqueue_style( 'cfs-editor', CFS_PLUGIN_URL . 'assets/css/cfs-editor.css', array(), CFS_VERSION );
		wp_enqueue_script( 'cfs-form-editor', CFS_PLUGIN_URL . 'assets/js/cfs-form-editor.js', array(), CFS_VERSION, true );

		wp_add_inline_style(
			'cfs-editor',
			'.cfs-migration .cfs-template-preview{background:#fff;border:1px solid #dcdcde;border-radius:4px;'
			. 'padding:12px;overflow-x:auto;font-size:12px;line-height:1.6;white-space:pre;}'
			. '.cfs-migration .cfs-panel h3{margin-top:0;}'
			. '.cfs-migration .cfs-removal-hint{margin-top:2rem;}'
		);
	}

	/**
	 * Add the screen under the submissions menu.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'cfs-submissions',
			__( 'Миграция форм', 'contact-form-submissions' ),
			__( 'Миграция', 'contact-form-submissions' ),
			CFS_Post_Type::capability(),
			'cfs-migration',
			array( $this, 'render' )
		);
	}

	/**
	 * Screen URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	private function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'cfs-migration' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Run whichever action was requested.
	 */
	public function handle_actions(): void {
		if ( ! isset( $_POST['cfs_migration_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( 'cfs_migration' );

		if ( ! CFS_Post_Type::user_can_manage() ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'contact-form-submissions' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['cfs_migration_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'migrate':
				$result = $this->migrate();
				wp_safe_redirect(
					$this->url(
						array(
							'cfs_done'  => 'migrated',
							'cfs_forms' => $result['forms'],
							'cfs_posts' => $result['replacements'],
						)
					)
				);
				exit;

			case 'rollback':
				$restored = $this->rollback();
				wp_safe_redirect( $this->url( array( 'cfs_done' => 'rolled_back', 'cfs_posts' => $restored ) ) );
				exit;

			case 'relink':
				$relinked = $this->relink();
				wp_safe_redirect( $this->url( array( 'cfs_done' => 'relinked', 'cfs_posts' => $relinked ) ) );
				exit;
		}
	}

	/**
	 * Create a form per unique shortcode and rewrite the content.
	 *
	 * @return array{forms: int, replacements: int}
	 */
	public function migrate(): array {
		$groups = CFS_Legacy_Scanner::group( CFS_Legacy_Scanner::scan() );

		$forms        = 0;
		$replacements = 0;

		foreach ( $groups as $group ) {
			$converted = CFS_Legacy_Adapter::convert( (array) $group['atts'] );

			$form = CFS_Form::create( (string) $converted['title'], (string) $converted['template'] );
			if ( ! $form ) {
				continue;
			}

			foreach ( (array) $converted['groups'] as $key => $values ) {
				$form->set_group( $key, $values );
			}
			$form->save();
			++$forms;

			foreach ( (array) $group['items'] as $item ) {
				if ( 'post' !== $item['source'] ) {
					continue; // Widgets are reported only — see the scanner.
				}

				if ( $this->replace_in_post( (int) $item['id'], (string) $item['shortcode'], $form->get_id() ) ) {
					++$replacements;
				}
			}
		}

		if ( $forms > 0 ) {
			CFS_Legacy::record_migration( $forms, $replacements );
		}

		return array(
			'forms'        => $forms,
			'replacements' => $replacements,
		);
	}

	/**
	 * Swap one shortcode for its 3.x equivalent inside a post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $shortcode Exact shortcode text to replace.
	 * @param int    $form_id   New form ID.
	 * @return bool
	 */
	private function replace_in_post( int $post_id, string $shortcode, int $form_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$content = (string) $post->post_content;
		if ( false === strpos( $content, $shortcode ) ) {
			return false;
		}

		$updated = str_replace( $shortcode, sprintf( '[contact_form id="%d"]', $form_id ), $content );

		// Keep the pre-migration content so the rollback button can restore it
		// byte for byte. Only the first migration writes it.
		if ( '' === (string) get_post_meta( $post_id, self::META_BACKUP, true ) ) {
			update_post_meta( $post_id, self::META_BACKUP, $content );
		}

		/*
		 * kses is disabled for this one write: the migration must change the
		 * shortcode and nothing else, and on multisite even an administrator
		 * would otherwise have unrelated markup stripped out of their page.
		 */
		$kses_was_on = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses_was_on ) {
			kses_remove_filters();
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $updated,
			),
			true
		);

		if ( $kses_was_on ) {
			kses_init_filters();
		}

		return ! is_wp_error( $result );
	}

	/**
	 * Restore every backed-up post.
	 *
	 * @return int Posts restored.
	 */
	public function rollback(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_BACKUP )
		);

		$restored = 0;

		foreach ( (array) $post_ids as $post_id ) {
			$backup = (string) get_post_meta( (int) $post_id, self::META_BACKUP, true );
			if ( '' === $backup ) {
				continue;
			}

			$kses_was_on = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
			if ( $kses_was_on ) {
				kses_remove_filters();
			}

			$result = wp_update_post(
				array(
					'ID'           => (int) $post_id,
					'post_content' => $backup,
				),
				true
			);

			if ( $kses_was_on ) {
				kses_init_filters();
			}

			if ( ! is_wp_error( $result ) ) {
				delete_post_meta( (int) $post_id, self::META_BACKUP );
				++$restored;
			}
		}

		return $restored;
	}

	/**
	 * Attach old submissions to a form chosen by the user.
	 *
	 * @return int Rows updated.
	 */
	public function relink(): int {
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_actions().
		$map = isset( $_POST['cfs_relink'] ) && is_array( $_POST['cfs_relink'] ) ? wp_unslash( $_POST['cfs_relink'] ) : array();

		$db      = new CFS_DB();
		$table   = $db->get_submissions_table();
		$updated = 0;

		foreach ( $map as $legacy_id => $form_id ) {
			$legacy_id = sanitize_text_field( (string) $legacy_id );
			$form_id   = (int) $form_id;

			if ( '' === $legacy_id || $form_id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET form_post_id = %d WHERE form_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$form_id,
					$legacy_id
				)
			);
		}

		return $updated;
	}

	/**
	 * Legacy form identifiers still present in the submissions table.
	 *
	 * @return array<string, int> form_id => submission count.
	 */
	private function legacy_form_ids(): array {
		global $wpdb;

		$db    = new CFS_DB();
		$table = $db->get_submissions_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT form_id, COUNT(*) AS total FROM {$table} WHERE form_post_id IS NULL GROUP BY form_id ORDER BY total DESC" );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->form_id ] = (int) $row->total;
		}

		return $out;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! CFS_Post_Type::user_can_manage() ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'contact-form-submissions' ) );
		}

		$groups     = CFS_Legacy_Scanner::group( CFS_Legacy_Scanner::scan() );
		$legacy_ids = $this->legacy_form_ids();
		$state      = CFS_Legacy::migration_state();
		$has_backup = $this->has_backups();
		?>
		<div class="wrap cfs-migration">
			<h1><?php esc_html_e( 'Миграция форм 2.x → 3.0', 'contact-form-submissions' ); ?></h1>

			<?php $this->render_notices(); ?>

			<?php if ( empty( $groups ) && empty( $legacy_ids ) ) : ?>
				<div class="notice notice-success inline">
					<p><strong><?php esc_html_e( 'Мигрировать нечего — шорткодов старого формата на сайте нет.', 'contact-form-submissions' ); ?></strong></p>
				</div>
				<?php $this->render_removal_hint(); ?>
				</div>
				<?php
				return;
			endif;
			?>

			<?php if ( ! empty( $groups ) ) : ?>
				<h2><?php esc_html_e( 'Найденные формы', 'contact-form-submissions' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Одинаковые шорткоды объединяются в одну форму. Перед заменой оригинальный текст страницы сохраняется — откатить можно кнопкой ниже.', 'contact-form-submissions' ); ?>
				</p>

				<?php foreach ( $groups as $hash => $group ) : ?>
					<?php $converted = CFS_Legacy_Adapter::convert( (array) $group['atts'] ); ?>
					<div class="cfs-panel">
						<h3><?php echo esc_html( (string) $converted['title'] ); ?></h3>

						<p><strong><?php esc_html_e( 'Где встречается:', 'contact-form-submissions' ); ?></strong></p>
						<ul>
							<?php foreach ( (array) $group['items'] as $item ) : ?>
								<li>
									<?php if ( '' !== (string) $item['edit_url'] ) : ?>
										<a href="<?php echo esc_url( (string) $item['edit_url'] ); ?>"><?php echo esc_html( (string) $item['title'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( (string) $item['title'] ); ?>
									<?php endif; ?>
									<span class="cfs-muted">— <?php echo esc_html( (string) $item['subtitle'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>

						<p><strong><?php esc_html_e( 'Получится шаблон:', 'contact-form-submissions' ); ?></strong></p>
						<pre class="cfs-template-preview"><?php echo esc_html( (string) $converted['template'] ); ?></pre>
					</div>
				<?php endforeach; ?>

				<form method="post" action="<?php echo esc_url( $this->url() ); ?>">
					<?php wp_nonce_field( 'cfs_migration' ); ?>
					<button
						type="submit"
						name="cfs_migration_action"
						value="migrate"
						class="button button-primary cfs-confirm"
						data-confirm="<?php esc_attr_e( 'Создать формы и заменить шорткоды на страницах?', 'contact-form-submissions' ); ?>"
					><?php esc_html_e( 'Мигрировать', 'contact-form-submissions' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( ! empty( $legacy_ids ) ) : ?>
				<h2><?php esc_html_e( 'Привязка старых заявок', 'contact-form-submissions' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Заявки, отправленные до миграции, не знают о новых формах. Укажите, какой форме соответствует каждый старый идентификатор — тогда фильтры и экспорт будут работать и для них.', 'contact-form-submissions' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( $this->url() ); ?>">
					<?php wp_nonce_field( 'cfs_migration' ); ?>
					<table class="wp-list-table widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Старый идентификатор', 'contact-form-submissions' ); ?></th>
								<th><?php esc_html_e( 'Заявок', 'contact-form-submissions' ); ?></th>
								<th><?php esc_html_e( 'Привязать к форме', 'contact-form-submissions' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $legacy_ids as $legacy_id => $count ) : ?>
								<tr>
									<td><code><?php echo esc_html( $legacy_id ); ?></code></td>
									<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
									<td>
										<select name="cfs_relink[<?php echo esc_attr( $legacy_id ); ?>]">
											<option value="0"><?php esc_html_e( '— не привязывать —', 'contact-form-submissions' ); ?></option>
											<?php foreach ( CFS_Form::all() as $form ) : ?>
												<option value="<?php echo esc_attr( (string) $form->get_id() ); ?>"><?php echo esc_html( $form->get_title() ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<button type="submit" name="cfs_migration_action" value="relink" class="button"><?php esc_html_e( 'Привязать', 'contact-form-submissions' ); ?></button>
					</p>
				</form>
			<?php endif; ?>

			<?php if ( $has_backup ) : ?>
				<h2><?php esc_html_e( 'Откат', 'contact-form-submissions' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Вернёт содержимое страниц к тому, каким оно было до замены шорткодов. Созданные формы останутся — их можно удалить вручную.', 'contact-form-submissions' ); ?></p>
				<form method="post" action="<?php echo esc_url( $this->url() ); ?>">
					<?php wp_nonce_field( 'cfs_migration' ); ?>
					<button
						type="submit"
						name="cfs_migration_action"
						value="rollback"
						class="button cfs-confirm"
						data-confirm="<?php esc_attr_e( 'Вернуть исходный текст страниц?', 'contact-form-submissions' ); ?>"
					><?php esc_html_e( 'Откатить замену', 'contact-form-submissions' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( ! empty( $state ) ) : ?>
				<?php $this->render_removal_hint(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Whether any post has a pre-migration backup.
	 *
	 * @return bool
	 */
	private function has_backups(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_BACKUP )
		);
	}

	/**
	 * The "you can delete this module now" note.
	 */
	private function render_removal_hint(): void {
		$state = CFS_Legacy::migration_state();
		?>
		<div class="notice notice-info inline cfs-removal-hint">
			<p><strong><?php esc_html_e( 'Модуль совместимости больше не нужен?', 'contact-form-submissions' ); ?></strong></p>
			<?php if ( ! empty( $state['date'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: date, 2: number of forms, 3: number of pages */
						esc_html__( 'Миграция выполнена %1$s: создано форм — %2$d, заменено шорткодов — %3$d.', 'contact-form-submissions' ),
						esc_html( mysql2date( 'd.m.Y H:i', (string) $state['date'] ) ),
						(int) ( $state['forms'] ?? 0 ),
						(int) ( $state['replacements'] ?? 0 )
					);
					?>
				</p>
			<?php endif; ?>
			<p>
				<?php esc_html_e( 'Удалите папку includes/legacy/ в плагине — вместе с ней исчезнут поддержка старого синтаксиса и этот экран. Ядро о них не знает, править код не нужно.', 'contact-form-submissions' ); ?>
			</p>
			<p><code>wp-content/plugins/contact-form-submissions/includes/legacy/</code></p>
		</div>
		<?php
	}

	/**
	 * Result notices.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$done  = isset( $_GET['cfs_done'] ) ? sanitize_key( wp_unslash( $_GET['cfs_done'] ) ) : '';
		$forms = isset( $_GET['cfs_forms'] ) ? (int) $_GET['cfs_forms'] : 0;
		$posts = isset( $_GET['cfs_posts'] ) ? (int) $_GET['cfs_posts'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $done ) {
			return;
		}

		$message = '';

		switch ( $done ) {
			case 'migrated':
				$message = sprintf(
					/* translators: 1: forms created, 2: shortcodes replaced */
					__( 'Готово: создано форм — %1$d, заменено шорткодов — %2$d.', 'contact-form-submissions' ),
					$forms,
					$posts
				);
				break;

			case 'rolled_back':
				/* translators: %d: posts restored */
				$message = sprintf( __( 'Восстановлено страниц: %d.', 'contact-form-submissions' ), $posts );
				break;

			case 'relinked':
				/* translators: %d: submissions relinked */
				$message = sprintf( __( 'Привязано заявок: %d.', 'contact-form-submissions' ), $posts );
				break;
		}

		if ( '' !== $message ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
	}
}
