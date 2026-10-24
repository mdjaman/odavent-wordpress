<?php
/**
 * HTML inspection for bin/smoke.sh: reads a page on STDIN, prints JSON on STDOUT.
 * Runs inside the wpcli container (any PHP 7.4+ with DOM), so the host needs no PHP, jq or Python.
 *
 * Usage: php html.php <command> < page.html
 *   links        every <a>: {href, text, class}
 *   jsonld       every application/ld+json script: {valid, error, data}
 *   scripts      every <script>: {type, src, length}
 *   fragments    outer HTML of each top-level element carrying an odv-* class, normalised (see normalise())
 *   dangerous    attribute problems: on* handlers, javascript:/data:/vbscript: URLs in URL attributes
 *   parse        libxml parse errors, except "tag X invalid" (libxml's HTML 4 parser does not know HTML5 tags)
 *   text         the visible text content
 *   count <s>    occurrences of the literal string <s> in the raw HTML
 */

$command = $argv[1] ?? '';
$html    = stream_get_contents( STDIN );

if ( 'count' === $command ) {
	echo json_encode( substr_count( $html, (string) ( $argv[2] ?? '' ) ) ), "\n";
	exit;
}

libxml_use_internal_errors( true );
$doc = new DOMDocument();
$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET );
$errors = libxml_get_errors();
libxml_clear_errors();
$xpath = new DOMXPath( $doc );

function out( $value ) {
	echo json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), "\n";
	exit;
}

function has_odv_class( DOMElement $el ): bool {
	return 1 === preg_match( '/(^|\s)odv-/', $el->getAttribute( 'class' ) );
}

/**
 * Comparable form of a block's HTML: whitespace collapsed, entities decoded, typographic quotes/dashes
 * (wptexturize runs on block output but not on shortcode output) folded to ASCII, wp-block-* classes
 * dropped, page-specific values (return URL, current countdown digits, page URL in JSON-LD) blanked.
 */
function normalise( string $html ): string {
	$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$html = strtr( $html, array( "\u{2019}" => "'", "\u{2018}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ' ) );
	$html = preg_replace( '/\bwp-block-[a-z0-9-]+\s*/', '', $html );
	$html = preg_replace( '/return=[^&"\s]*/', 'return=*', $html );
	$html = preg_replace( '#"url":"http://[^"]*"#', '"url":"*"', $html );
	$html = preg_replace( '/(data-odv-(?:days|hours|minutes|seconds)[^>]*>)\s*\d+/', '$1#', $html );
	$html = preg_replace( '/(odv-countdown__value[^>]*>)\s*\d+/', '$1#', $html );
	$html = preg_replace( '/\s+/', ' ', $html );
	$html = preg_replace( '/>\s+</', '><', $html );

	return trim( preg_replace( '/class=" *"/', '', $html ) );
}

switch ( $command ) {
	case 'links':
		$links = array();
		foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
			$links[] = array(
				'href'  => $a->getAttribute( 'href' ),
				'text'  => trim( preg_replace( '/\s+/', ' ', $a->textContent ) ),
				'class' => $a->getAttribute( 'class' ),
			);
		}
		out( $links );

	case 'jsonld':
		$found = array();
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
			$data    = json_decode( $script->textContent, true );
			$found[] = array(
				'valid' => JSON_ERROR_NONE === json_last_error() && is_array( $data ),
				'error' => json_last_error_msg(),
				'data'  => $data,
			);
		}
		out( $found );

	case 'scripts':
		$found = array();
		foreach ( $doc->getElementsByTagName( 'script' ) as $script ) {
			$found[] = array(
				'type'   => $script->getAttribute( 'type' ),
				'src'    => $script->getAttribute( 'src' ),
				'id'     => $script->getAttribute( 'id' ),
				'length' => strlen( $script->textContent ),
				'odv'    => null !== $script->parentNode && $script->parentNode instanceof DOMElement && has_odv_class( $script->parentNode ),
			);
		}
		out( $found );

	case 'fragments':
		$found = array();
		foreach ( $xpath->query( '//*[@class]' ) as $el ) {
			if ( ! has_odv_class( $el ) ) {
				continue;
			}
			for ( $parent = $el->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
				if ( has_odv_class( $parent ) ) {
					continue 2;
				}
			}
			preg_match( '/(^|\s)(odv-[a-z-]+)/', $el->getAttribute( 'class' ), $m );
			$found[] = array(
				'block' => $m[2],
				'html'  => normalise( $doc->saveHTML( $el ) ),
			);
		}
		out( $found );

	case 'dangerous':
		$problems = array();
		$url_attrs = array( 'href', 'src', 'srcset', 'action', 'formaction', 'poster', 'data', 'xlink:href', 'background', 'cite', 'longdesc', 'content' );
		foreach ( $xpath->query( '//*' ) as $el ) {
			foreach ( $el->attributes as $attr ) {
				$name = strtolower( $attr->nodeName );
				if ( 0 === strpos( $name, 'on' ) ) {
					$problems[] = $el->nodeName . ' has ' . $name;
				}
				if ( in_array( $name, $url_attrs, true ) ) {
					$value = strtolower( preg_replace( '/[\x00-\x20]+/', '', $attr->nodeValue ) );
					if ( 'content' === $name && 'meta' === $el->nodeName ) {
						continue;
					}
					if ( preg_match( '/^(javascript|data|vbscript):/', $value ) ) {
						$problems[] = $el->nodeName . ' ' . $name . '=' . substr( $attr->nodeValue, 0, 60 );
					}
				}
			}
		}
		out( $problems );

	case 'parse':
		$found = array();
		foreach ( $errors as $error ) {
			if ( 801 === $error->code ) {
				continue; // "Tag section invalid": an HTML5 element unknown to libxml.
			}
			$found[] = array(
				'code'    => $error->code,
				'line'    => $error->line,
				'message' => trim( $error->message ),
			);
		}
		out( $found );

	case 'text':
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		out( null !== $body ? trim( preg_replace( '/\s+/', ' ', $body->textContent ) ) : '' );

	default:
		fwrite( STDERR, "Unknown command '{$command}'\n" );
		exit( 2 );
}
