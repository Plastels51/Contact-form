<?php
/**
 * Form template parser — splits a template into HTML segments and field tags.
 *
 * @package ContactFormSubmissions
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Template_Parser
 *
 * Grammar:
 *
 *   [type[*] name attr="value" attr=value flag]
 *
 *   • type  — first token, required.
 *   • *     — marks the field as required.
 *   • name  — second bare token; optional for [submit] and [step].
 *   • attrs — named, any order. Values may be double-quoted, single-quoted or
 *             bare (no whitespace). A key with no "=" is a boolean flag.
 *   • a bare quoted string is a positional value, e.g. [submit "Отправить"].
 *   • [# … ] is a comment and produces no output.
 *   • \[ and \] are literal brackets.
 *
 * Parsing is done with a hand-written scanner rather than a regular
 * expression: an attribute value may legitimately contain a closing bracket
 * (label="Согласен [с условиями]"), and no regex can tell that apart from the
 * end of the tag without full quote tracking.
 */
class CFS_Template_Parser {

	/**
	 * Parse a template into an ordered list of segments.
	 *
	 * Each segment is one of:
	 *   array( 'kind' => 'html', 'content' => string )
	 *   array( 'kind' => 'tag',  'tag'     => array )
	 *
	 * A tag array looks like:
	 *   array(
	 *     'type'       => 'phone',
	 *     'name'       => 'phone',
	 *     'required'   => true,
	 *     'attrs'      => array( 'label' => 'Телефон' ),
	 *     'positional' => array( 'Отправить' ),
	 *     'raw'        => '[phone* phone label="Телефон"]',
	 *     'line'       => 4,
	 *   )
	 *
	 * @param string $template Raw template text.
	 * @return array<int, array>
	 */
	public static function parse( string $template ): array {
		$segments = array();
		$buffer   = '';
		$len      = strlen( $template );
		$i        = 0;

		while ( $i < $len ) {
			$char = $template[ $i ];

			// Escaped bracket → literal character, never a tag.
			if ( '\\' === $char && $i + 1 < $len && ( '[' === $template[ $i + 1 ] || ']' === $template[ $i + 1 ] ) ) {
				$buffer .= $template[ $i + 1 ];
				$i      += 2;
				continue;
			}

			if ( '[' !== $char ) {
				$buffer .= $char;
				++$i;
				continue;
			}

			$end = self::find_tag_end( $template, $i );
			if ( -1 === $end ) {
				// Unclosed bracket — treat as ordinary text so the author sees
				// their own typo rather than losing the rest of the template.
				$buffer .= $char;
				++$i;
				continue;
			}

			$raw   = substr( $template, $i, $end - $i + 1 );
			$inner = substr( $template, $i + 1, $end - $i - 1 );
			$tag   = self::parse_tag( $inner, $raw, self::line_at( $template, $i ) );

			if ( null !== $tag ) {
				if ( '' !== $buffer ) {
					$segments[] = array(
						'kind'    => 'html',
						'content' => $buffer,
					);
					$buffer = '';
				}
				$segments[] = array(
					'kind' => 'tag',
					'tag'  => $tag,
				);
			}

			$i = $end + 1;
		}

		if ( '' !== $buffer ) {
			$segments[] = array(
				'kind'    => 'html',
				'content' => $buffer,
			);
		}

		return $segments;
	}

	/**
	 * Return only the field tags of a template, in document order.
	 *
	 * @param string $template Raw template text.
	 * @return array<int, array>
	 */
	public static function parse_tags( string $template ): array {
		$tags = array();
		foreach ( self::parse( $template ) as $segment ) {
			if ( 'tag' === $segment['kind'] ) {
				$tags[] = $segment['tag'];
			}
		}
		return $tags;
	}

