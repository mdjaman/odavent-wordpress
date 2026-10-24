/**
 * Odavent Events: broken images degrade instead of showing the browser's broken-image icon.
 *
 * Reads only the data-odv-img attribute of each <img> the templates print ("photo", "logo" or "cover",
 * plus data-odv-initials on a speaker photo). When one cannot load:
 *  - a speaker photo becomes the initials placeholder the template already prints for speakers without a photo;
 *  - a sponsor logo becomes the sponsor's name (the image's alt text);
 *  - an event header logo or cover is removed.
 * Image "error" events do not bubble, so one capture-phase listener on the document catches them all; a one-time
 * sweep also handles images that failed before this deferred script ran. Nodes are built with createElement and
 * textContent only. Safe to run twice (a handled image is detached and skipped) and it never throws.
 */
( function () {
	'use strict';

	var SELECTOR = 'img[data-odv-img]';

	function hasClass( element, name ) {
		return ( ' ' + String( element.className ) + ' ' ).indexOf( ' ' + name + ' ' ) !== -1;
	}

	function isImage( element ) {
		return !!element && String( element.tagName ).toUpperCase() === 'IMG' && typeof element.getAttribute === 'function' && !!element.getAttribute( 'data-odv-img' );
	}

	function degrade( img ) {
		var parent = img.parentNode;
		if ( !parent ) {
			// Already replaced or removed (the error event and the sweep can both see the same image).
			return;
		}

		var kind = img.getAttribute( 'data-odv-img' );
		var replacement = null;

		if ( kind === 'photo' ) {
			replacement = document.createElement( 'span' );
			replacement.className = 'odv-speakers__photo odv-speakers__photo--placeholder';
			replacement.setAttribute( 'aria-hidden', 'true' );
			replacement.textContent = img.getAttribute( 'data-odv-initials' ) || '';
		} else if ( kind === 'logo' && hasClass( img, 'odv-sponsors__logo' ) && img.getAttribute( 'alt' ) ) {
			replacement = document.createElement( 'span' );
			replacement.className = 'odv-sponsors__name';
			replacement.textContent = img.getAttribute( 'alt' );
		}

		if ( replacement ) {
			parent.replaceChild( replacement, img );
		} else {
			// The header logo and cover, or a logo with no name to show instead.
			parent.removeChild( img );
		}
	}

	function onError( event ) {
		try {
			var target = event && event.target;
			if ( isImage( target ) ) {
				degrade( target );
			}
		} catch ( e ) {
			// Never break the page over a picture.
		}
	}

	// A finished load with no pixels. Without a source there is nothing that failed; a lazy image that has not
	// started loading has no currentSrc yet; and an SVG without intrinsic size reports width 0 in some browsers
	// while perfectly fine, so those are left alone (a failing one still reaches the error listener).
	function failedAlready( img ) {
		if ( !img.complete || img.naturalWidth !== 0 ) {
			return false;
		}
		var current = img.currentSrc || '';
		var source = current || img.getAttribute( 'src' ) || '';
		if ( !source ) {
			return false;
		}
		if ( !current && img.getAttribute( 'loading' ) === 'lazy' ) {
			return false;
		}
		return !/\.svg(?:$|[?#])/i.test( source );
	}

	function sweep() {
		try {
			var images = document.querySelectorAll( SELECTOR );
			// A static copy: degrade() changes the tree.
			var list = [];
			var i;
			for ( i = 0; i < images.length; i++ ) {
				list.push( images[ i ] );
			}
			for ( i = 0; i < list.length; i++ ) {
				try {
					if ( failedAlready( list[ i ] ) ) {
						degrade( list[ i ] );
					}
				} catch ( e ) {
					// Next image.
				}
			}
		} catch ( e ) {
			// Nothing to sweep.
		}
	}

	try {
		document.addEventListener( 'error', onError, true );
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', sweep );
		} else {
			sweep();
		}
	} catch ( e ) {
		// No listener, no fallback: the page still works.
	}
}() );
