<?php
/**
 * AJAX handler — processes form submissions with full security checks.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Ajax_Handler
 */
class CFS_Ajax_Handler {

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
		add_action( 'wp_ajax_cfs_submit_form', array( $this, 'handle_submission' ) );
		add_action( 'wp_ajax_nopriv_cfs_submit_form', array( $this, 'handle_submission' ) );
	}

	/**
	 * Main AJAX handler — runs all security checks in order.
	 */
	public function handle_submission(): void {
		// 1. Nonce.
		check_ajax_referer( 'cfs_submit_form', 'nonce' );

		// 2. Honeypot — both fields must be empty.
		$hp_w = isset( $_POST['cfs_hp_w'] ) ? $_POST['cfs_hp_w'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$hp_x = isset( $_POST['cfs_hp_x'] ) ? $_POST['cfs_hp_x'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! empty( $hp_w ) || ! empty( $hp_x ) ) {
			wp_send_json_error( array( 'message' => __( 'Ошибка валидации.', 'contact-form-submissions' ) ) );
			return;
		}

		// 3. Timestamp — at least 3 seconds.
		$timestamp = isset( $_POST['cfs_timestamp'] ) ? (int) $_POST['cfs_timestamp'] : 0;
		if ( ( time() - $timestamp ) < 3 ) {
			wp_send_json_error( array( 'message' => __( 'Слишком быстрая отправка. Подождите.', 'contact-form-submissions' ) ) );
			return;
		}

		// 4. HTTP Referer.
		$referer      = wp_get_referer();
		$site_host    = wp_parse_url( get_site_url(), PHP_URL_HOST );
		$referer_host = $referer ? wp_parse_url( $referer, PHP_URL_HOST ) : '';
		if ( $referer_host !== $site_host ) {
			wp_send_json_error( array( 'message' => __( 'Недопустимый источник запроса.', 'contact-form-submissions' ) ) );
			return;
		}

		// 5. Rate limiting.
		$ip = $this->get_client_ip();
		if ( apply_filters( 'cfs_rate_limit', $this->db->is_rate_limited( $ip ), $ip, '' ) ) {
			wp_send_json_error( array( 'message' => __( 'Слишком много попыток. Попробуйте позже.', 'contact-form-submissions' ) ) );
			return;
		}

		// 6. Sanitise inputs.
		// Extract form_id and page_url first (not in the cfs_ field loop).
		$raw_form_id = isset( $_POST['cfs_form_id'] ) ? sanitize_key( wp_unslash( $_POST['cfs_form_id'] ) ) : '';
		$page_url    = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';

		/*
		 * Collect all cfs_* POST fields dynamically.
		 *
		 * Skipped keys: system / honeypot fields that are never form data.
		 * Remaining keys are stripped of the "cfs_" prefix to get the field token
		 * (e.g. "cfs_comment_2" → "comment_2"), then the base type is derived
		 * by stripping any trailing "_N" suffix (e.g. "comment_2" → "comment").
		 *
		 * Sanitisation is applied per base type:
		 *   email   → sanitize_email()
		 *   comment → sanitize_textarea_field()
		 *   checkbox presence → hardcoded '1' (unchecked = not sent)
		 *   agreement presence → hardcoded '1' (unchecked = not sent)
		 *   all others → sanitize_text_field()
		 */
		$skip_cfs_keys = array( 'cfs_hp_w', 'cfs_hp_x', 'cfs_timestamp', 'cfs_form_id' );
		$field_data    = array();

		foreach ( $_POST as $post_key => $post_value ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( strpos( $post_key, 'cfs_' ) !== 0 ) {
				continue;
			}
			if ( in_array( $post_key, $skip_cfs_keys, true ) ) {
				continue;
			}

			$field_token = substr( sanitize_key( $post_key ), 4 ); // strip "cfs_" → e.g. "comment_2".
			if ( '' === $field_token ) {
				continue;
			}

			$base_type = (string) preg_replace( '/(_\d+)$/', '', $field_token ); // "comment_2" → "comment".

			// Multicheck sends an array of checked values; join to comma-separated string.
			if ( 'multicheck' === $base_type && is_array( $post_value ) ) {
				$sanitized_vals = array();
				foreach ( $post_value as $v ) {
					$clean = sanitize_key( wp_unslash( (string) $v ) );
					if ( '' !== $clean ) {
						$sanitized_vals[] = $clean;
					}
				}
				$field_data[ $field_token ] = implode( ',', $sanitized_vals );
				continue;
			}

			$post_value = is_array( $post_value ) ? '' : (string) $post_value;

			switch ( $base_type ) {
				case 'email':
					$field_data[ $field_token ] = sanitize_email( wp_unslash( $post_value ) );
					break;
				case 'checkbox':
				case 'agreement':
					// Checked checkboxes/agreements send value="1"; unchecked are absent from POST.
					$field_data[ $field_token ] = '1';
					break;
				case 'comment':
					$field_data[ $field_token ] = sanitize_textarea_field( wp_unslash( $post_value ) );
					break;
				case 'url':
					$field_data[ $field_token ] = esc_url_raw( wp_unslash( $post_value ) );
					break;
				default:
					$field_data[ $field_token ] = sanitize_text_field( wp_unslash( $post_value ) );
					break;
			}
		}

		// Collect non-cfs_ extra fields (e.g. custom hidden inputs).
		$extra_non_cfs = array();
		foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( strpos( $key, 'cfs_' ) !== 0 && ! in_array( $key, array( 'action', 'nonce', 'page_url' ), true ) ) {
				$extra_non_cfs[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}

		/*
		 * Retrieve cached form configuration (written by CFS_Form_Builder when
		 * the shortcode was rendered). Used in steps 7 and 8.
		 *
		 * If the transient is missing (expired / forged form_id), $form_config
		 * will be false. Step 7 skips config-dependent checks gracefully;
		 * step 8 will then reject the request.
		 */
		$form_config = ! empty( $raw_form_id )
			? get_transient( 'cfs_form_config_' . $raw_form_id )
			: false;

		// 7. Server-side validation.
		$errors = array();

		// ── Required-field validation (driven by cached form config) ────────────
		if ( is_array( $form_config ) ) {
			$form_fields  = array_map( 'trim', explode( ',', (string) $form_config['fields'] ) );
			$required_map = is_array( $form_config['required'] ) ? $form_config['required'] : array();

			foreach ( $form_fields as $field_token ) {
				if ( 'yes' !== ( $required_map[ $field_token ] ?? 'no' ) ) {
					continue; // Not required — skip.
				}

				// text fields are display-only — no value submitted, skip always.
				$field_type = isset( $form_config['field_types'][ $field_token ] )
					? (string) $form_config['field_types'][ $field_token ]
					: '';
				if ( 'text' === $field_type ) {
					continue;
				}

				$value = (string) ( $field_data[ $field_token ] ?? '' );
				if ( '' === $value ) {
					$error_msg              = __( 'Обязательное поле.', 'contact-form-submissions' );
					$errors[ $field_token ] = apply_filters(
						'cfs_validate_field',
						$error_msg,
						$field_token,
						$value,
						$raw_form_id
					);
				}
			}
		}

		// ── Name / surname / patronymic: only letters, hyphens, apostrophes ─────
		foreach ( $field_data as $field_token => $value ) {
			$base_type = (string) preg_replace( '/(_\d+)$/', '', $field_token );
			if ( ! in_array( $base_type, array( 'name', 'surname', 'patronymic' ), true ) ) {
				continue;
			}
			if ( ! empty( $value ) && ! preg_match( '/^[\p{L}\s\-\']+$/u', $value ) ) {
				$error_msg              = __( 'Некорректное значение поля.', 'contact-form-submissions' );
				$errors[ $field_token ] = apply_filters( 'cfs_validate_field', $error_msg, $field_token, $value, $raw_form_id );
			}
		}

		// ── Phone: 10–11 digits after stripping formatting ───────────────────────
		foreach ( $field_data as $field_token => $value ) {
			$base_type = (string) preg_replace( '/(_\d+)$/', '', $field_token );
			if ( 'phone' !== $base_type || empty( $value ) ) {
				continue;
			}
			$digits = (string) preg_replace( '/\D/', '', $value );
			if ( ! $digits || strlen( $digits ) < 10 || strlen( $digits ) > 11 ) {
				$error_msg              = __( 'Введите корректный номер телефона (10–11 цифр).', 'contact-form-submissions' );
				$errors[ $field_token ] = apply_filters( 'cfs_validate_field', $error_msg, $field_token, $value, $raw_form_id );
			}
		}

		// ── Email format ─────────────────────────────────────────────────────────
		foreach ( $field_data as $field_token => $value ) {
			$base_type = (string) preg_replace( '/(_\d+)$/', '', $field_token );
			if ( 'email' !== $base_type || empty( $value ) ) {
				continue;
			}
			if ( ! is_email( $value ) ) {
				$error_msg              = __( 'Введите корректный email.', 'contact-form-submissions' );
				$errors[ $field_token ] = apply_filters( 'cfs_validate_field', $error_msg, $field_token, $value, $raw_form_id );
			}
		}

		// ── Comment length ───────────────────────────────────────────────────────
		$max_comment = (int) get_option( 'cfs_max_comment_length', 1000 );
		foreach ( $field_data as $field_token => $value ) {
			$base_type = (string) preg_replace( '/(_\d+)$/', '', $field_token );
			if ( 'comment' !== $base_type || empty( $value ) ) {
				continue;
			}
			if ( mb_strlen( $value ) > $max_comment ) {
				$error_msg              = sprintf(
					/* translators: %d: max characters */
					__( 'Комментарий слишком длинный. Максимум %d символов.', 'contact-form-submissions' ),
					$max_comment
				);
				$errors[ $field_token ] = apply_filters( 'cfs_validate_field', $error_msg, $field_token, $value, $raw_form_id );
			}
		}

		// ── Select: whitelist — only values registered in the shortcode ──────────
		if ( is_array( $form_config ) && ! empty( $form_config['select_options'] ) ) {
			$allowed_vals = array();
			foreach ( explode( ',', (string) $form_config['select_options'] ) as $opt ) {
				$parts = explode( ':', trim( $opt ), 2 );
				if ( 2 === count( $parts ) ) {
					$allowed_vals[] = trim( $parts[1] );
				}
			}
			foreach ( $field_data as $field_token => $value ) {
				$base_type = (string) preg_replace( '/(_\d+)$/', '', $field_token );
				if ( 'select' !== $base_type || empty( $value ) ) {
					continue;
				}
				if ( ! in_array( $value, $allowed_vals, true ) ) {
					$error_msg              = __( 'Недопустимое значение.', 'contact-form-submissions' );
					$errors[ $field_token ] = apply_filters( 'cfs_validate_field', $error_msg, $field_token, $value, $raw_form_id );
				}
			}
		}

		// ── Radio: value whitelist ─── only registered option values allowed ───
		if ( is_array( $form_config ) && ! empty( $form_config['radio_options_map'] ) ) {
			$radio_map = (array) $form_config['radio_options_map'];
			foreach ( $field_data as $field_token => $value ) {
				$base_type = (string) preg_replace( '/(_\d+)$/', '', $field_token );
				if ( 'radio' !== $base_type || empty( $value ) ) {
					continue;
				}
				if ( ! isset( $radio_map[ $field_token ] ) ) {
					continue;
				}
				$allowed_vals = array();
				foreach ( explode( ',', (string) $radio_map[ $field_token ] ) as $opt ) {
					$parts = explode( ':', trim( $opt ), 2 );
					if ( 2 === count( $parts ) ) {
						$allowed_vals[] = trim( $parts[1] );
					}
				}
				if ( ! in_array( $value, $allowed_vals, true ) ) {
					$error_msg              = __( 'Недопустимое значение.', 'contact-form-submissions' );
					$errors[ $field_token ] = apply_filters( 'cfs_validate_field', $error_msg, $field_token, $value, $raw_form_id );
				}
			}
		}

		// ── Multicheck: each selected value must be in the whitelist ────────────
		if ( is_array( $form_config ) && ! empty( $form_config['multicheck_options_map'] ) ) {
			$mcheck_map = (array) $form_config['multicheck_options_map'];
			foreach ( $field_data as $field_token => $value ) {
				$base_type = (string) preg_replace( '/(_\d+)$/', '', $field_token );
				if ( 'multicheck' !== $base_type || empty( $value ) ) {
					continue;
				}
				if ( ! isset( $mcheck_map[ $field_token ] ) ) {
					continue;
				}
				$allowed_vals = array();
				foreach ( explode( ',', (string) $mcheck_map[ $field_token ] ) as $opt ) {
					$parts = explode( ':', trim( $opt ), 2 );
					if ( 2 === count( $parts ) ) {
						$allowed_vals[] = sanitize_key( trim( $parts[1] ) );
					}
				}
				foreach ( explode( ',', $value ) as $selected ) {
					if ( ! in_array( $selected, $allowed_vals, true ) ) {
						$error_msg              = __( 'Недопустимое значение.', 'contact-form-submissions' );
						$errors[ $field_token ] = apply_filters( 'cfs_validate_field', $error_msg, $field_token, $value, $raw_form_id );
						break;
					}
				}
			}
		}

		// ── Date: format + optional min/max ──────────────────────────────────────
		// ── Number: numeric + optional min/max/step ───────────────────────────────
		if ( is_array( $form_config ) && ! empty( $form_config['constraints'] ) ) {
			foreach ( (array) $form_config['constraints'] as $field_token => $constraint ) {
				$value     = (string) ( $field_data[ $field_token ] ?? '' );
				$con_type  = (string) ( $constraint['type'] ?? '' );

				if ( '' === $value ) {
					continue; // Empty optional field — skip.
				}

				if ( 'date' === $con_type ) {
					// Validate Y-m-d format.
					$date_obj = \DateTime::createFromFormat( 'Y-m-d', $value );
					if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $value ) {
						$errors[ $field_token ] = __( 'Некорректный формат даты.', 'contact-form-submissions' );
						continue;
					}
					if ( ! empty( $constraint['min'] ) && $value < $constraint['min'] ) {
						/* translators: %s: minimum date */
						$errors[ $field_token ] = sprintf( __( 'Дата не может быть раньше %s.', 'contact-form-submissions' ), $constraint['min'] );
					} elseif ( ! empty( $constraint['max'] ) && $value > $constraint['max'] ) {
						/* translators: %s: maximum date */
						$errors[ $field_token ] = sprintf( __( 'Дата не может быть позже %s.', 'contact-form-submissions' ), $constraint['max'] );
					}
				} elseif ( 'number' === $con_type ) {
					if ( ! is_numeric( $value ) ) {
						$errors[ $field_token ] = __( 'Введите числовое значение.', 'contact-form-submissions' );
						continue;
					}
					$num = (float) $value;
					$con_min  = $constraint['min'] ?? '';
					$con_max  = $constraint['max'] ?? '';
					if ( '' !== $con_min && $num < (float) $con_min ) {
						/* translators: %s: minimum number */
						$errors[ $field_token ] = sprintf( __( 'Минимальное значение: %s.', 'contact-form-submissions' ), $con_min );
					} elseif ( '' !== $con_max && $num > (float) $con_max ) {
						/* translators: %s: maximum number */
						$errors[ $field_token ] = sprintf( __( 'Максимальное значение: %s.', 'contact-form-submissions' ), $con_max );
					}
				}
			}
		}


		// ── Banned words → mark as spam ─────────────────────────────────────────
		$banned_words_raw = get_option( 'cfs_banned_words', '' );
		if ( ! empty( $banned_words_raw ) ) {
			$banned           = array_filter( array_map( 'trim', explode( "\n", (string) $banned_words_raw ) ) );
			$content_to_check = implode( ' ', array(
				$field_data['name']    ?? '',
				$field_data['comment'] ?? '',
				$field_data['email']   ?? '',
			) );
			foreach ( $banned as $word ) {
				if ( $word && stripos( $content_to_check, $word ) !== false ) {
					$is_spam = apply_filters( 'cfs_spam_check', true, array(), $raw_form_id );
					if ( $is_spam ) {
						wp_send_json_error( array( 'message' => __( 'Ваше сообщение было отклонено.', 'contact-form-submissions' ) ) );
						return;
					}
				}
			}
		}

		// Return all validation errors at once so the client can highlight fields.
		if ( ! empty( $errors ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Пожалуйста, исправьте ошибки в форме.', 'contact-form-submissions' ),
					'errors'  => $errors,
				)
			);
			return;
		}

		// 8. form_id validation — must exist in the transient cache.
		// A missing transient means the form was never rendered by this plugin
		// (expired session, forged request, etc.).
		if ( empty( $raw_form_id ) || false === $form_config ) {
			wp_send_json_error( array( 'message' => __( 'Неверный идентификатор формы.', 'contact-form-submissions' ) ) );
			return;
		}

		/*
		 * Build submission data.
		 *
		 * Primary fields (name, surname, patronymic, phone, email, comment,
		 * select, checkbox) map to dedicated DB columns.
		 *
		 * Indexed variants (name_2, comment_3, …) and non-cfs_ custom fields
		 * go into the 'extra' sub-array which is stored in form_data_json.
		 */
		$primary_fields = array( 'name', 'surname', 'patronymic', 'phone', 'email', 'comment', 'select', 'checkbox' );

		$data = array(
			'form_id'    => $raw_form_id,
			'name'       => $field_data['name'] ?? '',
			'surname'    => $field_data['surname'] ?? '',
			'patronymic' => $field_data['patronymic'] ?? '',
			'phone'      => isset( $field_data['phone'] ) && $field_data['phone']
				? (string) preg_replace( '/\D/', '', $field_data['phone'] )
				: '',
			'email'      => $field_data['email'] ?? '',
			'comment'    => $field_data['comment'] ?? '',
			'select'     => $field_data['select'] ?? '',
			'checkbox'   => $field_data['checkbox'] ?? '',
			'page_url'   => $page_url,
			'extra'      => array(),
		);

		// Indexed/secondary field instances go into extra, with phone digits stripped.
		foreach ( $field_data as $field_token => $value ) {
			if ( in_array( $field_token, $primary_fields, true ) ) {
				continue; // Already mapped to a primary column above.
			}
			$base_type = (string) preg_replace( '/(_\d+)$/', '', $field_token );
			if ( 'phone' === $base_type && $value ) {
				$value = (string) preg_replace( '/\D/', '', $value );
			}
			$data['extra'][ $field_token ] = $value;
		}

		// Merge non-cfs_ custom fields into extra.
		foreach ( $extra_non_cfs as $key => $value ) {
			$data['extra'][ $key ] = $value;
		}

		// Save IP and UA per settings.
		if ( get_option( 'cfs_save_ip', 'yes' ) === 'yes' ) {
			$data['ip_address'] = $ip;
		}
		if ( get_option( 'cfs_save_ua', 'yes' ) === 'yes' ) {
			$data['user_agent'] = isset( $_SERVER['HTTP_USER_AGENT'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
				: '';
		}

		// Allow external modification before save.
		$data = apply_filters( 'cfs_before_save', $data, $raw_form_id );

		// Check spam filter result.
		$is_spam = apply_filters( 'cfs_spam_check', false, $data, $raw_form_id );
		if ( $is_spam ) {
			wp_send_json_error( array( 'message' => __( 'Ваше сообщение было отклонено.', 'contact-form-submissions' ) ) );
			return;
		}

		// Save to DB.
		$submission_id = $this->db->insert_submission( $data );
		if ( ! $submission_id ) {
			wp_send_json_error( array( 'message' => __( 'Ошибка при сохранении данных. Попробуйте позже.', 'contact-form-submissions' ) ) );
			return;
		}

		// Record rate limit entry.
		$this->db->record_rate_limit( $ip );

		// Fire post-save action.
		do_action( 'cfs_after_save', $submission_id, $data );

		// Send email notification.
		$mailer = new CFS_Mailer();
		$mailer->send_notification( $data, $submission_id );

		$response = array(
			'message' => __( 'Спасибо! Мы свяжемся с вами.', 'contact-form-submissions' ),
		);

		$response = apply_filters( 'cfs_success_response', $response, $data );

		wp_send_json_success( $response );
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$keys = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// For comma-separated (X-Forwarded-For), take the first.
				$ip = trim( explode( ',', $ip )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}
}
