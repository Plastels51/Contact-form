<?php
/**
 * WordPress dashboard widget — latest new submissions.
 *
 * Shows a summary counter and a compact table of the 5 most
 * recent submissions with status "new".
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Dashboard
 */
class CFS_Dashboard {

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
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	/**
	 * Register the dashboard widget.
	 */
	public function register_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$new_count = $this->get_new_count();
		$title     = __( 'Заявки с сайта', 'contact-form-submissions' );
		if ( $new_count > 0 ) {
			$title .= ' <span class="cfs-dashboard-badge">' . (int) $new_count . '</span>';
		}

		wp_add_dashboard_widget(
			'cfs_dashboard_widget',
			$title,
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Get count of new submissions (uses transient cache).
	 *
	 * @return int
	 */
	private function get_new_count(): int {
		$count = get_transient( 'cfs_new_count' );
		if ( false === $count ) {
			$count = $this->db->count_by_status( 'new' );
			set_transient( 'cfs_new_count', $count, 5 * MINUTE_IN_SECONDS );
		}
		return (int) $count;
	}

	/**
	 * Render widget content.
	 */
	public function render_widget(): void {
		$new_count   = $this->get_new_count();
		$submissions = $this->db->get_recent( 10, '' );

		// Inline CSS for dashboard widget badge and status dots.
		?>
		<style>
			.cfs-dashboard-badge {
				display: inline-block;
				min-width: 18px;
				height: 18px;
				padding: 0 5px;
				border-radius: 9px;
				background-color: #d63638;
				color: #fff;
				font-size: 11px;
				font-weight: 600;
				line-height: 18px;
				text-align: center;
				vertical-align: middle;
				margin-left: 4px;
			}
			.cfs-dashboard-summary {
				display: flex;
				gap: 16px;
				margin-bottom: 12px;
				padding: 10px 12px;
				background: #f0f0f1;
				border-radius: 4px;
			}
			.cfs-dashboard-summary-item {
				text-align: center;
			}
			.cfs-dashboard-summary-item strong {
				display: block;
				font-size: 1.4em;
				line-height: 1.2;
			}
			.cfs-dashboard-summary-item span {
				font-size: 12px;
				color: #646970;
			}
			.cfs-dw-status {
				display: inline-block;
				width: 8px;
				height: 8px;
				border-radius: 50%;
				margin-right: 4px;
				vertical-align: middle;
			}
			.cfs-dw-status--new       { background-color: #2271b1; }
			.cfs-dw-status--processed { background-color: #00a32a; }
			.cfs-dw-status--spam      { background-color: #dba617; }
			#cfs_dashboard_widget .inside { padding-bottom: 0; }
		</style>
		<?php

		// Summary counters.
		$count_processed = $this->db->count_by_status( 'processed' );
		$count_spam      = $this->db->count_by_status( 'spam' );
		?>
		<div class="cfs-dashboard-summary">
			<div class="cfs-dashboard-summary-item">
				<strong style="color:#2271b1;"><?php echo (int) $new_count; ?></strong>
				<span><?php esc_html_e( 'Новые', 'contact-form-submissions' ); ?></span>
			</div>
			<div class="cfs-dashboard-summary-item">
				<strong style="color:#00a32a;"><?php echo (int) $count_processed; ?></strong>
				<span><?php esc_html_e( 'Обработано', 'contact-form-submissions' ); ?></span>
			</div>
			<div class="cfs-dashboard-summary-item">
				<strong style="color:#dba617;"><?php echo (int) $count_spam; ?></strong>
				<span><?php esc_html_e( 'Спам', 'contact-form-submissions' ); ?></span>
			</div>
		</div>
		<?php

		if ( empty( $submissions ) ) {
			echo '<p>' . esc_html__( 'Заявок пока нет.', 'contact-form-submissions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="margin-top:0;border:none;">';
		echo '<thead><tr>';
		echo '<th style="padding:6px 8px;">' . esc_html__( 'Имя', 'contact-form-submissions' ) . '</th>';
		echo '<th style="padding:6px 8px;">' . esc_html__( 'Контакт', 'contact-form-submissions' ) . '</th>';
		echo '<th style="padding:6px 8px;">' . esc_html__( 'Форма', 'contact-form-submissions' ) . '</th>';
		echo '<th style="padding:6px 8px;">' . esc_html__( 'Дата', 'contact-form-submissions' ) . '</th>';
		echo '<th style="padding:6px 8px;"></th>';
		echo '</tr></thead><tbody>';

		foreach ( $submissions as $row ) {
			$view_url = admin_url( 'admin.php?page=cfs-submissions&action=view&id=' . (int) $row->id );
			$contact  = $row->phone ?? ( $row->email ?? '—' );
			echo '<tr>';
			echo '<td style="padding:6px 8px;">';
			echo '<span class="cfs-dw-status cfs-dw-status--' . esc_attr( $row->status ) . '" title="' . esc_attr( $this->status_label( $row->status ) ) . '"></span>';
			echo esc_html( $row->name ?? '—' );
			echo '</td>';
			echo '<td style="padding:6px 8px;">' . esc_html( $contact ) . '</td>';
			echo '<td style="padding:6px 8px;"><code style="font-size:11px;">' . esc_html( $row->form_id ) . '</code></td>';
			echo '<td style="padding:6px 8px;">' . esc_html( mysql2date( 'd.m.Y H:i', $row->submitted_at ) ) . '</td>';
			echo '<td style="padding:6px 8px;"><a href="' . esc_url( $view_url ) . '">' . esc_html__( 'Открыть', 'contact-form-submissions' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$all_url = admin_url( 'admin.php?page=cfs-submissions' );
		echo '<p style="text-align:right;margin:8px 0 4px;"><a href="' . esc_url( $all_url ) . '">' . esc_html__( 'Все заявки &rarr;', 'contact-form-submissions' ) . '</a></p>';
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
}
