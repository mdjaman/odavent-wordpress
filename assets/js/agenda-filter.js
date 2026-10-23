/**
 * Odavent Events: filter the agenda by track.
 *
 * Progressive enhancement: without this script every session is visible. Each .odv-agenda that has filter
 * buttons gets one click handler (event delegation). A button's data-track is the track it shows ("" is
 * "All"); sessions carry data-track. Only top-level sessions are toggled, so a breakout room always stays
 * with its parent session, and a day with nothing left to show is hidden too.
 */
( function () {
	'use strict';

	function setHidden( element, hidden ) {
		if ( hidden ) {
			element.setAttribute( 'hidden', '' );
		} else {
			element.removeAttribute( 'hidden' );
		}
	}

	function isHidden( element ) {
		return element.hasAttribute( 'hidden' );
	}

	function isRoom( session, root ) {
		for ( var node = session.parentNode; node && node !== root; node = node.parentNode ) {
			if ( node.className && String( node.className ).indexOf( 'odv-agenda__rooms' ) !== -1 ) {
				return true;
			}
		}
		return false;
	}

	function apply( root, track ) {
		var sessions = root.querySelectorAll( '.odv-agenda__session' );
		var i;
		for ( i = 0; i < sessions.length; i++ ) {
			if ( !isRoom( sessions[ i ], root ) ) {
				setHidden( sessions[ i ], track !== '' && sessions[ i ].getAttribute( 'data-track' ) !== track );
			}
		}

		var days = root.querySelectorAll( '.odv-agenda__day' );
		for ( i = 0; i < days.length; i++ ) {
			var visible = false;
			var inDay = days[ i ].querySelectorAll( '.odv-agenda__session' );
			for ( var j = 0; j < inDay.length; j++ ) {
				if ( !isRoom( inDay[ j ], root ) && !isHidden( inDay[ j ] ) ) {
					visible = true;
					break;
				}
			}
			setHidden( days[ i ], !visible && inDay.length > 0 );
		}

		var buttons = root.querySelectorAll( '.odv-agenda__filter' );
		for ( i = 0; i < buttons.length; i++ ) {
			buttons[ i ].setAttribute( 'aria-pressed', buttons[ i ].getAttribute( 'data-track' ) === track ? 'true' : 'false' );
		}
	}

	function setup( root ) {
		if ( root.getAttribute( 'data-odv-ready' ) === '1' || !root.querySelector( '.odv-agenda__filter' ) ) {
			return;
		}
		root.setAttribute( 'data-odv-ready', '1' );

		root.addEventListener( 'click', function ( event ) {
			var target = event.target;
			while ( target && target !== root && !( target.className && String( target.className ).indexOf( 'odv-agenda__filter' ) !== -1 && target.tagName === 'BUTTON' ) ) {
				target = target.parentNode;
			}
			if ( !target || target === root ) {
				return;
			}
			apply( root, target.getAttribute( 'data-track' ) || '' );
		} );
	}

	function init() {
		var roots = document.querySelectorAll( '.odv-agenda' );
		for ( var i = 0; i < roots.length; i++ ) {
			setup( roots[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
