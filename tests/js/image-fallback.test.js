'use strict';
const assert = require( 'assert' ).strict;
const { El, run } = require( './fake-dom' );

/** An <img> as the templates print it. `state` sets what the browser reports about the load. */
function img( className, kind, attrs, state ) {
	const el = new El( className, Object.assign( { _tag: 'IMG', 'data-odv-img': kind, src: 'https://odavent.com/storage/x.webp' }, attrs ) );
	Object.assign( el, { complete: false, naturalWidth: 0, currentSrc: '' }, state );
	return el;
}
const failed = { complete: true, naturalWidth: 0, currentSrc: 'https://odavent.com/storage/x.webp' };
const loaded = { complete: true, naturalWidth: 96, currentSrc: 'https://odavent.com/storage/x.webp' };

const photo = ( state ) => img( 'odv-speakers__photo', 'photo', { alt: 'Marcel Djaman', 'data-odv-initials': 'MD', loading: 'lazy' }, state );
const logo = ( state ) => img( 'odv-sponsors__logo', 'logo', { alt: 'Société Générale', loading: 'lazy' }, state );
const cover = ( state ) => img( 'odv-header__cover', 'cover', { alt: '' }, state );
const headerLogo = ( state ) => img( 'odv-header__logo', 'logo', { alt: 'Sommet Tech' }, state );

/** Runs the script on a page holding these images (each in its own parent) and returns the parents. */
function page( images, doc ) {
	const parents = images.map( ( image ) => new El( 'wrapper', {}, [ image ] ) );
	const result = run( 'image-fallback.js', images, { t: 0 } );
	if ( doc ) {
		doc.current = result.doc;
	}
	return { parents, doc: result.doc };
}
/** Fires an error event the way a browser does: capture phase, target = the image. */
const fail = ( doc, image ) => doc.listeners.error.fn( { target: image } );
const only = ( parent ) => {
	assert.equal( parent.children.length, 1, 'exactly one child' );
	return parent.children[ 0 ];
};

// The listener is on the document, in the capture phase (image errors do not bubble).
( function () {
	const { doc } = page( [ photo() ] );
	assert.ok( doc.listeners.error, 'an error listener is registered on the document' );
	assert.equal( doc.listeners.error.capture, true, 'in the capture phase' );
}() );

// A failed speaker photo becomes the initials placeholder.
( function () {
	const image = photo();
	const { parents, doc } = page( [ image ] );
	fail( doc, image );
	const span = only( parents[ 0 ] );
	assert.equal( span.tagName, 'SPAN' );
	assert.equal( span.className, 'odv-speakers__photo odv-speakers__photo--placeholder' );
	assert.equal( span.getAttribute( 'aria-hidden' ), 'true' );
	assert.equal( span.textContent, 'MD' );
	assert.equal( image.parentNode, null );
}() );

// A failed sponsor logo becomes the name, inside the same parent (a link, in the template).
( function () {
	const image = logo();
	const { parents, doc } = page( [ image ] );
	fail( doc, image );
	const span = only( parents[ 0 ] );
	assert.equal( span.tagName, 'SPAN' );
	assert.equal( span.className, 'odv-sponsors__name' );
	assert.equal( span.textContent, 'Société Générale' );
}() );

// A failed cover and a failed header logo are removed.
( function () {
	const c = cover();
	const l = headerLogo();
	const { parents, doc } = page( [ c, l ] );
	fail( doc, c );
	fail( doc, l );
	assert.equal( parents[ 0 ].children.length, 0, 'cover removed' );
	assert.equal( parents[ 1 ].children.length, 0, 'header logo removed, not turned into a sponsor name' );
}() );

// A sponsor logo with no alt text has no name to show: it is removed.
( function () {
	const image = img( 'odv-sponsors__logo', 'logo', { alt: '' } );
	const { parents, doc } = page( [ image ] );
	fail( doc, image );
	assert.equal( parents[ 0 ].children.length, 0 );
}() );

// An image that failed before the script ran is handled by the sweep at load.
( function () {
	const p = photo( failed );
	const s = logo( failed );
	const c = cover( failed );
	const { parents } = page( [ p, s, c ] );
	assert.equal( only( parents[ 0 ] ).className, 'odv-speakers__photo odv-speakers__photo--placeholder' );
	assert.equal( only( parents[ 1 ] ).textContent, 'Société Générale' );
	assert.equal( parents[ 2 ].children.length, 0 );
}() );

