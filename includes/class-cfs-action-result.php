<?php
/**
 * Result of one post-submit action.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Action_Result
 *
 * Add-ons may return this object, a bool, a WP_Error or an array — normalise()
 * turns any of those into a result, so a handler written in five minutes still
 * produces a readable line in the submission's action log.
 */
class CFS_Action_Result {

	const STATUS_OK      = 'ok';
	const STATUS_FAILED  = 'failed';
	const STATUS_SKIPPED = 'skipped';

	/**
	 * Outcome.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Human-readable summary.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Extra data an add-on wants to keep, e.g. a remote record ID.
	 *
	 * @var array
	 */
	public $data;

	/**
	 * Whether a failure is worth retrying.
	 *
	 * @var bool
	 */
	public $retryable;

	/**
	 * Constructor.
	 *
	 * @param string $status    One of the STATUS_* constants.
	 * @param string $message   Summary.
	 * @param array  $data      Extra data.
	 * @param bool   $retryable Whether a retry might succeed.
	 */
	public function __construct( string $status, string $message = '', array $data = array(), bool $retryable = false ) {
		$this->status    = $status;
		$this->message   = $message;
		$this->data      = $data;
		$this->retryable = $retryable;
	}

	/**
	 * Successful result.
	 *
	 * @param string $message Summary.
	 * @param array  $data    Extra data.
	 * @return CFS_Action_Result
	 */
	public static function success( string $message = '', array $data = array() ): CFS_Action_Result {
		return new self( self::STATUS_OK, $message, $data );
	}

	/**
	 * Failed result.
	 *
	 * @param string $message   Summary.
	 * @param bool   $retryable Whether a retry might succeed.
	 * @param array  $data      Extra data.
	 * @return CFS_Action_Result
	 */
	public static function failure( string $message, bool $retryable = false, array $data = array() ): CFS_Action_Result {
		return new self( self::STATUS_FAILED, $message, $data, $retryable );
	}

	/**
	 * Nothing was done.
	 *
	 * @param string $message Summary.
	 * @return CFS_Action_Result
	 */
	public static function skipped( string $message = '' ): CFS_Action_Result {
		return new self( self::STATUS_SKIPPED, $message );
	}

	/**
	 * Whether the action succeeded.
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return self::STATUS_OK === $this->status;
	}

	/**
	 * Whether the action failed.
	 *
	 * @return bool
	 */
	public function is_failed(): bool {
		return self::STATUS_FAILED === $this->status;
	}

	/**
	 * Turn whatever a handler returned into a result.
	 *
	 * @param mixed $value Handler return value.
	 * @return CFS_Action_Result
	 */
	public static function normalize( $value ): CFS_Action_Result {
		if ( $value instanceof self ) {
			return $value;
		}

		if ( is_wp_error( $value ) ) {
			return self::failure( $value->get_error_message(), true );
		}

		if ( true === $value ) {
			return self::success();
		}

		if ( false === $value || null === $value ) {
			return self::failure( __( 'Действие не выполнено.', 'contact-form-submissions' ), true );
		}

		if ( is_array( $value ) ) {
			$ok = ! isset( $value['ok'] ) || ! empty( $value['ok'] );

			return new self(
				$ok ? self::STATUS_OK : self::STATUS_FAILED,
				(string) ( $value['message'] ?? '' ),
				isset( $value['data'] ) && is_array( $value['data'] ) ? $value['data'] : array(),
				! empty( $value['retryable'] )
			);
		}

		return self::success( (string) $value );
	}

	/**
	 * Serialisable form, for the action log.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'status'    => $this->status,
			'message'   => $this->message,
			'data'      => $this->data,
			'retryable' => $this->retryable,
		);
	}
}
