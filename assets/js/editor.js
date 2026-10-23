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

	var eventsRequest = null;

	var STATUS_LABELS = {
		draft: __( 'Draft', 'odavent-events' ),
		published: __( 'Published', 'odavent-events' ),
		paused: __( 'Paused', 'odavent-events' ),
		cancelled: __( 'Cancelled', 'odavent-events' ),
		archived: __( 'Archived', 'odavent-events' )
	};

	/**
	 * A translated label for a known Odavent status; an unknown one is shown as sent.
	 */
	function statusLabel( status ) {
		var key = String( status || '' );
		return Object.prototype.hasOwnProperty.call( STATUS_LABELS, key ) ? STATUS_LABELS[ key ] : key;
	}

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
							error: ( error && error.message ) || __( 'The events could not be loaded.', 'odavent-events' )
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
		var slugHint = typeof attributes.eventSlug === 'string' ? attributes.eventSlug : '';
		var options = [ { label: __( 'Default event (from the settings)', 'odavent-events' ), value: '0' } ];
		var byId = null;
		var bySlug = null;
		data.events.forEach( function ( event ) {
			if ( event && typeof event.id === 'number' ) {
				if ( event.id === current ) {
					byId = event;
				}
				if ( slugHint && event.slug === slugHint ) {
					bySlug = event;
				}
				options.push( {
					/* translators: 1: event name, 2: event status. */
					label: sprintf( __( '%1$s (%2$s)', 'odavent-events' ), event.name || event.slug || String( event.id ), statusLabel( event.status ) ),
					value: String( event.id )
				} );
			}
		} );

		// What the block holds: an id, else a slug (shortcode-style), else the default event.
		var selected = '0';
		if ( current > 0 ) {
			selected = String( current );
			if ( ! byId ) {
				options.push( {
					/* translators: %d: event id. */
					label: sprintf( __( 'Event #%d', 'odavent-events' ), current ),
					value: selected
				} );
			}
		} else if ( slugHint ) {
			if ( bySlug ) {
				selected = String( bySlug.id );
			} else {
				selected = 'slug:' + slugHint;
				options.push( {
					/* translators: %s: event slug. */
					label: sprintf( __( 'Event: %s', 'odavent-events' ), slugHint ),
					value: selected
				} );
			}
		}

		var controls = [];
		if ( data.error ) {
			controls.push( el( components.Notice, { key: 'error', status: 'warning', isDismissible: false }, data.error ) );
			controls.push( el( components.TextControl, {
				key: 'slug',
				label: __( 'Event slug', 'odavent-events' ),
				help: __( 'The last part of the event address on Odavent.', 'odavent-events' ),
				value: attributes.eventSlug || '',
				onChange: function ( value ) {
					setAttributes( { eventSlug: value, eventId: 0 } );
				}
			} ) );
		}
		controls.push( el( components.SelectControl, {
			key: 'event',
			label: __( 'Event', 'odavent-events' ),
			value: selected,
			options: options,
			onChange: function ( value ) {
				if ( value === selected || value.indexOf( 'slug:' ) === 0 ) {
					return;
				}
				var id = parseInt( value, 10 ) || 0;
				if ( id === 0 ) {
					setAttributes( { eventId: 0, eventSlug: '' } );
					return;
				}
				var found = null;
				data.events.forEach( function ( event ) {
					if ( event && event.id === id ) {
						found = event;
					}
				} );
				// An option that is not in the list keeps the stored slug hint.
				setAttributes( found ? { eventId: id, eventSlug: found.slug || '' } : { eventId: id } );
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
					el( components.PanelBody, { title: __( 'Event', 'odavent-events' ) }, el( EventPicker, props ) ),
					settings.length ? el( components.PanelBody, { title: __( 'Settings', 'odavent-events' ) }, settings ) : null
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
