<?php
/**
 * Mail templates — {tag} substitution with context-aware escaping.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Mail_Template
 *
 * Escaping depends on where a value lands:
 *
 *   header — newlines removed, or a submitted value could inject extra mail
 *            headers and turn a notification into an open relay;
 *   subject — tags stripped, newlines removed;
 *   html    — escaped and line breaks converted;
 *   text    — used as-is.
 */
class CFS_Mail_Template {

	const MODE_HEADER  = 'header';
	const MODE_SUBJECT = 'subject';
	const MODE_HTML    = 'html';
	const MODE_TEXT    = 'text';

	/**
	 * Build the substitution context for one submission.
	 *
	 * @param array    $data          Submission data.
	 * @param int      $submission_id Submission ID (0 when not stored).
	 * @param CFS_Form $form          Form the submission came from.
	 * @return array<string, string>
	 */
	public static function context( array $data, int $submission_id, CFS_Form $form ): array {
		$context = array();

		foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}
			$context[ (string) $field['name'] ]            = (string) ( $field['display'] ?? '' );
			$context[ (string) $field['name'] . '_label' ] = (string) ( $field['label'] ?? '' );
			$context[ (string) $field['name'] . '_raw' ]   = is_array( $field['value'] ?? '' )
				? implode( ', ', (array) $field['value'] )
				: (string) ( $field['value'] ?? '' );
		}

		$context['site_name']     = (string) wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$context['site_url']      = home_url( '/' );
		$context['form_title']    = $form->get_title();
		$context['form_id']       = (string) $form->get_id();
		$context['submission_id'] = (string) $submission_id;
		$context['admin_url']     = $submission_id > 0
			? admin_url( 'admin.php?page=cfs-submissions&action=view&id=' . $submission_id )
			: admin_url( 'admin.php?page=cfs-submissions' );
		$context['date']          = date_i18n( 'd.m.Y H:i', current_time( 'timestamp' ) );
		$context['ip']            = (string) ( $data['ip_address'] ?? '' );
		$context['user_agent']    = (string) ( $data['user_agent'] ?? '' );
		$context['page_url']      = (string) ( $data['page_url'] ?? '' );

		/**
		 * Filter the mail tag context.
		 *
		 * @param array    $context Tag => value.
		 * @param array    $data    Submission data.
		 * @param CFS_Form $form    Form.
		 */
		return (array) apply_filters( 'cfs_mail_context', $context, $data, $form );
	}

	/**
	 * Substitute {tags} in a template.
	 *
	 * @param string $template Template text.
	 * @param array  $context  Tag => value.
	 * @param string $mode     One of the MODE_* constants.
	 * @param array  $data     Submission data, for {all_fields}.
	 * @return string
	 */
	public static function render( string $template, array $context, string $mode, array $data = array() ): string {
		$rendered = (string) preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			static function ( array $matches ) use ( $context, $mode, $data ): string {
				$tag = strtolower( $matches[1] );

				if ( 'all_fields' === $tag ) {
					return self::all_fields( $data, $mode );
				}

				if ( ! array_key_exists( $tag, $context ) ) {
					// Unknown tag: leave it visible rather than silently blank,
					// so a typo in the template is obvious in the first email.
					return $matches[0];
				}

				return self::escape( (string) $context[ $tag ], $mode );
			},
			$template
		);

		if ( self::MODE_HTML === $mode ) {
			return $rendered;
		}

		if ( self::MODE_HEADER === $mode || self::MODE_SUBJECT === $mode ) {
			return self::strip_newlines( $rendered );
		}

		return $rendered;
	}

	/**
	 * Escape one value for its destination.
	 *
	 * @param string $value Raw value.
	 * @param string $mode  Destination mode.
	 * @return string
	 */
	private static function escape( string $value, string $mode ): string {
		switch ( $mode ) {
			case self::MODE_HEADER:
			case self::MODE_SUBJECT:
				return self::strip_newlines( wp_strip_all_tags( $value ) );

			case self::MODE_HTML:
				return nl2br( esc_html( $value ) );

			case self::MODE_TEXT:
			default:
				return wp_strip_all_tags( $value );
		}
	}

	/**
	 * Remove everything that could start a new mail header.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function strip_newlines( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $value ) );
	}

	/**
	 * The {all_fields} block.
	 *
	 * @param array  $data Submission data.
	 * @param string $mode Destination mode.
	 * @return string
	 */
	private static function all_fields( array $data, string $mode ): string {
		$fields = (array) ( $data['fields'] ?? array() );

		if ( empty( $fields ) ) {
			return '';
		}

		if ( self::MODE_HTML !== $mode ) {
			$lines = array();
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || 'hidden' === ( $field['type'] ?? '' ) ) {
					continue;
				}
				$lines[] = sprintf(
					'%s: %s',
					wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ),
					wp_strip_all_tags( (string) ( $field['display'] ?? '' ) )
				);
			}
			return implode( "\n", $lines );
		}

		$rows = '';
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || 'hidden' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			$rows .= sprintf(
				'<tr><td style="padding:6px 12px;font-weight:bold;background:#f5f5f5;border:1px solid #ddd;">%s</td>'
				. '<td style="padding:6px 12px;border:1px solid #ddd;">%s</td></tr>',
				esc_html( wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) ),
				nl2br( esc_html( (string) ( $field['display'] ?? '' ) ) )
			);
		}

		return '<table style="width:100%;border-collapse:collapse;">' . $rows . '</table>';
	}

	/**
	 * Resolve a recipient list that may contain mail tags.
	 *
	 * @param string $template Raw recipient string.
	 * @param array  $context  Tag => value.
	 * @return string[] Valid email addresses.
	 */
	public static function recipients( string $template, array $context ): array {
		$resolved = self::render( $template, $context, self::MODE_HEADER );

		$emails = array();
		foreach ( preg_split( '/[,;]+/', $resolved ) as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate && is_email( $candidate ) ) {
				$emails[] = $candidate;
			}
		}

		return array_values( array_unique( $emails ) );
	}
}
