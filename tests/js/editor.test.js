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
function render( pickerState, blocks, attributes, setAttributes ) {
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
		walk( registered[ name ].edit( { attributes: attributes || { eventId: 0, eventSlug: 'x' }, setAttributes: setAttributes || ( () => {} ) } ) );
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

// The event page: `sections` is a comma list, shown as one toggle per section (meta.sectionChoices).
const SECTIONS = [ 'header', 'countdown', 'register', 'tickets', 'speakers', 'agenda' ];
const pageBlocks = [
	{
		name: 'odavent-events/event-page',
		title: 'Event page',
		attributes: {
			eventId: { type: 'integer', default: 0 },
			eventSlug: { type: 'string', default: '' },
			sections: { type: 'string', default: '' },
			showHeadings: { type: 'boolean', default: true },
		},
		labels: { sections: 'Sections', showHeadings: 'Show headings' },
		sectionChoices: SECTIONS.map( ( value ) => ( { value, label: 'Label ' + value } ) ),
		managed: [ 'eventId', 'eventSlug', 'className' ],
	},
];

/** Renders the event page with the given `sections` value; returns its toggles and every setAttributes call. */
function renderPage( sections ) {
	const calls = [];
	const found = render( { loading: false, events: [], error: '' }, pageBlocks, { eventId: 0, eventSlug: '', sections, showHeadings: true }, ( update ) => calls.push( JSON.parse( JSON.stringify( update ) ) ) ); // plain objects: the script ran in another vm realm
	const toggles = found.filter( ( c ) => c.name === 'ToggleControl' && c.props.label.indexOf( 'Label ' ) === 0 );
	return { toggles, calls, found };
}

// An empty value means every section: all toggles on, and no free-text field for `sections`.
let page = renderPage( '' );
assert.deepEqual( page.toggles.map( ( t ) => t.props.label ), SECTIONS.map( ( s ) => 'Label ' + s ), 'one toggle per section, in order' );
assert.ok( page.toggles.every( ( t ) => t.props.checked === true ), 'empty means all on' );
assert.equal( page.found.filter( ( c ) => c.name === 'TextControl' ).length, 0, 'no text field for the sections list' );
assert.equal( page.found.filter( ( c ) => c.name === 'ToggleControl' && c.props.label === 'Show headings' ).length, 1, 'other attributes keep their controls' );

// Turning one off writes the rest as a comma list in default order, whatever the order they were typed in.
page.toggles[ 3 ].props.onChange( false );
assert.deepEqual( page.calls, [ { sections: 'header,countdown,register,speakers,agenda' } ], 'test_sections_toggles_write_a_comma_list_in_default_order' );

page = renderPage( 'agenda, header' );
assert.deepEqual( page.toggles.map( ( t ) => t.props.checked ), [ true, false, false, false, false, true ], 'the typed list sets the toggles' );
page.toggles[ 4 ].props.onChange( true ); // speakers on: default order, not click order
assert.deepEqual( page.calls, [ { sections: 'header,speakers,agenda' } ] );

// Turning the last missing section on writes an empty string, not the full list.
page = renderPage( 'header,countdown,register,tickets,speakers' );
page.toggles[ 5 ].props.onChange( true );
assert.deepEqual( page.calls, [ { sections: '' } ], 'test_all_sections_on_writes_an_empty_string' );

// The last section on cannot be turned off: an empty list would mean "every section".
page = renderPage( 'agenda' );
page.toggles[ 5 ].props.onChange( false );
assert.deepEqual( page.calls, [], 'at least one section stays on' );

// A list that names no known section reads as every section, like the server does.
page = renderPage( 'bogus' );
assert.ok( page.toggles.every( ( t ) => t.props.checked === true ), 'unknown names mean all on' );

// For assistive technology the toggles sit in one labelled group: role="group" named by the visible heading.
( function () {
	const registered = {};
	const wp = {
		blocks: { getBlockType: () => undefined, registerBlockType: ( name, settings ) => ( registered[ name ] = settings ) },
		element: {
			createElement: ( type, props, ...children ) => ( { type, props: props || {}, children } ),
			Fragment: component( 'Fragment' ),
			useState: () => [ { loading: false, events: [], error: '' }, () => {} ],
			useEffect: () => {},
		},
		components: { Spinner: component( 'Spinner' ), Notice: component( 'Notice' ), PanelBody: component( 'PanelBody' ), ToggleControl: component( 'ToggleControl' ), SelectControl: component( 'SelectControl' ), TextControl: component( 'TextControl' ), RangeControl: component( 'RangeControl' ) },
		blockEditor: { InspectorControls: component( 'InspectorControls' ), useBlockProps: () => ( {} ) },
		serverSideRender: component( 'ServerSideRender' ),
		i18n: { __: ( text ) => text, sprintf: ( format ) => format },
		apiFetch: () => new Promise( () => {} ),
	};
	vm.runInNewContext( source, { window: { wp, odaventEventsBlocks: pageBlocks } } );

	const nodes = [];
	const walk = ( node ) => {
		if ( Array.isArray( node ) ) {
			node.forEach( walk );
			return;
		}
		if ( ! node || typeof node !== 'object' ) {
			return;
		}
		nodes.push( node );
		if ( typeof node.type === 'function' && ! ( node.type.displayName ) ) {
			walk( node.type( node.props ) );
		}
		walk( node.props && node.props.children );
		walk( node.children );
	};
	walk( registered[ 'odavent-events/event-page' ].edit( { attributes: { eventId: 0, eventSlug: '', sections: '', showHeadings: true }, setAttributes: () => {} } ) );

	const groups = nodes.filter( ( n ) => n.props && n.props.role === 'group' );
	assert.equal( groups.length, 1, 'test_section_toggles_are_one_labelled_group: exactly one group' );
	const group = groups[ 0 ];
	const heading = nodes.filter( ( n ) => n.props && n.props.id && n.props.id === group.props[ 'aria-labelledby' ] );
	assert.equal( heading.length, 1, 'the group is named by the id of the visible heading' );
	assert.equal( heading[ 0 ].props.className, 'components-base-control__label' );
	assert.deepEqual( heading[ 0 ].children, [ 'Sections' ] );
	const toggles = [];
	( function collect( node ) {
		if ( Array.isArray( node ) ) {
			node.forEach( collect );
		} else if ( node && typeof node === 'object' ) {
			if ( node.type && node.type.displayName === 'ToggleControl' ) {
				toggles.push( node );
			}
			collect( node.props && node.props.children );
			collect( node.children );
		}
	}( group ) );
	assert.equal( toggles.length, SECTIONS.length, 'every toggle is inside the group' );
}() );

// Without sectionChoices (any other block) `sections` would stay an ordinary text field.
const plain = render( { loading: false, events: [], error: '' }, [ { name: 'odavent-events/x', title: 'X', attributes: { sections: { type: 'string', default: '' } }, labels: { sections: 'Sections' }, managed: [] } ] );
assert.equal( plain.filter( ( c ) => c.name === 'TextControl' ).length, 1, 'a block without sectionChoices keeps the text field' );

console.log( 'editor.test.js ok' );
