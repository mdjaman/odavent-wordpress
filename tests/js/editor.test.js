'use strict';
// assets/js/editor.js with a fake `wp`: every wp.components form control it creates gets the shared opt-in props
// (__nextHasNoMarginBottom, and __next40pxDefaultSize except on ToggleControl). Without them WordPress 6.7-7.0
// log a deprecation warning per control in the editor (seen on 6.9 by bin/e2e).
const assert = require( 'assert' ).strict;
const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const source = fs.readFileSync( path.join( __dirname, '../../assets/js/editor.js' ), 'utf8' );
const FORM_CONTROLS = [ 'SelectControl', 'TextControl', 'ToggleControl', 'RangeControl' ];

function component( name ) {
	const fn = function () {
		return null;
	};
	fn.displayName = name;
	return fn;
}

/** Runs editor.js, renders every block's Edit (and the event picker in the given state); returns the controls. */
function render( pickerState, blocks ) {
	const registered = {};
	const components = {};
	[ 'Spinner', 'Notice', 'PanelBody', ...FORM_CONTROLS ].forEach( ( name ) => ( components[ name ] = component( name ) ) );

	const wp = {
		blocks: {
			getBlockType: () => undefined,
			registerBlockType: ( name, settings ) => ( registered[ name ] = settings ),
		},
		element: {
			createElement: ( type, props, ...children ) => ( { type, props: props || {}, children } ),
			Fragment: component( 'Fragment' ),
			useState: () => [ pickerState, () => {} ],
			useEffect: () => {},
		},
		components,
		blockEditor: { InspectorControls: component( 'InspectorControls' ), useBlockProps: () => ( {} ) },
		serverSideRender: component( 'ServerSideRender' ),
		i18n: { __: ( text ) => text, sprintf: ( format, ...args ) => format.replace( /%(\d\$)?[sd]/g, () => String( args.shift() ) ) },
		apiFetch: () => new Promise( () => {} ),
	};
	vm.runInNewContext( source, { window: { wp, odaventEventsBlocks: blocks } } );

	const found = [];
	const walk = ( node ) => {
		if ( Array.isArray( node ) ) {
			node.forEach( walk );
			return;
		}
		if ( ! node || typeof node !== 'object' ) {
			return;
		}
		const name = node.type && node.type.displayName;
		if ( FORM_CONTROLS.includes( name ) ) {
			found.push( { name, props: node.props } );
		}
		if ( typeof node.type === 'function' && ! name ) {
			walk( node.type( node.props ) ); // a component of editor.js itself (EventPicker)
		}
		walk( node.props.children );
		walk( node.children );
	};
	Object.keys( registered ).forEach( ( name ) => {
		walk( registered[ name ].edit( { attributes: { eventId: 0, eventSlug: 'x' }, setAttributes: () => {} } ) );
	} );
	return found;
}

const blocks = [
	{
		name: 'odavent-events/test',
		title: 'Test',
		attributes: {
			eventId: { type: 'integer', default: 0 },
			eventSlug: { type: 'string', default: '' },
			showBio: { type: 'boolean', default: false },
			columns: { type: 'integer', default: 3, minimum: 2, maximum: 4 },
			limit: { type: 'integer', default: 0, minimum: 0 },
			label: { type: 'string', default: '' },
			layout: { type: 'string', default: 'grid', enum: [ 'grid', 'list' ] },
		},
		managed: [ 'eventId', 'eventSlug', 'className' ],
	},
];

// The picker with an error (adds the slug TextControl) and the attribute controls of every type.
const controls = render( { loading: false, events: [ { id: 1, slug: 'a', name: 'A', status: 'published' } ], error: 'boom' }, blocks );
const counts = {};
controls.forEach( ( c ) => ( counts[ c.name ] = ( counts[ c.name ] || 0 ) + 1 ) );
assert.deepEqual( counts, { TextControl: 3, SelectControl: 2, ToggleControl: 1, RangeControl: 1 }, 'every control kind is exercised' );

controls.forEach( ( { name, props } ) => {
	assert.equal( props.__nextHasNoMarginBottom, true, name + ' gets __nextHasNoMarginBottom' );
	if ( name === 'ToggleControl' ) {
		assert.equal( props.__next40pxDefaultSize, undefined, 'ToggleControl has no size prop' );
	} else {
		assert.equal( props.__next40pxDefaultSize, true, name + ' gets __next40pxDefaultSize' );
	}
	assert.ok( typeof props.label === 'string' && props.label !== '', name + ' keeps its own props' );
} );

// The shared defaults never leak between controls (each gets its own merged object).
assert.notEqual( controls[ 0 ].props, controls[ 1 ].props );

console.log( 'editor.test.js ok' );
