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
 *
 * Order of checks (never reorder, never skip):
 *
 *   1. nonce
 *   2. honeypot            cfs_hp_w / cfs_hp_x must be empty
 *   3. timestamp           on screen between 3 seconds and a day
 *   4. HTTP referer        when the browser sent one
 *   5. rate limiting       recorded BEFORE validation
 *   6. form lookup         a published form must own the posted id
 *   7. collect + sanitise  strictly the fields of that form's schema
 *   8. validation          required, formats, option whitelists, constraints
 *   9. anti-spam           banned words and the cfs_spam_check filter
 *
 * Step 6 comes before the data is read because the schema *is* the whitelist:
 * anything not described by the form is dropped rather than stored.
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

		add_action( 'wp_ajax_cfs_refresh_nonce', array( $this, 'refresh_nonce' ) );
		add_action( 'wp_ajax_nopriv_cfs_refresh_nonce', array( $this, 'refresh_nonce' ) );
	}

	/**
	 * Issue a fresh submit nonce.
	 *
	 * A nonce baked into a page that a full-page cache then serves for hours
	 * eventually expires, and every visitor of that cached page gets a failed
	 * submission with no way to recover. The script asks this endpoint for a
	 * new one and retries once.
	 */
	public function refresh_nonce(): void {
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'cfs_submit_form' ) ) );
	}

	/**
	 * Main AJAX handler.
	 */
	public function handle_submission(): void {
		// ── 1. Nonce ────────────────────────────────────────────────────────
		if ( ! check_ajax_referer( 'cfs_submit_form', 'nonce', false ) ) {
			$this->fail( 'nonce', __( 'Сессия устарела. Обновите страницу и попробуйте снова.', 'contact-form-submissions' ) );
		}

		// ── 2. Honeypot ─────────────────────────────────────────────────────
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$hp_w = isset( $_POST['cfs_hp_w'] ) ? (string) $_POST['cfs_hp_w'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$hp_x = isset( $_POST['cfs_hp_x'] ) ? (string) $_POST['cfs_hp_x'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( '' !== $hp_w || '' !== $hp_x ) {
			$this->fail( 'honeypot', __( 'Ошибка валидации.', 'contact-form-submissions' ) );
		}

		// ── 3. Timestamp ────────────────────────────────────────────────────
		// A missing or zero value is rejected outright: "time() - 0" is a huge
		// number, so omitting the field used to sail straight past this check.
		$timestamp = isset( $_POST['cfs_timestamp'] ) ? (int) $_POST['cfs_timestamp'] : 0;
		$age       = time() - $timestamp;

		if ( $timestamp <= 0 || $age < 3 || $age > DAY_IN_SECONDS ) {
			$this->fail( 'timing', __( 'Слишком быстрая отправка. Подождите.', 'contact-form-submissions' ) );
		}

		// ── 4. Referer ──────────────────────────────────────────────────────
		// Only enforced when the browser actually sent one: privacy settings,
		// extensions and a "no-referrer" policy legitimately strip the header,
		// and rejecting those visitors breaks the form for them.
		$referer = wp_get_referer();
		if ( $referer ) {
			$referer_host  = (string) wp_parse_url( $referer, PHP_URL_HOST );
			$allowed_hosts = array_filter(
				array(
					(string) wp_parse_url( get_site_url(), PHP_URL_HOST ),
					(string) wp_parse_url( home_url(), PHP_URL_HOST ),
				)
			);

			/**
			 * Filter the hostnames accepted as a submission source.
			 *
			 * @param array  $allowed_hosts Accepted hostnames.
			 * @param string $referer_host  Hostname from the Referer header.
			 */
			$allowed_hosts = (array) apply_filters( 'cfs_allowed_referer_hosts', $allowed_hosts, $referer_host );

			if ( ! in_array( $referer_host, $allowed_hosts, true ) ) {
				$this->fail( 'referer', __( 'Недопустимый источник запроса.', 'contact-form-submissions' ) );
			}
		}

		// ── 5. Rate limiting ────────────────────────────────────────────────
		// The attempt is recorded here rather than after a successful save, so
		// submissions failing validation still count against the limit.
		$ip      = $this->get_client_ip();
		$form_id = isset( $_POST['cfs_form_id'] ) ? (int) $_POST['cfs_form_id'] : 0;

		if ( apply_filters( 'cfs_rate_limit', $this->db->is_rate_limited( $ip ), $ip, $form_id ) ) {
			$this->fail( 'rate_limit', __( 'Слишком много попыток. Попробуйте позже.', 'contact-form-submissions' ) );
		}
		$this->db->record_rate_limit( $ip );

		// ── 6. Form lookup ──────────────────────────────────────────────────
		$form = $form_id > 0 ? CFS_Form::load( $form_id ) : null;

		if ( null === $form || 'publish' !== get_post_status( $form_id ) || ! $form->is_renderable() ) {
			$this->fail( 'unknown_form', __( 'Неверный идентификатор формы.', 'contact-form-submissions' ) );
		}

		$after = $form->get_after();

		// A page cached before the form was edited posts the old hash. The
		// submission is still processed — rejecting it would break every
		// visitor holding an open page the moment someone saves an edit — but
		// it is validated against the current schema.
		$posted_hash = isset( $_POST['cfs_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['cfs_hash'] ) ) : '';
		$stale       = '' !== $posted_hash && $posted_hash !== $form->get_hash();

		// ── 7. Collect and sanitise, driven by the schema ────────────────────
		$posted = isset( $_POST['cfs'] ) && is_array( $_POST['cfs'] )
			? wp_unslash( $_POST['cfs'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field type below.
			: array();

		$values = array();
		foreach ( $form->get_fields() as $name => $field ) {
			if ( empty( $field['submits'] ) ) {
				continue;
			}

			$raw = $posted[ $name ] ?? ( ! empty( $field['multiple'] ) ? array() : '' );

			$values[ $name ] = CFS_Field_Types::sanitize( (string) $field['type'], $raw );
		}

		// ── 8. Validation ───────────────────────────────────────────────────
		$errors = array();
		foreach ( $form->get_fields() as $name => $field ) {
			if ( empty( $field['submits'] ) ) {
				continue;
			}

			$error = CFS_Field_Types::validate( $field, $values[ $name ] );

			/**
			 * Filter the validation result for one field.
			 *
			 * @param string $error   Error message, '' when valid.
			 * @param string $name    Field name.
			 * @param mixed  $value   Sanitised value.
			 * @param int    $form_id Form post ID.
			 */
			$error = (string) apply_filters( 'cfs_validate_field', $error, $name, $values[ $name ], $form_id );

			if ( '' !== $error ) {
				$errors[ $name ] = $error;
			}
		}

		if ( ! empty( $errors ) ) {
			$this->fail(
				'validation',
				$this->message( $after, 'validation', __( 'Пожалуйста, исправьте ошибки в форме.', 'contact-form-submissions' ) ),
				array( 'errors' => $errors )
			);
		}

		// ── 9. Anti-spam ────────────────────────────────────────────────────
		if ( $this->has_banned_words( $values ) ) {
			$this->fail( 'spam', $this->message( $after, 'spam', __( 'Ваше сообщение было отклонено.', 'contact-form-submissions' ) ) );
		}

		// ── Build the submission ────────────────────────────────────────────
		$data = $this->build_submission_data( $form, $values, $ip, $stale );

		/**
		 * Filter the submission data before it is stored.
		 *
		 * @param array $data    Submission data.
		 * @param int   $form_id Form post ID.
		 */
		$data = (array) apply_filters( 'cfs_before_save', $data, $form_id );

		/**
		 * Final spam verdict.
		 *
		 * @param bool  $is_spam Whether the submission is spam.
		 * @param array $data    Submission data.
		 * @param int   $form_id Form post ID.
		 */
		if ( (bool) apply_filters( 'cfs_spam_check', false, $data, $form_id ) ) {
			$this->fail( 'spam', $this->message( $after, 'spam', __( 'Ваше сообщение было отклонено.', 'contact-form-submissions' ) ) );
		}

		// ── Store ───────────────────────────────────────────────────────────
		$settings      = $form->get_settings();
		$submission_id = 0;

		if ( ! empty( $settings['save_to_db'] ) ) {
			$submission_id = (int) $this->db->insert_submission( $data );

			if ( ! $submission_id ) {
				$this->fail(
					'save_failed',
					$this->message( $after, 'server', __( 'Ошибка при сохранении данных. Попробуйте позже.', 'contact-form-submissions' ) )
				);
			}
		}

		/**
		 * Fires after a submission has been stored.
		 *
		 * Runs after the database write on purpose: an add-on talking to an
		 * external system can fail without ever costing the site a lead.
		 *
		 * @param int   $submission_id Submission ID, 0 when storage is disabled.
		 * @param array $data          Submission data.
		 */
		do_action( 'cfs_after_save', $submission_id, $data );

		// Mail and integrations. A failure here is logged against the
		// submission and never shown to the visitor — the lead is already safe.
		$runner    = new CFS_Action_Runner( $this->db );
		$overrides = $runner->run( $form, $data, $submission_id );

		wp_send_json_success( array_merge( $this->build_success_response( $after, $data ), $overrides ) );
	}

	/**
	 * Assemble the stored submission array.
	 *
	 * @param CFS_Form $form   Form.
	 * @param array    $values Sanitised values keyed by field name.
	 * @param string   $ip     Client IP.
	 * @param bool     $stale  Whether the page posted an outdated schema hash.
	 * @return array
	 */
	private function build_submission_data( CFS_Form $form, array $values, string $ip, bool $stale ): array {
		$fields = array();
		$schema = array();
		$extra  = array();
		$roles  = array(
			'name'    => '',
			'email'   => '',
			'phone'   => '',
			'comment' => '',
		);

		foreach ( $form->get_fields() as $name => $field ) {
			if ( empty( $field['submits'] ) ) {
				continue;
			}

			$value = $values[ $name ];

			// Phone numbers are stored as digits only so that two spellings of
			// the same number stay comparable; the mask is reapplied on display.
			if ( 'phone' === $field['type'] && is_string( $value ) && '' !== $value ) {
				$value = (string) preg_replace( '/\D/', '', $value );
			}

			$flat = is_array( $value ) ? implode( ',', $value ) : (string) $value;

			// The stored label is only ever shown in the admin card, an email
			// or a CSV cell, so the markup an agreement label carries for the
			// form itself is stripped here rather than at every read site.
			$label = wp_strip_all_tags( (string) $field['label'] );

			$fields[] = array(
				'name'    => $name,
				'type'    => (string) $field['type'],
				'label'   => $label,
				'value'   => $value,
				'display' => CFS_Field_Types::display( $field, $value ),
			);

			$schema[] = array(
				'token' => $name,
				'type'  => (string) $field['type'],
				'label' => $label,
			);

			$role = (string) $field['role'];
			if ( '' !== $role && isset( $roles[ $role ] ) && '' === $roles[ $role ] ) {
				$roles[ $role ] = $flat;
			}

			$extra[ $name ] = $flat;
		}

		$data = array(
			'_v'           => 2,
			'form'         => array(
				'id'    => $form->get_id(),
				'title' => $form->get_title(),
				'hash'  => $form->get_hash(),
				'stale' => $stale,
			),
			'form_id'      => (string) $form->get_id(),
			'form_post_id' => $form->get_id(),
			'name'         => $roles['name'],
			'email'        => $roles['email'],
			'phone'        => $roles['phone'],
			'comment'      => $roles['comment'],
			'fields'       => $fields,
			'extra'        => $extra,
			'_schema'      => $schema,
			'page_url'     => isset( $_POST['cfs_page_url'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in handle_submission().
				? esc_url_raw( wp_unslash( $_POST['cfs_page_url'] ) )
				: '',
		);

		if ( get_option( 'cfs_save_ip', 'yes' ) === 'yes' ) {
			$data['ip_address'] = $ip;
		}

		if ( get_option( 'cfs_save_ua', 'yes' ) === 'yes' ) {
			$data['user_agent'] = isset( $_SERVER['HTTP_USER_AGENT'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
				: '';
		}

		return $data;
	}

	/**
	 * Response sent to the browser after a successful submission.
	 *
	 * @param array $after "After submit" settings.
	 * @param array $data  Submission data.
	 * @return array
	 */
	private function build_success_response( array $after, array $data ): array {
		$mode = (string) $after['mode'];

		$response = array(
			'mode'       => $mode,
			'message'    => 'redirect' === $mode ? '' : (string) $after['message'],
			'redirect'   => array(
				'url'   => in_array( $mode, array( 'redirect', 'message_redirect' ), true )
					? (string) $after['redirect_url']
					: '',
				'delay' => (int) $after['redirect_delay'],
			),
			'reset'      => (bool) $after['reset_form'],
			'scroll'     => (bool) $after['scroll_to_message'],
			'closeModal' => (bool) $after['close_modal'],
		);

		/**
		 * Filter the success response.
		 *
		 * @param array $response Response payload.
		 * @param array $data     Submission data.
		 */
		return (array) apply_filters( 'cfs_success_response', $response, $data );
	}

	/**
	 * Whether any submitted value contains a banned word.
	 *
	 * Every value is scanned, not just the comment: spam routinely hides in a
	 * secondary field, a surname or a hidden one.
	 *
	 * @param array $values Sanitised values.
	 * @return bool
	 */
	private function has_banned_words( array $values ): bool {
		$raw = (string) get_option( 'cfs_banned_words', '' );
		if ( '' === trim( $raw ) ) {
			return false;
		}

		$banned = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		if ( empty( $banned ) ) {
			return false;
		}

		$haystack = '';
		foreach ( $values as $value ) {
			$haystack .= ' ' . ( is_array( $value ) ? implode( ' ', $value ) : (string) $value );
		}

		// stripos() only folds case for ASCII, so "КАЗИНО" never matched the
		// banned word "казино" — the list was effectively case-sensitive for
		// every non-Latin alphabet.
		$has_mb = function_exists( 'mb_stripos' );

		foreach ( $banned as $word ) {
			if ( '' === $word ) {
				continue;
			}

			$found = $has_mb
				? mb_stripos( $haystack, $word ) !== false
				: stripos( $haystack, $word ) !== false;

			if ( $found ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Per-form override for a standard error message.
	 *
	 * @param array  $after   "After submit" settings.
	 * @param string $key     Message key.
	 * @param string $default Built-in text.
	 * @return string
	 */
	private function message( array $after, string $key, string $default ): string {
		$custom = (string) ( $after['errors'][ $key ] ?? '' );
		return '' !== trim( $custom ) ? $custom : $default;
	}

	/**
	 * Send a JSON error response and stop.
	 *
	 * @param string $code    Machine-readable reason.
	 * @param string $message Human-readable message.
	 * @param array  $extra   Extra payload, e.g. per-field errors.
	 */
	private function fail( string $code, string $message, array $extra = array() ): void {
		wp_send_json_error(
			array_merge(
				array(
					'code'    => $code,
					'message' => $message,
				),
				$extra
			)
		);
	}

	/**
	 * Client IP address.
	 *
	 * Only REMOTE_ADDR is trusted by default. Proxy headers such as
	 * X-Forwarded-For are attacker-controlled on any site that is not actually
	 * behind a proxy overwriting them — trusting them unconditionally lets
	 * anyone forge an IP and sidestep rate limiting entirely.
	 *
	 * Sites genuinely behind Cloudflare or a load balancer can opt back in:
	 *
	 *   add_filter( 'cfs_trusted_ip_headers', function () {
	 *       return array( 'HTTP_CF_CONNECTING_IP' );
	 *   } );
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		/**
		 * Filter the proxy headers trusted for client IP detection.
		 *
		 * @param array $headers $_SERVER keys, checked before REMOTE_ADDR.
		 */
		$trusted = (array) apply_filters( 'cfs_trusted_ip_headers', array() );

		foreach ( array_merge( $trusted, array( 'REMOTE_ADDR' ) ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$ip = trim( explode( ',', $ip )[0] );

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '0.0.0.0';
	}
}
