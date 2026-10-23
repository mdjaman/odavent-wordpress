/**
 * Odavent Events: block editor integration. Plain ES5 on the wp.* globals, no build step.
 *
 * Registers every block listed in window.odaventEventsBlocks (printed by Block_Registry from each
 * block.json). Each block previews through ServerSideRender and gets an Inspector panel: an event picker
 * fed by GET /odavent/v1/events, then one control per attribute, chosen by the attribute's type.
 */
( function ( wp, blocks ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components || ! wp.blockEditor || ! blocks || ! blocks.length ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var components = wp.components;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var ServerSideRender = wp.serverSideRender;
	var DOMAIN = 'odavent-events';

	var eventsRequest = null;

	/**
	 * One request per editor session; a failure is forgotten so the next picker retries.
	 */
	function loadEvents() {
		if ( ! eventsRequest ) {
			eventsRequest = wp.apiFetch( { path: '/odavent/v1/events' } );
			eventsRequest[ 'catch' ]( function () {
				eventsRequest = null;
			} );
		}
		return eventsRequest;
	}

	function setter( setAttributes, name ) {
		return function ( value ) {
			var update = {};
			update[ name ] = value;
			setAttributes( update );
		};
	}

	function EventPicker( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var state = useState( { loading: true, events: [], error: '' } );
		var data = state[ 0 ];
		var setData = state[ 1 ];

		useEffect( function () {
			var active = true;
			loadEvents().then(
				function ( events ) {
					if ( active ) {
						setData( { loading: false, events: Array.isArray( events ) ? events : [], error: '' } );
					}
				},
				function ( error ) {
					if ( active ) {
						setData( {
							loading: false,
							events: [],
							error: ( error && error.message ) || __( 'The events could not be loaded.', DOMAIN )
						} );
					}
				}
			);
			return function () {
				active = false;
			};
		}, [] );

		if ( data.loading ) {
			return el( components.Spinner, null );
		}

		var current = parseInt( attributes.eventId, 10 ) || 0;
		var options = [ { label: __( 'Default event (from the settings)', DOMAIN ), value: '0' } ];
		var known = false;
		data.events.forEach( function ( event ) {
			if ( event && typeof event.id === 'number' ) {
				known = known || event.id === current;
				options.push( {
					/* translators: 1: event name, 2: event status. */
					label: sprintf( __( '%1$s (%2$s)', DOMAIN ), event.name || event.slug || String( event.id ), event.status || '' ),
					value: String( event.id )
				} );
			}
		} );
		if ( current > 0 && ! known ) {
			options.push( {
				/* translators: %d: event id. */
				label: sprintf( __( 'Event #%d', DOMAIN ), current ),
				value: String( current )
			} );
		}

		var controls = [];
		if ( data.error ) {
			controls.push( el( components.Notice, { key: 'error', status: 'warning', isDismissible: false }, data.error ) );
			controls.push( el( components.TextControl, {
				key: 'slug',
				label: __( 'Event slug', DOMAIN ),
				help: __( 'The last part of the event address on Odavent.', DOMAIN ),
				value: attributes.eventSlug || '',
				onChange: function ( value ) {
					setAttributes( { eventSlug: value, eventId: 0 } );
				}
			} ) );
		}
		controls.push( el( components.SelectControl, {
			key: 'event',
			label: __( 'Event', DOMAIN ),
			value: String( current ),
			options: options,
			onChange: function ( value ) {
				var id = parseInt( value, 10 ) || 0;
				var slug = '';
				data.events.forEach( function ( event ) {
					if ( event && event.id === id ) {
						slug = event.slug || '';
					}
				} );
				setAttributes( { eventId: id, eventSlug: slug } );
			}
		} ) );

		return el( Fragment, null, controls );
	}

	function attributeControl( name, schema, meta, props ) {
		var label = ( meta.labels && meta.labels[ name ] ) || name;
		var value = props.attributes[ name ];
		var onChange = setter( props.setAttributes, name );

		switch ( schema.type ) {
			case 'boolean':
				return el( components.ToggleControl, { key: name, label: label, checked: !! value, onChange: onChange } );

			case 'integer':
			case 'number':
				if ( typeof schema.minimum === 'number' && typeof schema.maximum === 'number' && components.RangeControl ) {
					return el( components.RangeControl, {
						key: name,
						label: label,
						value: typeof value === 'number' ? value : schema[ 'default' ],
						min: schema.minimum,
						max: schema.maximum,
						onChange: function ( next ) {
							onChange( typeof next === 'number' ? next : schema[ 'default' ] );
						}
					} );
				}
				return el( components.TextControl, {
					key: name,
					label: label,
					type: 'number',
					min: typeof schema.minimum === 'number' ? schema.minimum : undefined,
					value: typeof value === 'number' ? String( value ) : '',
					onChange: function ( next ) {
						var parsed = schema.type === 'integer' ? parseInt( next, 10 ) : parseFloat( next );
						if ( isNaN( parsed ) ) {
							parsed = typeof schema[ 'default' ] === 'number' ? schema[ 'default' ] : 0;
						}
						if ( typeof schema.minimum === 'number' && parsed < schema.minimum ) {
							parsed = schema.minimum;
						}
						onChange( parsed );
					}
				} );

			case 'string':
				if ( Array.isArray( schema[ 'enum' ] ) ) {
					return el( components.SelectControl, {
						key: name,
						label: label,
						value: value || '',
						options: schema[ 'enum' ].map( function ( option ) {
							return { label: String( option ), value: String( option ) };
						} ),
						onChange: onChange
					} );
				}
				return el( components.TextControl, { key: name, label: label, value: value || '', onChange: onChange } );

			default:
				return null;
		}
	}

	function makeEdit( meta ) {
		var managed = Array.isArray( meta.managed ) ? meta.managed : [ 'eventId', 'eventSlug', 'className' ];

		return function Edit( props ) {
			var blockProps = useBlockProps();
			var settings = [];

			Object.keys( meta.attributes || {} ).forEach( function ( name ) {
				if ( managed.indexOf( name ) === -1 ) {
					var control = attributeControl( name, meta.attributes[ name ] || {}, meta, props );
					if ( control ) {
						settings.push( control );
					}
				}
			} );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el( components.PanelBody, { title: __( 'Event', DOMAIN ) }, el( EventPicker, props ) ),
					settings.length ? el( components.PanelBody, { title: __( 'Settings', DOMAIN ) }, settings ) : null
				),
				el( 'div', blockProps, el( ServerSideRender, { block: meta.name, attributes: props.attributes } ) )
			);
		};
	}

	blocks.forEach( function ( meta ) {
		if ( ! meta || typeof meta.name !== 'string' || wp.blocks.getBlockType( meta.name ) ) {
			return;
		}

		wp.blocks.registerBlockType( meta.name, {
			apiVersion: 3,
			title: meta.title,
			description: meta.description,
			category: meta.category || 'odavent-events',
			icon: meta.icon || 'calendar-alt',
			attributes: meta.attributes || {},
			supports: meta.supports || { html: false },
			edit: makeEdit( meta ),
			save: function () { return null; }
		} );
	} );
}( window.wp, window.odaventEventsBlocks ) );
