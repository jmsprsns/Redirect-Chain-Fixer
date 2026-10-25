<?php
/**
 * Finds links in post content and swaps them for new ones.
 *
 * Links are the href of every <a> tag. Replacing one changes that href and,
 * for blocks that keep a copy of the URL in their attributes (the JSON in
 * the <!-- wp:... --> comment), that copy too, so the block editor doesn't
 * flag the block as changed outside the editor.
 *
 * Each replacement reports the exact spots it changed, so an undo can put
 * back those spots only, and not other links that happen to point at the
 * same page.
 *
 * @package Redirect_Chain_Fixer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content helper.
 */
final class Redirect_Chain_Fixer_Content {

	/**
	 * The href of every <a> tag: 1 is everything before the value, 2 the quote, 3 the value.
	 */
	const HREF = '/(<a\s[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is';

	/**
	 * A block comment's attributes, as WordPress's own block parser reads them.
	 */
	const BLOCK = '/<!--\s+wp:(?:[a-z][a-z0-9_-]*\/)?[a-z][a-z0-9_-]*\s+(\{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?\})\s+\/?-->/s';

	/**
	 * Every distinct link in a piece of HTML, as written (entities decoded).
	 *
	 * @param string $html Post content.
	 * @return string[]
	 */
	public static function links( $html ) {
		if ( ! preg_match_all( self::HREF, (string) $html, $matches ) ) {
			return array();
		}

		$links = array();
		foreach ( $matches[3] as $raw ) {
			$link = self::decode( $raw );
			if ( '' !== $link ) {
				$links[ $link ] = true;
			}
		}

		return array_keys( $links );
	}

	/**
	 * Replace links, everywhere they appear.
	 *
	 * @param string               $html   Post content.
	 * @param array<string,string> $map    Old link (as links() returns it) => new link.
	 * @param array                $places Set to where each old link was replaced: old link =>
	 *                                     list of places, each array( 'a', n ) for the nth link
	 *                                     in the content or array( 'b', n, path ) for an attribute
	 *                                     of the nth block comment with attributes.
	 * @return string The content, unchanged if none of the links are in it or
	 *                it couldn't be processed safely.
	 */
	public static function replace( $html, array $map, &$places = array() ) {
		$original = (string) $html;
		$places   = array();

		if ( ! $map ) {
			return $original;
		}

		$index = -1;
		$html  = preg_replace_callback(
			self::HREF,
			function ( $m ) use ( $map, &$index, &$places ) {
				$index++;
				$link = self::decode( $m[3] );

				if ( ! isset( $map[ $link ] ) ) {
					return $m[0];
				}

				$places[ $link ][] = array( 'a', $index );

				return $m[1] . $m[2] . esc_attr( $map[ $link ] ) . $m[2];
			},
			$original
		);

		// A regex error (e.g. the backtrack limit on a huge post) returns null:
		// leave the post alone rather than risk saving it empty.
		if ( ! is_string( $html ) ) {
			$places = array();
			return $original;
		}

		$html = self::edit_blocks(
			$html,
			function ( $attrs, $block ) use ( $map, &$places ) {
				return self::walk(
					$attrs,
					array(),
					function ( $value, $path ) use ( $map, $block, &$places ) {
						if ( ! isset( $map[ $value ] ) ) {
							return $value;
						}

						$places[ $value ][] = array( 'b', $block, $path );

						return $map[ $value ];
					}
				);
			}
		);

		if ( ! is_string( $html ) ) {
			$places = array();
			return $original;
		}

		return $html;
	}

	/**
	 * Put a replaced link back: only in the places replace() reported, and
	 * only where the link is still the new one.
	 *
	 * @param string $html   Post content.
	 * @param string $new    The link as it is now.
	 * @param string $old    The link to put back.
	 * @param array  $places Places from replace().
	 * @return string The content, unchanged if nothing matched.
	 */
	public static function revert( $html, $new, $old, array $places ) {
		$original = (string) $html;
		$links    = array();
		$blocks   = array();

		foreach ( $places as $place ) {
			if ( ! is_array( $place ) || ! isset( $place[0], $place[1] ) ) {
				continue;
			}

			if ( 'a' === $place[0] ) {
				$links[ (int) $place[1] ] = true;
			} elseif ( 'b' === $place[0] && isset( $place[2] ) && is_array( $place[2] ) ) {
				$blocks[ (int) $place[1] ][] = $place[2];
			}
		}

		$index = -1;
		$html  = preg_replace_callback(
			self::HREF,
			function ( $m ) use ( $new, $old, $links, &$index ) {
				$index++;

				if ( ! isset( $links[ $index ] ) || self::decode( $m[3] ) !== $new ) {
					return $m[0];
				}

				return $m[1] . $m[2] . esc_attr( $old ) . $m[2];
			},
			$original
		);

		if ( ! is_string( $html ) ) {
			return $original;
		}

		if ( ! $blocks ) {
			return $html;
		}

		$html = self::edit_blocks(
			$html,
			function ( $attrs, $block ) use ( $new, $old, $blocks ) {
				if ( ! isset( $blocks[ $block ] ) ) {
					return $attrs;
				}

				return self::walk(
					$attrs,
					array(),
					function ( $value, $path ) use ( $new, $old, $blocks, $block ) {
						return $value === $new && in_array( $path, $blocks[ $block ], true ) ? $old : $value;
					}
				);
			}
		);

		return is_string( $html ) ? $html : $original;
	}

	/**
	 * Run a callback over the attributes of each block comment that has
	 * them, re-encoding only the ones it changes.
	 *
	 * @param string   $html Post content.
	 * @param callable $edit Gets ( array $attrs, int $block ), returns the attributes.
	 * @return string|null Null on a regex error.
	 */
	private static function edit_blocks( $html, $edit ) {
		if ( false === strpos( $html, '<!-- wp:' ) ) {
			return $html;
		}

		$index = -1;

		return preg_replace_callback(
			self::BLOCK,
			function ( $m ) use ( $edit, &$index ) {
				$index++;
				$attrs = json_decode( $m[1], true );

				if ( ! is_array( $attrs ) ) {
					return $m[0];
				}

				$edited = call_user_func( $edit, $attrs, $index );

				if ( $edited === $attrs ) {
					return $m[0];
				}

				// Encoded the way the block editor encodes them, so the comment
				// reads back exactly as if the editor had saved it.
				$json = function_exists( 'serialize_block_attributes' ) ? serialize_block_attributes( $edited ) : wp_json_encode( $edited, JSON_UNESCAPED_SLASHES );

				return str_replace( $m[1], $json, $m[0] );
			},
			$html
		);
	}

	/**
	 * Pass every string in a nested array through a callback, with its key path.
	 *
	 * @param array    $attrs    Attributes.
	 * @param array    $path     Keys leading to $attrs.
	 * @param callable $callback Gets ( string $value, array $path ), returns the new value.
	 * @return array
	 */
	private static function walk( array $attrs, array $path, $callback ) {
		foreach ( $attrs as $key => $value ) {
			$here = array_merge( $path, array( $key ) );

			if ( is_array( $value ) ) {
				$attrs[ $key ] = self::walk( $value, $here, $callback );
			} elseif ( is_string( $value ) ) {
				$attrs[ $key ] = call_user_func( $callback, $value, $here );
			}
		}

		return $attrs;
	}

	/**
	 * An href value as the browser reads it: entities decoded, spaces trimmed.
	 *
	 * @param string $raw Attribute value from the HTML.
	 * @return string
	 */
	public static function decode( $raw ) {
		return trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
