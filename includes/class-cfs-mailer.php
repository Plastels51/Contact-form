<?php
/**
 * Email notification sender.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Mailer
 */
class CFS_Mailer {

	/**
	 * Send notification email for a new submission.
	 *
	 * @param array $data          Submission data.
	 * @param int   $submission_id Submission ID.
	 */
	public function send_notification( array $data, int $submission_id ): void {
		$recipients = $this->get_recipients( $data );
		$subject    = $this->get_subject( $data );
		$body       = $this->get_body( $data, $submission_id );
		$headers    = $this->get_headers( $data );

		$result = wp_mail( $recipients, $subject, $body, $headers );

		if ( ! $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'CFS Mailer: failed to send notification for submission #%d',
				$submission_id
			) );
		}
	}

	/**
	 * Build list of email recipients.
	 *
	 * @param array $data Submission data.
	 * @return array
	 */
	private function get_recipients( array $data ): array {
		$recipients = array( get_option( 'admin_email' ) );

		$extra = get_option( 'cfs_extra_emails', '' );
		if ( ! empty( $extra ) ) {
			foreach ( explode( ',', $extra ) as $email ) {
				$email = trim( $email );
				if ( is_email( $email ) ) {
					$recipients[] = $email;
				}
			}
		}

		return apply_filters( 'cfs_email_recipients', $recipients, $data );
	}

	/**
	 * Build email subject.
	 *
	 * @param array $data Submission data.
	 * @return string
	 */
	private function get_subject( array $data ): string {
		$template = get_option(
			'cfs_email_subject',
			/* translators: 1: site name, 2: form ID */
			__( 'Новая заявка с сайта {site_name} — форма {form_id}', 'contact-form-submissions' )
		);

		$subject = str_replace(
			array( '{site_name}', '{form_id}' ),
			array( get_bloginfo( 'name' ), $data['form_id'] ?? 'default' ),
			$template
		);

		return wp_strip_all_tags( $subject );
	}

	/**
	 * Build email headers.
	 *
	 * @param array $data Submission data.
	 * @return array
	 */
	private function get_headers( array $data ): array {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( ! empty( $data['email'] ) && is_email( $data['email'] ) ) {
			$reply_name = ! empty( $data['name'] ) ? $data['name'] : $data['email'];
			$headers[]  = 'Reply-To: ' . sanitize_text_field( $reply_name ) . ' <' . $data['email'] . '>';
		}

		return apply_filters( 'cfs_email_headers', $headers, $data );
	}

	/**
	 * Build HTML email body.
	 *
	 * @param array $data          Submission data.
	 * @param int   $submission_id Submission ID.
	 * @return string
	 */
	private function get_body( array $data, int $submission_id ): string {
		$admin_url = admin_url( 'admin.php?page=cfs-submissions&action=view&id=' . $submission_id );

		$fields_html = '';
		$field_map   = array(
			'name'       => __( 'Имя', 'contact-form-submissions' ),
			'surname'    => __( 'Фамилия', 'contact-form-submissions' ),
			'patronymic' => __( 'Отчество', 'contact-form-submissions' ),
			'phone'      => __( 'Телефон', 'contact-form-submissions' ),
			'email'      => __( 'Email', 'contact-form-submissions' ),
			'comment'    => __( 'Комментарий', 'contact-form-submissions' ),
			'select'     => __( 'Тема', 'contact-form-submissions' ),
		);

		foreach ( $field_map as $key => $label ) {
			if ( ! empty( $data[ $key ] ) ) {
				$fields_html .= sprintf(
					'<tr><td style="padding:6px 12px;font-weight:bold;background:#f5f5f5;border:1px solid #ddd;">%s</td><td style="padding:6px 12px;border:1px solid #ddd;">%s</td></tr>',
					esc_html( $label ),
					nl2br( esc_html( $data[ $key ] ) )
				);
			}
		}

		if ( ! empty( $data['extra'] ) && is_array( $data['extra'] ) ) {
			foreach ( $data['extra'] as $key => $val ) {
				$fields_html .= sprintf(
					'<tr><td style="padding:6px 12px;font-weight:bold;background:#f5f5f5;border:1px solid #ddd;">%s</td><td style="padding:6px 12px;border:1px solid #ddd;">%s</td></tr>',
					esc_html( $key ),
					esc_html( $val )
				);
			}
		}

		$meta_html = '';
		if ( ! empty( $data['ip_address'] ) ) {
			$meta_html .= '<p style="color:#999;font-size:12px;">IP: ' . esc_html( $data['ip_address'] ) . '</p>';
		}
		if ( ! empty( $data['page_url'] ) ) {
			$meta_html .= '<p style="color:#999;font-size:12px;">' . __( 'Страница:', 'contact-form-submissions' ) . ' <a href="' . esc_url( $data['page_url'] ) . '">' . esc_html( $data['page_url'] ) . '</a></p>';
		}

		$body = sprintf(
			'<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;">
			<h2 style="background:#0073aa;color:#fff;padding:15px 20px;margin:0;">%s</h2>
			<div style="padding:20px;">
				<table style="width:100%%;border-collapse:collapse;">%s</table>
				<p style="margin-top:20px;">
					<a href="%s" style="background:#0073aa;color:#fff;padding:8px 16px;text-decoration:none;border-radius:3px;">%s</a>
				</p>
				%s
			</div>
			</body></html>',
			/* translators: %s: form ID */
			sprintf( esc_html__( 'Новая заявка — форма %s', 'contact-form-submissions' ), esc_html( $data['form_id'] ?? 'default' ) ),
			$fields_html,
			esc_url( $admin_url ),
			esc_html__( 'Просмотреть в панели', 'contact-form-submissions' ),
			$meta_html
		);

		return apply_filters( 'cfs_email_body', $body, $data );
	}
}
