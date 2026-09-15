<?php
/**
 * Post-submit action pipeline: mail, integrations, response.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Action_Runner
 *
 * Runs after the submission is stored, never before: an integration that
 * cannot reach its remote end must not cost the site a lead. Every result is
 * written to the submission's action log and shown on its admin card.
 */
class CFS_Action_Runner {

	/**
	 * Cron hook for deferred actions.
	 */
	const CRON_HOOK = 'cfs_run_deferred_action';

	/**
	 * Meta key holding the action log.
	 */
	const META_LOG = '_cfs_actions';

	/**
	 * Delay before each retry, in seconds.
	 */
	const RETRY_DELAYS = array( 60, 300, 1800 );

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
	}

	/**
	 * Register the cron callback and the admin panel.
	 *
	 * Kept out of the constructor so that creating a runner to execute one
	 * submission does not add a second copy of every hook.
	 */
	public function register_hooks(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_deferred' ), 10, 3 );
		add_filter( 'cfs_submission_panels', array( $this, 'register_panel' ), 20, 2 );
	}

	/**
	 * Run every action configured for a form.
	 *
	 * @param CFS_Form $form          Form.
	 * @param array    $data          Submission data.
	 * @param int      $submission_id Submission ID, 0 when storage is disabled.
	 * @return array Response overrides contributed by the actions.
	 */
	public function run( CFS_Form $form, array $data, int $submission_id ): array {
		$overrides = array();
		$mailer    = new CFS_Mailer();

		foreach ( array( 'admin', 'autoreply' ) as $slot ) {
			$result = $mailer->send( $form, $slot, $data, $submission_id );

			if ( ! $result->is_ok() && CFS_Action_Result::STATUS_SKIPPED === $result->status ) {
				continue; // Disabled letters leave no trace.
			}

			$this->log(
				$submission_id,
				'mail_' . $slot,
				'admin' === $slot
					? __( 'Письмо администратору', 'contact-form-submissions' )
					: __( 'Автоответ отправителю', 'contact-form-submissions' ),
				$result
			);
		}

		foreach ( CFS_Integrations::enabled_for( $form ) as $id => $item ) {
			if ( ! empty( $item['deferred'] ) && $submission_id > 0 ) {
				$this->schedule( $submission_id, (string) $id, 1 );

				$this->log(
					$submission_id,
					(string) $id,
					(string) $item['label'],
					CFS_Action_Result::skipped( __( 'В очереди на отправку…', 'contact-form-submissions' ) )
				);
				continue;
			}

			$result = $this->run_integration( $item, $form, $data, $submission_id );
			$this->log( $submission_id, (string) $id, (string) $item['label'], $result );
		}

		/**
		 * Filter the response overrides contributed by post-submit actions.
		 *
		 * @param array    $overrides     Keys understood by the front-end script.
		 * @param CFS_Form $form          Form.
		 * @param array    $data          Submission data.
		 * @param int      $submission_id Submission ID.
		 */
		return (array) apply_filters( 'cfs_action_response', $overrides, $form, $data, $submission_id );
	}

	/**
	 * Execute one integration handler.
	 *
	 * @param array    $item          Integration descriptor.
	 * @param CFS_Form $form          Form.
	 * @param array    $data          Submission data.
	 * @param int      $submission_id Submission ID.
	 * @return CFS_Action_Result
	 */
	private function run_integration( array $item, CFS_Form $form, array $data, int $submission_id ): CFS_Action_Result {
		if ( ! is_callable( $item['run'] ) ) {
			return CFS_Action_Result::failure( __( 'Интеграция не задала обработчик.', 'contact-form-submissions' ) );
		}

		$state = CFS_Integrations::form_settings( $form, (string) $item['id'] );

		try {
			$returned = call_user_func( $item['run'], $data, $state['settings'], $submission_id, $form );
		} catch ( Throwable $e ) {
			// An add-on throwing must not take the request down with it.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'CFS integration "%s" threw: %s', $item['id'], $e->getMessage() ) );

			return CFS_Action_Result::failure( $e->getMessage(), true );
		}

		return CFS_Action_Result::normalize( $returned );
	}

	/**
	 * Queue a deferred integration.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $action_id     Integration id.
	 * @param int    $attempt       1-based attempt number.
	 */
	private function schedule( int $submission_id, string $action_id, int $attempt ): void {
		// end() takes its argument by reference, and a class constant is not a
		// variable — that is a fatal at compile time on PHP 7.2.
		$delays = self::RETRY_DELAYS;
		$delay  = $delays[ $attempt - 1 ] ?? $delays[ count( $delays ) - 1 ];

		// The first run should not wait a full minute; only retries back off.
		if ( 1 === $attempt ) {
			$delay = 5;
		}

		wp_schedule_single_event(
			time() + $delay,
			self::CRON_HOOK,
			array( $submission_id, $action_id, $attempt )
		);
	}

	/**
	 * Cron callback: run one deferred integration.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $action_id     Integration id.
	 * @param int    $attempt       1-based attempt number.
	 */
	public function run_deferred( $submission_id, $action_id, $attempt = 1 ): void {
		$submission_id = (int) $submission_id;
		$action_id     = (string) $action_id;
		$attempt       = max( 1, (int) $attempt );

		$row = $this->db->get_submission( $submission_id );
		if ( ! $row ) {
			return;
		}

		$form = CFS_Form::load( (int) $row->form_post_id );
		if ( ! $form ) {
			return;
		}

		$item = CFS_Integrations::get( $action_id );
		if ( null === $item ) {
			return;
		}

		$data   = (array) json_decode( (string) $row->form_data_json, true );
		$result = $this->run_integration( $item, $form, $data, $submission_id );

		if ( $result->is_failed() && $result->retryable && $attempt < count( self::RETRY_DELAYS ) ) {
			$this->schedule( $submission_id, $action_id, $attempt + 1 );

			$result = CFS_Action_Result::failure(
				sprintf(
					/* translators: 1: error message, 2: attempt number */
					__( '%1$s Повтор через некоторое время (попытка %2$d).', 'contact-form-submissions' ),
					$result->message,
					$attempt
				),
				true,
				$result->data
			);
		}

		$this->log( $submission_id, $action_id, (string) $item['label'], $result, $attempt );
	}

	/**
	 * Write one entry into the action log.
	 *
	 * @param int               $submission_id Submission ID.
	 * @param string            $action_id     Action id.
	 * @param string            $label         Human-readable action name.
	 * @param CFS_Action_Result $result        Result.
	 * @param int               $attempt       Attempt number.
	 */
	private function log( int $submission_id, string $action_id, string $label, CFS_Action_Result $result, int $attempt = 1 ): void {
		if ( $submission_id <= 0 ) {
			return; // Nothing to attach the log to.
		}

		$log = $this->get_log( $submission_id );

		$log[ $action_id ] = array_merge(
			$result->to_array(),
			array(
				'label'   => $label,
				'attempt' => $attempt,
				'time'    => current_time( 'mysql' ),
			)
		);

		$this->db->update_meta( $submission_id, self::META_LOG, $log );
	}

	/**
	 * Read the action log.
	 *
	 * @param int $submission_id Submission ID.
	 * @return array
	 */
	private function get_log( int $submission_id ): array {
		$log = $this->db->get_meta( $submission_id, self::META_LOG, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Add the action log to the submission detail screen.
	 *
	 * @param array  $panels     Existing panels.
	 * @param object $submission Submission row.
	 * @return array
	 */
	public function register_panel( array $panels, $submission ): array {
		if ( ! is_object( $submission ) || empty( $submission->id ) ) {
			return $panels;
		}

		$log = $this->get_log( (int) $submission->id );
		if ( empty( $log ) ) {
			return $panels;
		}

		$rows = array();
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$status = (string) ( $entry['status'] ?? '' );

			$rows[] = array(
				'label' => (string) ( $entry['label'] ?? '' ),
				'value' => $this->status_text( $status ) . ( '' !== (string) ( $entry['message'] ?? '' ) ? ' — ' . $entry['message'] : '' ),
				'color' => $this->status_color( $status ),
				'small' => true,
			);
		}

		$panels[] = array(
			'id'       => 'cfs-actions',
			'title'    => __( 'Действия после отправки', 'contact-form-submissions' ),
			'context'  => 'side',
			'priority' => 30,
			'rows'     => $rows,
		);

		return $panels;
	}

	/**
	 * Human-readable status.
	 *
	 * @param string $status Status constant.
	 * @return string
	 */
	private function status_text( string $status ): string {
		switch ( $status ) {
			case CFS_Action_Result::STATUS_OK:
				return __( 'Выполнено', 'contact-form-submissions' );
			case CFS_Action_Result::STATUS_FAILED:
				return __( 'Ошибка', 'contact-form-submissions' );
			default:
				return __( 'Пропущено', 'contact-form-submissions' );
		}
	}

	/**
	 * Colour for a status.
	 *
	 * @param string $status Status constant.
	 * @return string
	 */
	private function status_color( string $status ): string {
		switch ( $status ) {
			case CFS_Action_Result::STATUS_OK:
				return '#00a32a';
			case CFS_Action_Result::STATUS_FAILED:
				return '#d63638';
			default:
				return '#787c82';
		}
	}
}
