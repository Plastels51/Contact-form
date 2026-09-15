<?php
/**
 * Email notifications built from the form's own mail templates.
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
	 * Send one of the form's two letters.
	 *
	 * @param CFS_Form $form          Form.
	 * @param string   $slot          'admin' or 'autoreply'.
	 * @param array    $data          Submission data.
	 * @param int      $submission_id Submission ID, 0 when storage is off.
	 * @return CFS_Action_Result
	 */
	public function send( CFS_Form $form, string $slot, array $data, int $submission_id ): CFS_Action_Result {
		$mail = $form->get_mail();

		if ( empty( $mail[ $slot ] ) || empty( $mail[ $slot ]['enabled'] ) ) {
			return CFS_Action_Result::skipped( __( 'Отключено в настройках формы.', 'contact-form-submissions' ) );
		}

		$settings = $mail[ $slot ];
		$context  = CFS_Mail_Template::context( $data, $submission_id, $form );

		$recipients = $this->recipients( $slot, $settings, $context, $data );
		if ( empty( $recipients ) ) {
			return CFS_Action_Result::failure(
				__( 'Не удалось определить получателя.', 'contact-form-submissions' )
			);
		}

		$is_html = ! empty( $settings['html'] );
		$subject = $this->subject( $settings, $context, $data, $form );
		$body    = $this->body( $settings, $context, $data, $submission_id, $is_html );
		$headers = $this->headers( $settings, $context, $data, $is_html );

		$sent = wp_mail( $recipients, $subject, $body, $headers );

		if ( ! $sent ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'CFS Mailer: wp_mail() failed for submission #%d (%s)',
					$submission_id,
					$slot
				)
			);

			return CFS_Action_Result::failure(
				__( 'wp_mail() вернул ошибку — проверьте настройки почты сайта.', 'contact-form-submissions' ),
				true
			);
		}

		return CFS_Action_Result::success(
			sprintf(
				/* translators: %s: comma-separated recipients */
				__( 'Отправлено: %s', 'contact-form-submissions' ),
				implode( ', ', $recipients )
			),
			array( 'to' => $recipients )
		);
	}

	/**
	 * Recipients for one letter.
	 *
	 * @param string $slot     'admin' or 'autoreply'.
	 * @param array  $settings Slot settings.
	 * @param array  $context  Mail tag context.
	 * @param array  $data     Submission data.
	 * @return string[]
	 */
	private function recipients( string $slot, array $settings, array $context, array $data ): array {
		$recipients = CFS_Mail_Template::recipients( (string) $settings['to'], $context );

		if ( empty( $recipients ) && 'admin' === $slot ) {
			// No explicit list — fall back to the site admin plus whatever the
			// global settings add, which is what 2.x always did.
			$recipients[] = (string) get_option( 'admin_email' );

			foreach ( explode( ',', (string) get_option( 'cfs_extra_emails', '' ) ) as $extra ) {
				$extra = trim( $extra );
				if ( is_email( $extra ) ) {
					$recipients[] = $extra;
				}
			}
		}

		if ( empty( $recipients ) && 'autoreply' === $slot ) {
			// The visitor's own address, when the form collected one.
			foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
				if ( is_array( $field ) && 'email' === ( $field['type'] ?? '' ) && is_email( (string) $field['value'] ) ) {
					$recipients[] = (string) $field['value'];
					break;
				}
			}
		}

		$recipients = array_values( array_unique( array_filter( $recipients, 'is_email' ) ) );

		/**
		 * Filter the recipients of a notification.
		 *
		 * @param array  $recipients Email addresses.
		 * @param array  $data       Submission data.
		 * @param string $slot       'admin' or 'autoreply'.
		 */
		return (array) apply_filters( 'cfs_email_recipients', $recipients, $data, $slot );
	}

	/**
	 * Subject line.
	 *
	 * @param array    $settings Slot settings.
	 * @param array    $context  Mail tag context.
	 * @param array    $data     Submission data.
	 * @param CFS_Form $form     Form.
	 * @return string
	 */
	private function subject( array $settings, array $context, array $data, CFS_Form $form ): string {
		$template = (string) $settings['subject'];

		if ( '' === trim( $template ) ) {
			$template = (string) get_option(
				'cfs_email_subject',
				/* translators: 1: site name, 2: form title */
				__( 'Новая заявка с сайта {site_name} — форма {form_title}', 'contact-form-submissions' )
			);
		}

		// 2.x subject templates used {form_id}; keep them working now that the
		// id is a number and a title exists.
		if ( false === strpos( $template, '{form_title}' ) && false !== strpos( $template, '{form_id}' ) ) {
			$context['form_id'] = $form->get_title();
		}

		return CFS_Mail_Template::render( $template, $context, CFS_Mail_Template::MODE_SUBJECT, $data );
	}

	/**
	 * Message body.
	 *
	 * @param array $settings      Slot settings.
	 * @param array $context       Mail tag context.
	 * @param array $data          Submission data.
	 * @param int   $submission_id Submission ID.
	 * @param bool  $is_html       Whether to send HTML.
	 * @return string
	 */
	private function body( array $settings, array $context, array $data, int $submission_id, bool $is_html ): string {
		$template = (string) $settings['body'];

		if ( '' === trim( $template ) ) {
			$template = '{all_fields}';
		}

		$mode = $is_html ? CFS_Mail_Template::MODE_HTML : CFS_Mail_Template::MODE_TEXT;
		$body = CFS_Mail_Template::render( $template, $context, $mode, $data );

		if ( $is_html ) {
			$body = $this->wrap_html( $body, $context, $submission_id );
		}

		/**
		 * Filter the message body.
		 *
		 * @param string $body Rendered body.
		 * @param array  $data Submission data.
		 */
		return (string) apply_filters( 'cfs_email_body', $body, $data );
	}

	/**
	 * Wrap a rendered body in the HTML shell.
	 *
	 * @param string $body          Rendered body.
	 * @param array  $context       Mail tag context.
	 * @param int    $submission_id Submission ID.
	 * @return string
	 */
	private function wrap_html( string $body, array $context, int $submission_id ): string {
		$button = '';

		if ( $submission_id > 0 ) {
			$button = sprintf(
				'<p style="margin-top:20px;"><a href="%s" style="background:#0073aa;color:#fff;padding:8px 16px;text-decoration:none;border-radius:3px;">%s</a></p>',
				esc_url( (string) $context['admin_url'] ),
				esc_html__( 'Просмотреть в панели', 'contact-form-submissions' )
			);
		}

		$meta = '';
		if ( '' !== (string) $context['page_url'] ) {
			$meta .= sprintf(
				'<p style="color:#999;font-size:12px;">%s <a href="%s">%s</a></p>',
				esc_html__( 'Страница:', 'contact-form-submissions' ),
				esc_url( (string) $context['page_url'] ),
				esc_html( (string) $context['page_url'] )
			);
		}
		if ( '' !== (string) $context['ip'] ) {
			$meta .= sprintf( '<p style="color:#999;font-size:12px;">IP: %s</p>', esc_html( (string) $context['ip'] ) );
		}

		return sprintf(
			'<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
			. '<body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;">'
			. '<h2 style="background:#0073aa;color:#fff;padding:15px 20px;margin:0;font-size:18px;">%s</h2>'
			. '<div style="padding:20px;">%s%s%s</div></body></html>',
			esc_html( (string) $context['form_title'] ),
			$body,
			$button,
			$meta
		);
	}

	/**
	 * Mail headers.
	 *
	 * @param array $settings Slot settings.
	 * @param array $context  Mail tag context.
	 * @param array $data     Submission data.
	 * @param bool  $is_html  Whether to send HTML.
	 * @return string[]
	 */
	private function headers( array $settings, array $context, array $data, bool $is_html ): array {
		$headers = array(
			'Content-Type: ' . ( $is_html ? 'text/html' : 'text/plain' ) . '; charset=UTF-8',
		);

		/*
		 * From is the site's own address, never the visitor's: sending "From" a
		 * domain that has not authorised this server (no matching SPF/DKIM) is
		 * exactly what gets outgoing mail flagged as spoofed. The visitor's
		 * address goes in Reply-To, which carries no such restriction.
		 */
		$from_name = CFS_Mail_Template::render( (string) $settings['from_name'], $context, CFS_Mail_Template::MODE_HEADER, $data );
		if ( '' === $from_name ) {
			$from_name = (string) $context['site_name'];
		}
		$from_name = $this->clean_display_name( $from_name );

		$from_email = CFS_Mail_Template::render( (string) $settings['from_email'], $context, CFS_Mail_Template::MODE_HEADER, $data );
		if ( ! is_email( $from_email ) ) {
			$from_email = (string) get_option( 'admin_email' );
		}

		if ( '' !== $from_name && is_email( $from_email ) ) {
			$headers[] = 'From: "' . $from_name . '" <' . $from_email . '>';
		}

		$reply_to = CFS_Mail_Template::render( (string) $settings['reply_to'], $context, CFS_Mail_Template::MODE_HEADER, $data );

		if ( '' === trim( $reply_to ) ) {
			// Default to whatever the form collected as an email.
			foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
				if ( is_array( $field ) && 'email' === ( $field['type'] ?? '' ) && is_email( (string) $field['value'] ) ) {
					$reply_to = (string) $field['value'];
					break;
				}
			}
		}

		if ( is_email( $reply_to ) ) {
			$reply_name = $this->clean_display_name( (string) ( $data['name'] ?? '' ) );
			$headers[]  = '' !== $reply_name
				? 'Reply-To: "' . $reply_name . '" <' . $reply_to . '>'
				: 'Reply-To: ' . $reply_to;
		}

		foreach ( array( 'cc' => 'Cc', 'bcc' => 'Bcc' ) as $key => $header ) {
			$list = CFS_Mail_Template::recipients( (string) $settings[ $key ], $context );
			if ( ! empty( $list ) ) {
				$headers[] = $header . ': ' . implode( ', ', $list );
			}
		}

		/**
		 * Filter the mail headers.
		 *
		 * @param array $headers Headers.
		 * @param array $data    Submission data.
		 */
		return (array) apply_filters( 'cfs_email_headers', $headers, $data );
	}

	/**
	 * Strip characters that would let a display name break out of its header.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private function clean_display_name( string $name ): string {
		$name = CFS_Mail_Template::strip_newlines( wp_strip_all_tags( $name ) );

		return trim( str_replace( array( '"', '<', '>' ), '', $name ) );
	}
}