// A working image is untouched by the sweep, and an error event for something else is ignored.
( function () {
	const good = photo( loaded );
	const { parents, doc } = page( [ good ] );
	assert.equal( only( parents[ 0 ] ), good );
	assert.equal( good.parentNode, parents[ 0 ] );
	doc.listeners.error.fn( { target: new El( 'other', { _tag: 'IMG' } ) } );
	doc.listeners.error.fn( { target: new El( 'other', { _tag: 'SCRIPT', 'data-odv-img': 'photo' } ) } );
	doc.listeners.error.fn( {} );
	doc.listeners.error.fn( null );
	assert.equal( only( parents[ 0 ] ), good );
}() );

// The sweep leaves alone what has not failed: still loading, no source yet, lazy and not started, SVG.
( function () {
	const loading = photo( { complete: false } );
	const noSource = img( 'odv-speakers__photo', 'photo', { alt: 'A', 'data-odv-initials': 'A' }, { complete: true } );
	delete noSource.attrs.src;
	const emptySource = img( 'odv-speakers__photo', 'photo', { alt: 'A', 'data-odv-initials': 'A', src: '' }, { complete: true } );
	// Some browsers report complete with no pixels for a lazy image that has not started loading: no currentSrc yet.
	const lazyIdle = photo( { complete: true, currentSrc: '' } );
	const svg = img( 'odv-sponsors__logo', 'logo', { alt: 'Svg Co', loading: 'lazy' }, { complete: true, currentSrc: 'https://odavent.com/storage/logo.SVG?v=2' } );
	const images = [ loading, noSource, emptySource, lazyIdle, svg ];
	const { parents } = page( images );
	images.forEach( ( image, i ) => assert.equal( only( parents[ i ] ), image, 'image ' + i + ' untouched' ) );
}() );

// A non-lazy image that failed with only a src attribute (no currentSrc reported) is still handled.
( function () {
	const image = img( 'odv-header__cover', 'cover', { alt: '' }, { complete: true, currentSrc: '' } );
	const { parents } = page( [ image ] );
	assert.equal( parents[ 0 ].children.length, 0 );
}() );

// Running twice does not double-handle: a second event, the sweep after the event, a second copy of the script.
( function () {
	const image = photo();
	const { parents, doc } = page( [ image ] );
	fail( doc, image );
	let made = 0;
	const create = doc.createElement;
	doc.createElement = ( tag ) => {
		made++;
		return create( tag );
	};
	fail( doc, image );
	assert.equal( made, 0, 'the second event builds nothing' );
	const span = only( parents[ 0 ] );
	assert.equal( span.textContent, 'MD' );

	// The same, with the failure already known at load and the event arriving as well.
	const early = logo( failed );
	const second = page( [ early ] );
	fail( second.doc, early );
	assert.equal( only( second.parents[ 0 ] ).textContent, 'Société Générale' );

	// A second run over the page the first already fixed finds nothing left to do.
	run( 'image-fallback.js', [ image ], { t: 0 } );
	assert.equal( only( parents[ 0 ] ), span, 'the placeholder is still the only child' );
}() );

// A page that is still parsing sweeps on DOMContentLoaded, not before.
( function () {
	const fs = require( 'fs' );
	const path = require( 'path' );
	const vm = require( 'vm' );
	const image = photo( failed );
	const parent = new El( 'wrapper', {}, [ image ] );
	const handlers = {};
	const doc = {
		readyState: 'loading',
		querySelectorAll: () => [ image ],
		createElement: ( tag ) => new El( '', { _tag: tag.toUpperCase() } ),
		addEventListener: ( type, fn ) => {
			handlers[ type ] = fn;
		},
	};
	vm.runInNewContext( fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'js', 'image-fallback.js' ), 'utf8' ), { document: doc, String } );
	assert.equal( only( parent ), image, 'nothing swept while parsing' );
	handlers.DOMContentLoaded();
	assert.equal( only( parent ).className, 'odv-speakers__photo odv-speakers__photo--placeholder' );
}() );

// Never throws: an image with no parent, or a broken DOM, is skipped.
( function () {
	const orphan = photo( failed );
	const { doc } = run( 'image-fallback.js', [ orphan ], { t: 0 } );
	assert.doesNotThrow( () => doc.listeners.error.fn( { target: orphan } ) );
	const hostile = { tagName: 'IMG', getAttribute: () => 'photo', className: '', parentNode: { replaceChild() { throw new Error( 'boom' ); }, removeChild() { throw new Error( 'boom' ); } } };
	assert.doesNotThrow( () => doc.listeners.error.fn( { target: hostile } ) );
}() );

console.log( 'image-fallback.test.js ok' );
