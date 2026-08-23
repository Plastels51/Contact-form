<?php
/**
 * Template HTML sanitiser.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Template_Sanitizer
 *
 * The template is sanitised once, when the form is saved, and never again:
 * running kses over the rendered output would strip the SVG icons and ARIA
 * attributes the plugin itself generates.
 *
 * Field tags survive untouched — kses treats "[phone* phone]" as ordinary text.
 */
class CFS_Template_Sanitizer {

	/**
	 * Sanitise a form template.
	 *
	 * @param string $template Raw template as typed by the author.
	 * @return string
	 */
	public static function sanitize( string $template ): string {
		$template = (string) wp_check_invalid_utf8( $template, true );

		// Normalise line endings so stored templates diff cleanly and the
		// balance check below counts lines the same way the editor does.
		$template = str_replace( array( "\r\n", "\r" ), "\n", $template );

		// Field tags are masked out first — see CFS_Template_Parser::mask().
		$stash  = array();
		$masked = CFS_Template_Parser::mask( $template, $stash );
		$clean  = wp_kses( $masked, self::allowed_html() );

		return CFS_Template_Parser::unmask( $clean, $stash );
	}

	/**
	 * The HTML allowlist for form templates.
	 *
	 * Form controls (input, select, textarea, button) are deliberately absent:
	 * a control added by hand would not exist in the compiled schema, so its
	 * value would be silently discarded on submit. Refusing to store it is
	 * clearer than accepting markup that quietly does nothing.
	 *
	 * @return array<string, array>
	 */
	public static function allowed_html(): array {
		$global = array(
			'class' => array(),
			'id'    => array(),
			'style' => array(),
			'title' => array(),
			'dir'   => array(),
			'lang'  => array(),
		);

		$tags = array(
			'p'          => array(),
			'div'        => array(),
			'span'       => array(),
			'section'    => array(),
			'article'    => array(),
			'header'     => array(),
			'footer'     => array(),
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'h5'         => array(),
			'h6'         => array(),
			'br'         => array(),
			'hr'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'u'          => array(),
			's'          => array(),
			'small'      => array(),
			'sub'        => array(),
			'sup'        => array(),
			'code'       => array(),
			'pre'        => array(),
			'blockquote' => array( 'cite' => array() ),
			'ul'         => array(),
			'ol'         => array( 'start' => array(), 'type' => array() ),
			'li'         => array(),
			'dl'         => array(),
			'dt'         => array(),
			'dd'         => array(),
			'table'      => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tfoot'      => array(),
			'tr'         => array(),
			'th'         => array( 'colspan' => array(), 'rowspan' => array(), 'scope' => array() ),
			'td'         => array( 'colspan' => array(), 'rowspan' => array() ),
			'fieldset'   => array(),
			'legend'     => array(),
			'label'      => array( 'for' => array() ),
			'figure'     => array(),
			'figcaption' => array(),
			'a'          => array(
				'href'     => array(),
				'target'   => array(),
				'rel'      => array(),
				'download' => array(),
			),
			'img'        => array(
				'src'     => array(),
				'alt'     => array(),
				'width'   => array(),
				'height'  => array(),
				'loading' => array(),
				'srcset'  => array(),
				'sizes'   => array(),
			),
		);

		foreach ( $tags as $tag => $attrs ) {
			$tags[ $tag ] = array_merge( $global, $attrs );
		}

		/**
		 * Filter the HTML allowed inside a form template.
		 *
		 * @param array<string, array> $tags Tag => allowed attributes.
		 */
		return (array) apply_filters( 'cfs_template_allowed_html', $tags );
	}

	/**
	 * Whether the template's HTML tags are balanced.
	 *
	 * Used only to warn the author — an unbalanced template is still saved and
	 * still renders, it just leaks a stray tag into the surrounding page.
	 *
	 * @param string $template Sanitised template.
	 * @return bool
	 */
	public static function is_balanced( string $template ): bool {
		// Field tags may contain characters that confuse the balancer; strip
		// them first so only real HTML is measured.
		$html_only = '';
		foreach ( CFS_Template_Parser::parse( $template ) as $segment ) {
			if ( 'html' === $segment['kind'] ) {
				$html_only .= $segment['content'];
			}
		}

		// force_balance_tags() also rewrites void tags — "<hr>" comes back as
		// "<hr />" — so the strings are normalised before comparison. Without
		// this every template containing <br> or <hr> reported a false alarm.
		return self::normalize_void_tags( force_balance_tags( $html_only ) )
			=== self::normalize_void_tags( $html_only );
	}

	/**
	 * Drop the optional self-closing slash from void elements.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private static function normalize_void_tags( string $html ): string {
		return (string) preg_replace( '#\s*/>#', '>', $html );
	}
}
