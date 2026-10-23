'use strict';
const assert = require( 'assert' ).strict;
const { El, run } = require( './fake-dom' );

const STARTS = '2026-11-12T09:00:00+00:00';
const ENDS = '2026-11-13T18:00:00+00:00';
const at = ( iso ) => ( { t: Date.parse( iso ) } );

/** Markup like templates/countdown.php; the timer exists only for the upcoming state. */
function block( state, extra ) {
	const unit = ( n ) => new El( 'odv-countdown__value', { 'data-unit': n } );
	const kids = [];
	if ( state === 'upcoming' ) {
		kids.push( new El( 'odv-countdown__timer', {}, [ 'days', 'hours', 'minutes', 'seconds' ].map( unit ) ) );
	}
	const message = new El( 'odv-countdown__message', state === 'upcoming' ? { hidden: '' } : {} );
	message.textContent = state === 'live' ? 'Live now' : state === 'ended' ? 'Event ended' : '';
	kids.push( message );
	return new El(
		'odv-countdown',
		Object.assign( { 'data-starts-at': STARTS, 'data-ends-at': ENDS, 'data-state': state, 'data-label-live': 'Live now', 'data-label-ended': 'Event ended' }, extra ),
		kids
	);
}
const value = ( root, unit ) => root.querySelector( '[data-unit="' + unit + '"]' ).textContent;
const message = ( root ) => root.querySelector( '.odv-countdown__message' );

// Initial values, tick, upcoming -> live -> ended, interval cleared.
( function () {
	const root = block( 'upcoming' );
	const clock = at( '2026-11-10T05:55:55+00:00' );
	const { timers } = run( 'countdown.js', [ root ], clock );
	assert.equal( [ 'days', 'hours', 'minutes', 'seconds' ].map( ( u ) => value( root, u ) ).join( ':' ), '2:03:04:05' );
	clock.t += 1000;
	timers[ 0 ]();
	assert.equal( value( root, 'seconds' ), '04' );

	clock.t = Date.parse( STARTS );
	timers[ 0 ]();
	assert.equal( root.getAttribute( 'data-state' ), 'live' );
	assert.equal( message( root ).textContent, 'Live now' );
	assert.ok( root.querySelector( '.odv-countdown__timer' ).hasAttribute( 'hidden' ) );
	assert.ok( !message( root ).hasAttribute( 'hidden' ) );

	clock.t = Date.parse( '2026-11-14T00:00:00+00:00' );
	timers[ 0 ]();
	assert.equal( root.getAttribute( 'data-state' ), 'ended' );
	assert.equal( message( root ).textContent, 'Event ended' );
	assert.equal( timers[ 0 ], null, 'interval cleared once ended' );
}() );

// A page cached as "upcoming" and loaded after the end is corrected, with no interval.
( function () {
	const root = block( 'upcoming' );
	const { timers } = run( 'countdown.js', [ root ], at( '2026-11-14T00:00:00+00:00' ) );
	assert.equal( root.getAttribute( 'data-state' ), 'ended' );
	assert.equal( timers.length, 0 );
}() );

// Running the script twice does not double up.
( function () {
	const root = block( 'upcoming' );
	const clock = at( '2026-11-10T00:00:00+00:00' );
	assert.equal( run( 'countdown.js', [ root ], clock ).timers.length, 1 );
	assert.equal( run( 'countdown.js', [ root ], clock ).timers.length, 0, 'second run is a no-op' );
}() );

// Live without an end date stays live and needs no interval; a bad start date is ignored.
( function () {
	const noEnd = block( 'live', { 'data-ends-at': undefined } );
	delete noEnd.attrs[ 'data-ends-at' ];
	assert.equal( run( 'countdown.js', [ noEnd ], at( '2027-01-01T00:00:00Z' ) ).timers.length, 0 );
	assert.equal( noEnd.getAttribute( 'data-state' ), 'live' );

	const bad = block( 'upcoming', { 'data-starts-at': 'garbage' } );
	assert.equal( run( 'countdown.js', [ bad ], at( '2026-11-10T00:00:00Z' ) ).timers.length, 0 );
}() );

// Odavent flagged the event ended: it stays ended whatever the clock says.
( function () {
	const root = block( 'ended', { 'data-ended': 'true' } );
	run( 'countdown.js', [ root ], at( '2026-01-01T00:00:00Z' ) );
	assert.equal( root.getAttribute( 'data-state' ), 'ended' );
	assert.equal( message( root ).textContent, 'Event ended' );
}() );

// Never steps back past the server's state: a slow clock must not blank a block rendered live or ended.
( function () {
	const live = block( 'live' );
	const { timers } = run( 'countdown.js', [ live ], at( '2026-11-01T00:00:00Z' ) );
	assert.equal( live.getAttribute( 'data-state' ), 'live' );
	assert.equal( message( live ).textContent, 'Live now' );
	assert.ok( !message( live ).hasAttribute( 'hidden' ) );
	assert.equal( timers.length, 1, 'still waiting for the end' );
	timers[ 0 ]();
	assert.equal( message( live ).textContent, 'Live now', 'a tick does not blank it either' );

	const ended = block( 'ended', { 'data-ended': undefined } );
	delete ended.attrs[ 'data-ended' ];
	const second = run( 'countdown.js', [ ended ], at( '2026-11-01T00:00:00Z' ) );
	assert.equal( ended.getAttribute( 'data-state' ), 'ended' );
	assert.equal( message( ended ).textContent, 'Event ended' );
	assert.equal( second.timers.length, 0 );
}() );

// A paused event: while upcoming it counts down; once the start has passed the whole block is hidden, with no label.
( function () {
	const root = block( 'upcoming', { 'data-odv-paused': '1' } );
	const clock = at( '2026-11-10T00:00:00Z' );
	const { timers } = run( 'countdown.js', [ root ], clock );
	assert.ok( !root.hasAttribute( 'hidden' ) );
	assert.equal( timers.length, 1 );

	clock.t = Date.parse( STARTS );
	timers[ 0 ]();
	assert.ok( root.hasAttribute( 'hidden' ), 'block hidden at the start time' );
	assert.equal( message( root ).textContent, '', 'no live label' );
	assert.ok( message( root ).hasAttribute( 'hidden' ) );
	assert.equal( root.getAttribute( 'data-state' ), 'upcoming', 'state not advanced' );
	assert.equal( timers[ 0 ], null, 'interval cleared' );

	clock.t = Date.parse( '2026-11-14T00:00:00Z' );
	assert.equal( message( root ).textContent, '', 'and no ended label later' );

	// Loaded from a page cache after the start: hidden at once, no interval.
	const cached = block( 'upcoming', { 'data-odv-paused': '1' } );
	const late = run( 'countdown.js', [ cached ], at( '2026-11-13T00:00:00Z' ) );
	assert.ok( cached.hasAttribute( 'hidden' ) );
	assert.equal( message( cached ).textContent, '' );
	assert.equal( late.timers.length, 0 );

	// Not paused: the same moment shows "Live now".
	const normal = block( 'upcoming' );
	run( 'countdown.js', [ normal ], at( '2026-11-12T10:00:00Z' ) );
	assert.ok( !normal.hasAttribute( 'hidden' ) );
	assert.equal( message( normal ).textContent, 'Live now' );
}() );

console.log( 'countdown.test.js ok' );
