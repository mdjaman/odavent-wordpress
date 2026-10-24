'use strict';
// assets/js/admin-appearance.js against the Appearance fields and preview of Settings > Odavent Events: the preview
// follows the inputs through the same custom properties the site uses, and shows the text-on-accent ratio.
const assert = require( 'assert' ).strict;
const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );
const { El } = require( './fake-dom' );

const source = fs.readFileSync( path.join( __dirname, '../../assets/js/admin-appearance.js' ), 'utf8' );
const PREFIX = 'odavent_events_appearance_';

function style() {
	return {
		props: {},
		setProperty( k, v ) {
			this.props[ k ] = String( v );
		},
		removeProperty( k ) {
			delete this.props[ k ];
		},
		getPropertyValue( k ) {
			return this.props[ k ] || '';
		},
	};
}

/** The page: inputs by id and one preview. Values are the server-rendered ones. */
function page( values ) {
	const byId = {};
	const input = ( name, value, extra ) => {
		const el = new El( '', Object.assign( { id: PREFIX + name, _tag: 'INPUT' }, extra || {} ) );
		el.value = value;
		byId[ PREFIX + name ] = el;
		return el;
	};
	[ 'accent', 'contrast', 'text', 'border' ].forEach( ( name ) => input( name, values[ name ] || '', { class: 'odv-color-field' } ) );
	input( 'radius', values.radius || '', { min: '0', max: '24' } );
	input( 'spacing', values.spacing || '' );
	input( 'button_style', values.button_style || 'filled' );
	input( 'contrast_auto', '1' ).checked = values.auto !== false;
	byId[ PREFIX + 'contrast_manual' ] = new El( '', values.auto !== false ? { hidden: '' } : {} );

	const ratio = new El( 'odv-appearance-preview__ratio' );
	const warning = new El( 'odv-appearance-preview__warning', { hidden: '' } );
	const preview = new El(
		'odv-appearance-preview odv-appearance-preview--filled',
		{
			'data-ratio-label': 'Text on accent: %s:1',
			'data-decimal': '.',
			'data-gaps': JSON.stringify( { compact: '0.75rem', comfortable: '1rem', roomy: '1.5rem' } ),
		},
		[ ratio, warning ]
	);
	preview.style = style();
	return { byId, preview, ratio, warning, field: ( name ) => byId[ PREFIX + name ] };
}

function run( p, jQuery ) {
	const sandbox = {
		document: {
			readyState: 'complete',
			querySelectorAll: ( selector ) => ( selector === '.odv-appearance-preview' ? [ p.preview ] : [] ),
			getElementById: ( id ) => p.byId[ id ] || null,
			addEventListener() {},
		},
		JSON,
		Math,
		Object,
		String,
		Number,
		parseInt,
		isNaN,
	};
	if ( jQuery ) {
		sandbox.jQuery = jQuery;
	}
	vm.runInNewContext( source, sandbox );
}

const prop = ( p, name ) => p.preview.style.getPropertyValue( name );
const change = ( p, name, value ) => {
	p.field( name ).value = value;
	p.field( name ).listeners.input();
};

