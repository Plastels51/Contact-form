<?php
/**
 * Finds 2.x shortcodes across the site.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Legacy_Scanner
 */
class CFS_Legacy_Scanner {

	/**
	 * Find every [contact_form] that still uses the 2.x attribute syntax.
	 *
	 * @return array<int, array> Occurrences.
	 */
	public static function scan(): array {
		return array_merge( self::scan_posts(), self::scan_widgets() );
	}

	/**
	 * Occurrences inside post content.
	 *
	 * @return array<int, array>
	 */
	private static function scan_posts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_status, post_content
				 FROM {$wpdb->posts}
				 WHERE post_content LIKE %s
				   AND post_status NOT IN ('trash', 'auto-draft')
				   AND post_type NOT IN ('revision', %s)
				 ORDER BY post_type, post_title",
				'%[contact_form%',
				CFS_Post_Type::POST_TYPE
			)
		);

		$found = array();

		foreach ( (array) $rows as $row ) {
			foreach ( self::extract( (string) $row->post_content ) as $match ) {
				$found[] = array_merge(
					$match,
					array(
						'source'   => 'post',
						'id'       => (int) $row->ID,
						'title'    => (string) $row->post_title,
						'subtitle' => sprintf( '%s · %s', $row->post_type, $row->post_status ),
						'edit_url' => (string) get_edit_post_link( (int) $row->ID, 'raw' ),
					)
				);
			}
		}

		return $found;
	}

	/**
	 * Occurrences inside classic text widgets.
	 *
	 * Widgets are reported but never rewritten: their storage differs per
	 * widget type and a wrong write there is not undoable from a post backup.
	 *
	 * @return array<int, array>
	 */
	private static function scan_widgets(): array {
		$found    = array();
		$instances = get_option( 'widget_text', array() );

		if ( ! is_array( $instances ) ) {
			return $found;
		}

		foreach ( $instances as $key => $instance ) {
			if ( ! is_array( $instance ) || empty( $instance['text'] ) ) {
				continue;
			}

			foreach ( self::extract( (string) $instance['text'] ) as $match ) {
				$found[] = array_merge(
					$match,
					array(
						'source'   => 'widget',
						'id'       => (int) $key,
						'title'    => (string) ( $instance['title'] ?? __( 'Текстовый виджет', 'contact-form-submissions' ) ),
						'subtitle' => __( 'виджет — замените шорткод вручную', 'contact-form-submissions' ),
						'edit_url' => admin_url( 'widgets.php' ),
					)
				);
			}
		}

		return $found;
	}

	/**
	 * Pull legacy shortcodes out of one blob of content.
	 *
	 * @param string $content Content to scan.
	 * @return array<int, array>
	 */
	public static function extract( string $content ): array {
		if ( false === strpos( $content, '[contact_form' ) ) {
			return array();
		}

		$pattern = get_shortcode_regex( array( 'contact_form' ) );
		if ( ! preg_match_all( '/' . $pattern . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$found = array();

		foreach ( $matches as $match ) {
			$atts = shortcode_parse_atts( $match[3] );
			$atts = is_array( $atts ) ? $atts : array();

			// A shortcode that already names a form has nothing to migrate.
			if ( isset( $atts['id'] ) || isset( $atts['slug'] ) ) {
				continue;
			}

			$found[] = array(
				'shortcode' => (string) $match[0],
				'atts'      => $atts,
				'hash'      => self::hash( $atts ),
			);
		}

		return $found;
	}

	/**
	 * Stable hash of one attribute set, so identical shortcodes collapse into
	 * a single new form instead of one per page.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function hash( array $atts ): string {
		$normalised = array_change_key_case( $atts, CASE_LOWER );
		ksort( $normalised );

		return md5( (string) wp_json_encode( $normalised ) );
	}

	/**
	 * Group occurrences by attribute hash.
	 *
	 * @param array $occurrences Result of scan().
	 * @return array<string, array> hash => array( 'atts' => …, 'items' => … )
	 */
	public static function group( array $occurrences ): array {
		$groups = array();

		foreach ( $occurrences as $occurrence ) {
			$hash = (string) $occurrence['hash'];

			if ( ! isset( $groups[ $hash ] ) ) {
				$groups[ $hash ] = array(
					'atts'  => (array) $occurrence['atts'],
					'items' => array(),
				);
			}

			$groups[ $hash ]['items'][] = $occurrence;
		}

		return $groups;
	}
}