	/**
	 * Replace every field tag with an opaque placeholder.
	 *
	 * Field tags are not HTML and must not be run through kses: a bare "<" in
	 * an attribute makes kses swallow the rest of the tag, closing quote and
	 * all, which silently destroys the field. Masking the tags first means the
	 * sanitiser only ever sees real markup.
	 *
	 * @param string $template Raw template.
	 * @param array  $stash    Receives placeholder => raw tag text.
	 * @return string Template with tags replaced by placeholders.
	 */
	public static function mask( string $template, array &$stash ): string {
		$stash = array();

		// A per-call token so a template containing the literal placeholder
		// text cannot be used to smuggle markup past the sanitiser.
		$token  = 'CFS' . substr( md5( $template . wp_rand() ), 0, 10 );
		$masked = '';
		$len    = strlen( $template );
		$i      = 0;
		$n      = 0;

		while ( $i < $len ) {
			$char = $template[ $i ];

			if ( '\\' === $char && $i + 1 < $len && ( '[' === $template[ $i + 1 ] || ']' === $template[ $i + 1 ] ) ) {
				$masked .= substr( $template, $i, 2 );
				$i      += 2;
				continue;
			}

			if ( '[' === $char ) {
				$end = self::find_tag_end( $template, $i );
				if ( -1 !== $end ) {
					$placeholder           = '%%' . $token . '_' . $n . '%%';
					$stash[ $placeholder ] = substr( $template, $i, $end - $i + 1 );
					$masked               .= $placeholder;
					$i                     = $end + 1;
					++$n;
					continue;
				}
			}

			$masked .= $char;
			++$i;
		}

		return $masked;
	}

	/**
	 * Put masked tags back into a sanitised template.
	 *
	 * @param string $template Masked and sanitised template.
	 * @param array  $stash    Placeholder => raw tag text, from mask().
	 * @return string
	 */
	public static function unmask( string $template, array $stash ): string {
		if ( empty( $stash ) ) {
			return $template;
		}
		return str_replace( array_keys( $stash ), array_values( $stash ), $template );
	}

	/**
	 * Locate the closing bracket of the tag that starts at $start.
	 *
	 * Quoted sections are skipped wholesale, so a "]" inside a label does not
	 * terminate the tag. A second unquoted "[" means the first one was never a
	 * tag at all — bail out and let the caller emit it as text.
	 *
	 * @param string $template Template text.
	 * @param int    $start    Index of the opening "[".
	 * @return int Index of the closing "]", or -1 when there is none.
	 */
	private static function find_tag_end( string $template, int $start ): int {
		$len   = strlen( $template );
		$quote = '';

		for ( $i = $start + 1; $i < $len; $i++ ) {
			$char = $template[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $char && $i + 1 < $len ) {
					++$i; // Skip the escaped character.
					continue;
				}
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( ']' === $char ) {
				return $i;
			}

			if ( '[' === $char ) {
				return -1;
			}
		}

		return -1;
	}

	/**
	 * Parse the inside of a tag (everything between the brackets).
	 *
	 * @param string $inner Tag body without brackets.
	 * @param string $raw   Full raw tag including brackets.
	 * @param int    $line  1-based line number of the opening bracket.
	 * @return array|null Tag array, or null for comments and empty tags.
	 */
	private static function parse_tag( string $inner, string $raw, int $line ) {
		$inner = trim( $inner );

		if ( '' === $inner || '#' === $inner[0] ) {
			return null; // Comment or empty brackets.
		}

		$pos = 0;
		$len = strlen( $inner );

		// ── Type, with optional trailing "*" ────────────────────────────────
		$type = self::read_bare( $inner, $pos, $len );
		if ( '' === $type ) {
			return null;
		}

		$required = false;
		if ( '*' === substr( $type, -1 ) ) {
			$required = true;
			$type     = substr( $type, 0, -1 );
		}

		$type = strtolower( $type );
		if ( ! preg_match( '/^[a-z][a-z0-9_-]*$/', $type ) ) {
			return null;
		}

		// ── Name, attributes, positional values ─────────────────────────────
		$name       = '';
		$attrs      = array();
		$positional = array();

		while ( $pos < $len ) {
			self::skip_whitespace( $inner, $pos, $len );
			if ( $pos >= $len ) {
				break;
			}

			$char = $inner[ $pos ];

			if ( '"' === $char || "'" === $char ) {
				$positional[] = self::read_quoted( $inner, $pos, $len );
				continue;
			}

			$key = self::read_key( $inner, $pos, $len );
			if ( '' === $key ) {
				++$pos; // Unexpected character — step over it.
				continue;
			}

			if ( $pos < $len && '=' === $inner[ $pos ] ) {
				++$pos;
				$attrs[ strtolower( $key ) ] = self::read_value( $inner, $pos, $len );
				continue;
			}

			// Bare token: the first one is the field name, the rest are flags.
			if ( '' === $name ) {
				$name = $key;
				continue;
			}

			$attrs[ strtolower( $key ) ] = 'true';
		}

		// "required" as a flag or attribute is equivalent to the "*" suffix.
		if ( isset( $attrs['required'] ) ) {
			$flag = strtolower( trim( (string) $attrs['required'] ) );
			$required = ! in_array( $flag, array( 'no', 'false', '0', '' ), true );
			unset( $attrs['required'] );
		}

		return array(
			'type'       => $type,
			'name'       => $name,
			'required'   => $required,
			'attrs'      => $attrs,
			'positional' => $positional,
			'raw'        => $raw,
			'line'       => $line,
		);
	}