// test_preview_follows_the_inputs
( function () {
	const p = page( { accent: '#1e3a8a', contrast: '#fefefe', auto: false } );
	run( p );

	assert.equal( prop( p, '--odv-accent' ), '#1e3a8a', 'the saved values are applied on load' );
	assert.equal( prop( p, '--odv-accent-contrast' ), '#fefefe' );
	assert.equal( p.ratio.textContent, 'Text on accent: 10.27:1' );
	assert.ok( p.warning.hasAttribute( 'hidden' ) );
	// Unset fields block the saved :root values so the preview shows the stylesheet defaults.
	assert.equal( prop( p, '--odv-text' ), 'initial' );
	assert.equal( prop( p, '--odv-border' ), 'initial' );
	assert.equal( prop( p, '--odv-radius' ), 'initial' );
	assert.equal( prop( p, '--odv-gap' ), 'initial' );

	change( p, 'accent', '#777777' );
	change( p, 'contrast', '#FFF' );
	assert.equal( prop( p, '--odv-accent' ), '#777777', 'typing updates the preview' );
	assert.equal( prop( p, '--odv-accent-contrast' ), '#ffffff' );
	assert.equal( p.ratio.textContent, 'Text on accent: 4.48:1' );
	assert.ok( !p.warning.hasAttribute( 'hidden' ), 'below 4.5 the warning shows' );

	change( p, 'contrast', '#000000' );
	assert.ok( p.warning.hasAttribute( 'hidden' ), 'a readable pair hides it again' );

	change( p, 'accent', 'red;}body{display:none' );
	assert.equal( prop( p, '--odv-accent' ), 'initial', 'an invalid colour is never written' );
	assert.ok( p.ratio.hasAttribute( 'hidden' ), 'no accent, no ratio (as on save)' );
	assert.ok( p.warning.hasAttribute( 'hidden' ) );

	change( p, 'text', '#111' );
	change( p, 'border', '#cccccc' );
	change( p, 'radius', '12' );
	change( p, 'spacing', 'roomy' );
	assert.equal( prop( p, '--odv-text' ), '#111111' );
	assert.equal( prop( p, '--odv-border' ), '#cccccc' );
	assert.equal( prop( p, '--odv-radius' ), '12px' );
	assert.equal( prop( p, '--odv-gap' ), '1.5rem' );

	change( p, 'radius', '99' );
	assert.equal( prop( p, '--odv-radius' ), '24px', 'clamped like on save' );
	change( p, 'radius', '-3' );
	assert.equal( prop( p, '--odv-radius' ), '0px' );
	change( p, 'radius', '1e3' );
	assert.equal( prop( p, '--odv-radius' ), 'initial' );
	change( p, 'spacing', 'constructor' );
	assert.equal( prop( p, '--odv-gap' ), 'initial', 'only the listed spacings' );

	change( p, 'button_style', 'outline' );
	assert.ok( / odv-appearance-preview--outline\b/.test( ' ' + p.preview.className ) );
	assert.ok( !/--filled\b/.test( p.preview.className ) );
	change( p, 'button_style', 'filled' );
	assert.ok( /odv-appearance-preview--filled\b/.test( p.preview.className ) );
	assert.ok( !/--outline\b/.test( p.preview.className ) );
	assert.ok( /^odv-appearance-preview\b/.test( p.preview.className ), 'the base class stays' );

	// Run twice: still one set of listeners, and the preview is set up once.
	run( p );
	assert.equal( p.preview.getAttribute( 'data-odv-ready' ), '1' );
}() );

// test_automatic_text_color_tracks_the_accent
( function () {
	const p = page( { accent: '#1e3a8a', contrast: '#ffeb3b' } );
	run( p );

	assert.equal( prop( p, '--odv-accent-contrast' ), '#ffffff', 'automatic ignores the hidden manual value' );
	change( p, 'accent', '#ffeb3b' );
	assert.equal( prop( p, '--odv-accent-contrast' ), '#000000', 'a light accent gets dark text' );
	assert.ok( p.warning.hasAttribute( 'hidden' ) );
	change( p, 'accent', '#3730a3' );
	assert.equal( prop( p, '--odv-accent-contrast' ), '#ffffff' );

	change( p, 'accent', '' );
	assert.equal( prop( p, '--odv-accent-contrast' ), 'initial', 'no accent: the stylesheet default pair' );

	// Unchecking Automatic shows the manual picker and uses its value.
	change( p, 'accent', '#3730a3' );
	p.field( 'contrast_auto' ).checked = false;
	p.field( 'contrast_auto' ).listeners.change();
	assert.ok( !p.byId[ PREFIX + 'contrast_manual' ].hasAttribute( 'hidden' ) );
	assert.equal( prop( p, '--odv-accent-contrast' ), '#ffeb3b' );

	p.field( 'contrast_auto' ).checked = true;
	p.field( 'contrast_auto' ).listeners.change();
	assert.ok( p.byId[ PREFIX + 'contrast_manual' ].hasAttribute( 'hidden' ) );
	assert.equal( prop( p, '--odv-accent-contrast' ), '#ffffff' );
}() );

// test_color_picker_changes_update_the_preview
( function () {
	const p = page( { accent: '' } );
	const pickers = [];
	const jQuery = function ( el ) {
		return {
			wpColorPicker( options ) {
				pickers.push( { el, options } );
			},
		};
	};
	jQuery.fn = { wpColorPicker() {} };
	p.field( 'border' ).disabled = true;
	run( p, jQuery );

	assert.deepEqual(
		pickers.map( ( x ) => x.el.getAttribute( 'id' ) ),
		[ PREFIX + 'accent', PREFIX + 'contrast', PREFIX + 'text' ],
		'one picker per enabled colour field'
	);
	const accent = pickers[ 0 ].options;
	accent.change( {}, { color: { toString: () => '#ffeb3b' } } );
	assert.equal( prop( p, '--odv-accent' ), '#ffeb3b', 'the picker value, even before the input catches up' );
	assert.equal( prop( p, '--odv-accent-contrast' ), '#000000' );
	accent.clear();
	assert.equal( prop( p, '--odv-accent' ), 'initial' );
}() );

// A preview without its fields (another plugin's page reusing the class) does nothing and never throws.
( function () {
	const p = page( {} );
	p.byId = {};
	run( p );
	assert.equal( prop( p, '--odv-accent' ), 'initial' );
}() );

console.log( 'admin-appearance.test.js ok' );
