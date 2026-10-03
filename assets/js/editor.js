/**
 * Responsive Loop Grid Rows - editor helper.
 *
 * Does two small jobs while a Loop Grid is open in the Elementor panel:
 *
 * 1. Keeps the "Calculated items per page" box up to date as Columns / Rows
 *    change, for every breakpoint the site has enabled.
 * 2. Hides the widget's own "Items Per Page" field while Responsive Rows is on
 *    (the PHP side already does this through the control's condition; this is
 *    the fallback for an Elementor Pro version where that control moved).
 *
 * Values are read from the widget's settings model, which is stable across
 * Elementor versions and already includes defaults. If the model is not
 * reachable, the script falls back to reading the panel inputs by their
 * `data-setting` attribute. If neither works it does nothing, and the box
 * simply keeps its last value.
 *
 * `window.RLG_Editor` is provided by the plugin: { order, chains, labels }.
 */
( function ( $ ) {
	'use strict';

	if ( ! $ || typeof elementor === 'undefined' ) {
		return;
	}

	var CFG = window.RLG_Editor || {
		order: [ 'desktop', 'tablet', 'mobile' ],
		chains: {
			desktop: [ 'desktop' ],
			tablet: [ 'tablet', 'desktop' ],
			mobile: [ 'mobile', 'tablet', 'desktop' ]
		},
		labels: { desktop: 'Desktop', tablet: 'Tablet', mobile: 'Mobile' }
	};

	var DEFAULTS = { columns: 3, rlg_rows: 2 };
	var boundSettings = null;

	function settingKey( base, device ) {
		return device === 'desktop' ? base : base + '_' + device;
	}

	function toPositiveInt( value ) {
		var parsed = parseInt( value, 10 );
		return isNaN( parsed ) || parsed < 1 ? null : parsed;
	}

	/**
	 * Build a reader for a control value, from the settings model when
	 * available, otherwise from the panel's inputs.
	 *
	 * @param {Object|null} settings Backbone settings model of the open widget.
	 * @return {function(string): *}
	 */
	function makeReader( settings ) {
		if ( settings && typeof settings.get === 'function' ) {
			return function ( key ) {
				return settings.get( key );
			};
		}

		var $panel = $( '#elementor-panel-content-wrapper' );

		return function ( key ) {
			var value = null;

			$panel.find( '[data-setting="' + key + '"]' ).each( function () {
				var v = $( this ).val();
				if ( v !== '' && v !== undefined && v !== null ) {
					value = v;
					return false;
				}
			} );

			return value;
		};
	}

	/**
	 * Effective value for a device, walking the same inheritance chain the
	 * server uses.
	 */
	function resolve( read, base, device ) {
		var chain = CFG.chains[ device ] || [ device, 'desktop' ];

		for ( var i = 0; i < chain.length; i++ ) {
			var value = toPositiveInt( read( settingKey( base, chain[ i ] ) ) );
			if ( value ) {
				return value;
			}
		}

		return DEFAULTS[ base ];
	}

	function recalculate( read ) {
		var $box = $( '#elementor-panel-content-wrapper' ).find( '[data-rlg-calculated-box]' );

		if ( ! $box.length ) {
			return;
		}

		CFG.order.forEach( function ( device ) {
			var items = resolve( read, 'columns', device ) * resolve( read, 'rlg_rows', device );

			$box.find( '[data-rlg-device="' + device + '"] [data-rlg-value]' ).text( items );
		} );
	}

	/**
	 * Hide or show the native Items Per Page control to match the switch.
	 */
	function syncItemsPerPage( read ) {
		var enabled = read( 'rlg_enable' ) === 'yes';

		$( '#elementor-panel-content-wrapper .elementor-control-posts_per_page' ).toggle( ! enabled );
	}

	function refresh( settings ) {
		var read = makeReader( settings );

		recalculate( read );
		syncItemsPerPage( read );
	}

	/**
	 * Bind to one widget's settings model. Unbinds from the previous one
	 * first so listeners never pile up across widget selections.
	 */
	function bind( settings ) {
		if ( boundSettings && typeof boundSettings.off === 'function' ) {
			boundSettings.off( 'change', onChange );
		}

		boundSettings = settings && typeof settings.on === 'function' ? settings : null;

		if ( boundSettings ) {
			boundSettings.on( 'change', onChange );
		}

		refresh( boundSettings );
	}

	function onChange() {
		refresh( boundSettings );
	}

	try {
		elementor.hooks.addAction( 'panel/open_editor/widget/loop-grid', function ( panel, model ) {
			var settings = model && typeof model.get === 'function' ? model.get( 'settings' ) : null;

			// Give Elementor a tick to finish rendering the panel's controls.
			setTimeout( function () {
				bind( settings );
			}, 50 );
		} );
	} catch ( e ) {
		// Elementor's hooks API is unavailable/changed - fail silently.
	}
} )( window.jQuery );
