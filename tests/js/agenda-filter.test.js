'use strict';
const assert = require( 'assert' ).strict;
const { El, run } = require( './fake-dom' );

const session = ( track, rooms ) => new El( 'odv-agenda__session', { 'data-track': track, _tag: 'LI' }, rooms ? [ new El( 'odv-agenda__rooms', {}, rooms ) ] : [] );
const button = ( track ) => new El( 'odv-agenda__filter', { 'data-track': track, 'aria-pressed': track === '' ? 'true' : 'false', _tag: 'BUTTON' } );

const room = session( 'Other' );
room.className = 'odv-agenda__session odv-agenda__session--child';
const a = session( 'A', [ room ] );
const b = session( 'B' );
const c = session( 'B' );
const day1 = new El( 'odv-agenda__day', {}, [ new El( 'odv-agenda__list', {}, [ a, b ] ) ] );
const day2 = new El( 'odv-agenda__day', {}, [ new El( 'odv-agenda__list', {}, [ c ] ) ] );
const [ all, onlyA, onlyB ] = [ button( '' ), button( 'A' ), button( 'B' ) ];
const root = new El( 'odv-agenda', {}, [ new El( 'odv-agenda__filters', {}, [ all, onlyA, onlyB ] ), day1, day2 ] );
const noFilters = new El( 'odv-agenda', {}, [ day1 ] );
const hidden = ( el ) => el.hasAttribute( 'hidden' );
const click = ( target ) => root.listeners.click( { target } );

// Two agendas on the page (one without buttons) and the script run twice: one handler on the first only.
run( 'agenda-filter.js', [ root, noFilters ], { t: 0 } );
run( 'agenda-filter.js', [ root, noFilters ], { t: 0 } );
assert.equal( Object.keys( noFilters.listeners ).length, 0, 'no buttons, no handler' );

click( onlyA );
assert.ok( !hidden( a ) );
assert.ok( hidden( b ) );
assert.ok( !hidden( room ), 'a room is never toggled on its own' );
assert.ok( hidden( day2 ), 'a day with nothing left is hidden' );
assert.ok( !hidden( day1 ) );
assert.equal( onlyA.getAttribute( 'aria-pressed' ), 'true' );
assert.equal( all.getAttribute( 'aria-pressed' ), 'false' );

click( onlyB );
assert.ok( hidden( a ), 'a parent hides, taking its room with it' );
assert.ok( !hidden( room ) );
assert.ok( !hidden( day2 ) );

click( all );
assert.ok( !hidden( a ) && !hidden( b ) && !hidden( day2 ), 'All shows everything' );
assert.equal( all.getAttribute( 'aria-pressed' ), 'true' );

root.listeners.click( { target: root } ); // a click outside any button does nothing
assert.equal( root.getAttribute( 'data-odv-ready' ), '1' );

console.log( 'agenda-filter.test.js ok' );
