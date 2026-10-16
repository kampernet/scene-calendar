<?php
namespace SceneCalendar\Util;

defined( 'ABSPATH' ) || exit;

/**
 * DOM helpers: load HTML into an XPath, select by class, extract text,
 * read JSON-LD, and convert pages to compact markdown for the AI agent.
 */
final class Html {

	/** Load an HTML string (UTF-8) and return a DOMXPath over it. */
	public static function load( string $html ): \DOMXPath {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The XML declaration forces libxml to treat the input as UTF-8.
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return new \DOMXPath( $dom );
	}

	/**
	 * XPath predicate matching elements that have CSS class $class.
	 * Usage: $xp->query('//div[' . Html::cls('tw-section') . ']').
	 */
	public static function cls( string $class ): string {
		return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
	}

	/** First element matching $xpath (relative to $context if given), or null. */
	public static function first( \DOMXPath $xp, string $xpath, ?\DOMNode $context = null ): ?\DOMElement {
		$nodes = $context ? $xp->query( $xpath, $context ) : $xp->query( $xpath );
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				if ( $node instanceof \DOMElement ) {
					return $node;
				}
			}
		}
		return null;
	}

	/** All elements matching $xpath as an array. */
	public static function all( \DOMXPath $xp, string $xpath, ?\DOMNode $context = null ): array {
		$nodes = $context ? $xp->query( $xpath, $context ) : $xp->query( $xpath );
		$out   = array();
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				if ( $node instanceof \DOMElement ) {
					$out[] = $node;
				}
			}
		}
		return $out;
	}

	/** Text content of a node with whitespace collapsed (like bs4 get_text(" ", strip=True)). */
	public static function text( ?\DOMNode $node ): string {
		if ( ! $node ) {
			return '';
		}
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** Attribute value or '' (null-safe). */
	public static function attr( ?\DOMElement $el, string $name ): string {
		return $el ? trim( $el->getAttribute( $name ) ) : '';
	}

	/**
	 * Convert an HTML fragment to plain text with block elements on separate
	 * lines (like bs4 get_text(separator="\n")). Entities are decoded.
	 */
	public static function to_text( string $html ): string {
		$html = preg_replace( '/<(script|style)\b[\s\S]*?<\/\1>/i', ' ', $html );
		$html = preg_replace( '/<(?:br|p|h[1-6]|div|li|tr|td|th|section|article|header|footer)\b[^>]*\/?>/i', "\n", $html );
		$html = preg_replace( '/<\/(?:p|h[1-6]|div|li|tr|td|th|section|article|header|footer)>/i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$lines = array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' );
		return implode( "\n", $lines );
	}

	/** Strip tags to a single line of text, keeping word boundaries at block elements. */
	public static function to_line( string $html ): string {
		return trim( preg_replace( '/\s+/u', ' ', self::to_text( $html ) ) );
	}

	/**
	 * Decode every <script type="application/ld+json"> block in a page.
	 * Returns a flat list of top-level objects (arrays and @graph are expanded).
	 */
	public static function json_ld( string $html ): array {
		$out = array();
		if ( ! preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>([\s\S]*?)<\/script>/i', $html, $matches ) ) {
			return $out;
		}
		foreach ( $matches[1] as $block ) {
			$data = json_decode( trim( $block ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$items = array_is_list( $data ) ? $data : array( $data );
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( isset( $item['@graph'] ) && is_array( $item['@graph'] ) ) {
					foreach ( $item['@graph'] as $g ) {
						if ( is_array( $g ) ) {
							$out[] = $g;
						}
					}
				} else {
					$out[] = $item;
				}
			}
		}
		return $out;
	}

	/**
	 * Reduce a full page to compact markdown-ish text for the LLM: drops
	 * scripts/styles/media/nav chrome, keeps headings, list items and links
	 * (made absolute against $base_url).
	 */
	public static function to_markdown( string $html, string $base_url = '' ): string {
		$title = '';
		if ( preg_match( '/<title[^>]*>([\s\S]*?)<\/title>/i', $html, $m ) ) {
			$title = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		if ( preg_match( '/<body[^>]*>([\s\S]*)<\/body>/i', $html, $m ) ) {
			$html = $m[1];
		}
		$html = preg_replace( '/<(script|style|noscript|svg|iframe|video|audio|template)\b[\s\S]*?<\/\1>/i', ' ', $html );
		$html = preg_replace( '/<!--[\s\S]*?-->/', ' ', $html );

		// Images: keep alt text only (posters often carry the show name).
		$html = preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $m ) {
				return preg_match( '/\balt=["\']([^"\']+)["\']/i', $m[0], $a ) ? ' ' . $a[1] . ' ' : ' ';
			},
			$html
		);

		// Links -> [text](absolute href).
		$html = preg_replace_callback(
			'/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i',
			static function ( $m ) use ( $base_url ) {
				$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $m[2] ) ) );
				$href = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( str_starts_with( $href, '#' ) || str_starts_with( strtolower( $href ), 'javascript:' ) ) {
					return $text;
				}
				$href = self::absolute_url( $href, $base_url );
				return '' === $text ? " $href " : " [$text]($href) ";
			},
			$html
		);

		// Headings and list items.
		$html = preg_replace_callback(
			'/<h([1-6])\b[^>]*>([\s\S]*?)<\/h\1>/i',
			static function ( $m ) {
				return "\n" . str_repeat( '#', (int) $m[1] ) . ' ' . trim( wp_strip_all_tags( $m[2] ) ) . "\n";
			},
			$html
		);
		$html = preg_replace( '/<li\b[^>]*>/i', "\n- ", $html );

		$text = self::to_text( $html );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return ( '' !== $title ? "# $title\n" : '' ) . $text;
	}

	/** Resolve a possibly-relative URL against a base URL. */
	public static function absolute_url( string $href, string $base ): string {
		if ( '' === $base || preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) ) {
			return $href;
		}
		$parts = wp_parse_url( $base );
		if ( empty( $parts['host'] ) ) {
			return $href;
		}
		$scheme = $parts['scheme'] ?? 'https';
		if ( str_starts_with( $href, '//' ) ) {
			return "$scheme:$href";
		}
		$origin = $scheme . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( str_starts_with( $href, '/' ) ) {
			return $origin . $href;
		}
		$path = $parts['path'] ?? '/';
		$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
		return $origin . ( '' === $dir ? '/' : $dir ) . $href;
	}
}