	/**
	 * Advance past any whitespace.
	 *
	 * @param string $str String being scanned.
	 * @param int    $pos Cursor, advanced in place.
	 * @param int    $len String length.
	 */
	private static function skip_whitespace( string $str, int &$pos, int $len ): void {
		while ( $pos < $len && preg_match( '/\s/', $str[ $pos ] ) ) {
			++$pos;
		}
	}

	/**
	 * Read a bare token up to the next whitespace.
	 *
	 * @param string $str String being scanned.
	 * @param int    $pos Cursor, advanced in place.
	 * @param int    $len String length.
	 * @return string
	 */
	private static function read_bare( string $str, int &$pos, int $len ): string {
		self::skip_whitespace( $str, $pos, $len );
		$start = $pos;
		while ( $pos < $len && ! preg_match( '/\s/', $str[ $pos ] ) ) {
			++$pos;
		}
		return substr( $str, $start, $pos - $start );
	}

	/**
	 * Read an attribute key: letters, digits, underscore and hyphen.
	 *
	 * @param string $str String being scanned.
	 * @param int    $pos Cursor, advanced in place.
	 * @param int    $len String length.
	 * @return string
	 */
	private static function read_key( string $str, int &$pos, int $len ): string {
		$start = $pos;
		while ( $pos < $len && preg_match( '/[A-Za-z0-9_-]/', $str[ $pos ] ) ) {
			++$pos;
		}
		return substr( $str, $start, $pos - $start );
	}

	/**
	 * Read an attribute value — quoted or bare.
	 *
	 * @param string $str String being scanned.
	 * @param int    $pos Cursor, advanced in place.
	 * @param int    $len String length.
	 * @return string
	 */
	private static function read_value( string $str, int &$pos, int $len ): string {
		if ( $pos < $len && ( '"' === $str[ $pos ] || "'" === $str[ $pos ] ) ) {
			return self::read_quoted( $str, $pos, $len );
		}
		return self::read_bare( $str, $pos, $len );
	}

	/**
	 * Read a quoted string, honouring backslash escapes.
	 *
	 * @param string $str String being scanned.
	 * @param int    $pos Cursor on the opening quote, advanced past the closing one.
	 * @param int    $len String length.
	 * @return string Unescaped contents.
	 */
	private static function read_quoted( string $str, int &$pos, int $len ): string {
		$quote = $str[ $pos ];
		++$pos;
		$value = '';

		while ( $pos < $len ) {
			$char = $str[ $pos ];

			if ( '\\' === $char && $pos + 1 < $len ) {
				$next = $str[ $pos + 1 ];
				// Only quote characters and the backslash itself are escapes;
				// everything else keeps the backslash, so regex patterns in
				// pattern="\d{3}" survive intact.
				if ( $next === $quote || '\\' === $next ) {
					$value .= $next;
					$pos   += 2;
					continue;
				}
			}

			if ( $char === $quote ) {
				++$pos;
				return $value;
			}

			$value .= $char;
			++$pos;
		}

		return $value; // Unterminated quote — return what we have.
	}

	/**
	 * 1-based line number of an offset, for error messages.
	 *
	 * @param string $template Template text.
	 * @param int    $offset   Byte offset.
	 * @return int
	 */
	private static function line_at( string $template, int $offset ): int {
		return substr_count( substr( $template, 0, $offset ), "\n" ) + 1;
	}
}
