/**
 * Odavent Events: countdown to the event start.
 *
 * Reads only the data attributes of each .odv-countdown block (data-starts-at, data-ends-at, data-ended,
 * data-label-live, data-label-ended), ticks every second, and switches to the "live" and "ended" labels.
 * It recomputes from the clock, so a page that was cached before the event started is corrected on load.
 * Safe to run twice and with several countdowns on a page.
 */
( function () {
	'use strict';

	var SECOND = 1000;
	var UNITS = [ 'days', 'hours', 'minutes', 'seconds' ];

	function pad( number ) {
		return number < 10 ? '0' + number : String( number );
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

	function setup( root ) {
		if ( root.getAttribute( 'data-odv-ready' ) === '1' ) {
			return;
		}
		root.setAttribute( 'data-odv-ready', '1' );

		var starts = Date.parse( root.getAttribute( 'data-starts-at' ) || '' );
		if ( isNaN( starts ) ) {
			return;
		}
		var ends = Date.parse( root.getAttribute( 'data-ends-at' ) || '' );
		var flaggedEnded = root.getAttribute( 'data-ended' ) === 'true';
		var timer = root.querySelector( '.odv-countdown__timer' );
		var message = root.querySelector( '.odv-countdown__message' );
		var fields = {};
		var i;
		for ( i = 0; i < UNITS.length; i++ ) {
			fields[ UNITS[ i ] ] = root.querySelector( '[data-unit="' + UNITS[ i ] + '"]' );
		}
		var interval = null;

		function stateAt( now ) {
			if ( flaggedEnded || ( !isNaN( ends ) && now >= ends ) ) {
				return 'ended';
			}
			return now >= starts ? 'live' : 'upcoming';
		}

		function show( state ) {
			if ( root.getAttribute( 'data-state' ) === state ) {
				return;
			}
			root.setAttribute( 'data-state', state );
			setHidden( timer, state !== 'upcoming' );
			if ( message ) {
				message.textContent = state === 'live' ? ( root.getAttribute( 'data-label-live' ) || '' ) : ( state === 'ended' ? ( root.getAttribute( 'data-label-ended' ) || '' ) : '' );
				setHidden( message, state === 'upcoming' );
			}
		}

		// Nothing more can change once the event has ended, or is live with no end date to wait for.
		function settled( state ) {
			return state === 'ended' || ( state === 'live' && isNaN( ends ) );
		}

		function tick() {
			var now = Date.now();
			var state = stateAt( now );

			show( state );

			if ( state === 'upcoming' ) {
				var left = Math.max( 0, Math.floor( ( starts - now ) / SECOND ) );
				var values = {
					days: String( Math.floor( left / 86400 ) ),
					hours: pad( Math.floor( ( left % 86400 ) / 3600 ) ),
					minutes: pad( Math.floor( ( left % 3600 ) / 60 ) ),
					seconds: pad( left % 60 )
				};
				for ( var j = 0; j < UNITS.length; j++ ) {
					if ( fields[ UNITS[ j ] ] ) {
						fields[ UNITS[ j ] ].textContent = values[ UNITS[ j ] ];
					}
				}
			}

			if ( settled( state ) && interval !== null ) {
				clearInterval( interval );
				interval = null;
			}
		}

		tick();
		if ( !settled( stateAt( Date.now() ) ) ) {
			interval = setInterval( tick, SECOND );
		}
	}

	function init() {
		var roots = document.querySelectorAll( '.odv-countdown' );
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
