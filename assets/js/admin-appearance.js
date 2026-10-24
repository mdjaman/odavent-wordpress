/**
 * Odavent Events: live preview of the Appearance settings (Settings > Odavent Events).
 *
 * Reads the Appearance fields (ids odavent_events_appearance_*) and writes the same custom properties the site
 * uses (--odv-accent, --odv-accent-contrast, --odv-text, --odv-border, --odv-radius, --odv-gap) on the
 * .odv-appearance-preview element, whose cards use the plugin's real classes and stylesheet. An unset field is
 * written as `initial`, which blocks the saved values on :root, so the preview falls back to the stylesheet
 * defaults exactly like the site will. Only validated values are written: #rrggbb colours, a radius clamped to the
 * field's min and max, and the spacings listed in data-gaps.
 *
 * The text-on-accent ratio follows the same rules as the server (WCAG 2.x, two decimals, automatic text is black
 * or white, whichever reads better); its label comes from data-ratio-label ("%s" is the ratio), so the script has
 * no strings of its own. Colour fields get the WordPress colour picker when it is loaded.
 * Safe to run twice.
 */
( function () {
	'use strict';

	var PREFIX = 'odavent_events_appearance_';
	var COLORS = [ 'accent', 'contrast', 'text', 'border' ];
	var FIELDS = [ 'accent', 'contrast', 'text', 'border', 'radius', 'spacing', 'button_style', 'contrast_auto' ];
	var MIN_RATIO = 4.5;
	var BASE = 'odv-appearance-preview';

	function hex( value ) {
		var match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec( String( value || '' ).replace( /^\s+|\s+$/g, '' ) );
		if ( !match ) {
			return '';
		}
		var digits = match[ 1 ].toLowerCase();
		if ( digits.length === 3 ) {
			digits = digits.charAt( 0 ) + digits.charAt( 0 ) + digits.charAt( 1 ) + digits.charAt( 1 ) + digits.charAt( 2 ) + digits.charAt( 2 );
		}
		return '#' + digits;
	}

	function luminance( color ) {
		var channels = [];
		var i;
		for ( i = 1; i < 7; i += 2 ) {
			var c = parseInt( color.substr( i, 2 ), 16 ) / 255;
			channels.push( c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ) );
		}
		return 0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ];
	}

	function ratio( a, b ) {
		var la = luminance( a );
		var lb = luminance( b );
		return Math.round( ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 ) * 100 ) / 100;
	}

	function autoContrast( color ) {
		return ratio( color, '#000000' ) > ratio( color, '#ffffff' ) ? '#000000' : '#ffffff';
	}

	function setHidden( element, hidden ) {
		if ( !element ) {
			return;
		}
		if ( hidden ) {
			element.setAttribute( 'hidden', '' );
		} else {
			element.removeAttribute( 'hidden' );
		}
	}

	function field( name ) {
		return document.getElementById( PREFIX + name );
	}

	function value( name ) {
		var element = field( name );
		return element && typeof element.value === 'string' ? element.value : '';
	}

	function gapsOf( preview ) {
		try {
			var gaps = JSON.parse( preview.getAttribute( 'data-gaps' ) || '{}' );
			return gaps && typeof gaps === 'object' ? gaps : {};
		} catch ( e ) {
			return {};
		}
	}

	function radiusOf() {
		var raw = value( 'radius' ).replace( /^\s+|\s+$/g, '' );
		if ( !/^-?\d+$/.test( raw ) ) {
			return '';
		}
		var input = field( 'radius' );
		var min = parseInt( input.getAttribute( 'min' ), 10 );
		var max = parseInt( input.getAttribute( 'max' ), 10 );
		var number = parseInt( raw, 10 );
		number = isNaN( min ) ? number : Math.max( min, number );
		number = isNaN( max ) ? number : Math.min( max, number );
		return number + 'px';
	}

	function setup( preview ) {
		if ( preview.getAttribute( 'data-odv-ready' ) === '1' ) {
			return;
		}
		preview.setAttribute( 'data-odv-ready', '1' );

		var gaps = gapsOf( preview );
		var ratioText = preview.querySelector( '.odv-appearance-preview__ratio' );
		var warning = preview.querySelector( '.odv-appearance-preview__warning' );
		var label = preview.getAttribute( 'data-ratio-label' ) || '%s';
		var decimal = preview.getAttribute( 'data-decimal' ) || '.';

		function setVar( name, val ) {
			preview.style.setProperty( name, val || 'initial' );
		}

		function update() {
			var auto = field( 'contrast_auto' );
			var automatic = auto ? !!auto.checked : true;
			var accent = hex( value( 'accent' ) );
			var manual = automatic ? '' : hex( value( 'contrast' ) );
			var contrast = manual || ( accent ? autoContrast( accent ) : '' );
			var spacing = value( 'spacing' );
			var outline = value( 'button_style' ) === 'outline';

			setVar( '--odv-accent', accent );
			setVar( '--odv-accent-contrast', contrast );
			setVar( '--odv-text', hex( value( 'text' ) ) );
			setVar( '--odv-border', hex( value( 'border' ) ) );
			setVar( '--odv-radius', radiusOf() );
			setVar( '--odv-gap', Object.prototype.hasOwnProperty.call( gaps, spacing ) && typeof gaps[ spacing ] === 'string' ? gaps[ spacing ] : '' );
			setHidden( document.getElementById( PREFIX + 'contrast_manual' ), automatic );

			preview.className = String( preview.className )
				.replace( /(^|\s)odv-appearance-preview--(filled|outline)(?=\s|$)/g, '' )
				.replace( /^\s+|\s+$/g, '' ) + ' ' + BASE + ( outline ? '--outline' : '--filled' );

			// As on save: no accent of the organizer's own, no ratio to judge.
			if ( !accent ) {
				setHidden( ratioText, true );
				setHidden( warning, true );
				return;
			}
			var current = ratio( accent, contrast );
			if ( ratioText ) {
				ratioText.textContent = label.replace( /%(?:1\$)?s/, current.toFixed( 2 ).replace( '.', decimal ) );
			}
			setHidden( ratioText, false );
			setHidden( warning, current >= MIN_RATIO );
		}

		function picker( element ) {
			jQuery( element ).wpColorPicker( {
				// The picker calls back while dragging, before the input may hold the new value.
				change: function ( event, ui ) {
					if ( ui && ui.color ) {
						element.value = ui.color.toString();
					}
					update();
				},
				clear: function () {
					element.value = '';
					update();
				},
			} );
		}

		var i;
		for ( i = 0; i < FIELDS.length; i++ ) {
			var element = field( FIELDS[ i ] );
			if ( element ) {
				element.addEventListener( 'input', update );
				element.addEventListener( 'change', update );
			}
		}

		if ( typeof jQuery === 'function' && jQuery.fn && jQuery.fn.wpColorPicker ) {
			for ( i = 0; i < COLORS.length; i++ ) {
				var color = field( COLORS[ i ] );
				if ( color && !color.disabled ) {
					picker( color );
				}
			}
		}

		update();
	}

	function init() {
		var previews = document.querySelectorAll( '.odv-appearance-preview' );
		var i;
		for ( i = 0; i < previews.length; i++ ) {
			setup( previews[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
