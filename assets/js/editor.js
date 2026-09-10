/**
 * Responsive Loop Grid Rows - editor helper.
 *
 * Elementor's panel re-renders controls as Backbone views, and there is no
 * small, version-stable public API for "recalculate this bit of raw HTML
 * whenever these other controls change". Rather than hook into Elementor's
 * internal Backbone views (fragile across versions), this takes a
 * deliberately simple, best-effort approach: it watches the actual <input>
 * elements for the controls we care about (by their `data-setting`
 * attribute, which Elementor does consistently render) while a Loop Grid
 * widget's panel is open, and recalculates the numbers itself in JS.
 *
 * If Elementor ever changes this DOM structure, this script simply stops
 * finding the inputs and silently does nothing further - the "Calculated"
 * text will still show the value from the last save/panel-open, it just
 * won't live-update. This is the "sensible editor fallback" referenced in
 * the README.
 */
( function ( $ ) {
	'use strict';

	if ( ! $ || typeof elementor === 'undefined' ) {
		return;
	}

	var WATCHED_SETTINGS = [
		'columns', 'columns_tablet', 'columns_mobile',
		'rlg_rows', 'rlg_rows_tablet', 'rlg_rows_mobile'
	];

	/**
	 * Read the current value of a control's input inside a given panel
	 * root, by its data-setting attribute. Elementor renders responsive
	 * controls' inactive breakpoints as hidden siblings, so we look at all
	 * matches and take the first with a non-empty value.
	 *
	 * @param {jQuery} root
	 * @param {string} setting
	 * @return {number}
	 */
	function readSetting( root, setting ) {
		var $inputs = root.find( '[data-setting="' + setting + '"]' );
		var value = null;

		$inputs.each( function () {
			var v = $( this ).val();
			if ( v !== '' && v !== undefined && v !== null ) {
				value = v;
				return false;
			}
		} );

		var parsed = parseInt( value, 10 );
		return isNaN( parsed ) || parsed < 1 ? null : parsed;
	}

	/**
	 * Recalculate and write the three "items/page" numbers into the
	 * Responsive Rows helper box.
	 *
	 * @param {jQuery} root Panel content root for the currently open widget.
	 */
	function recalculate( root ) {
		var $box = root.find( '[data-rlg-calculated-box]' );
		if ( ! $box.length ) {
			return;
		}

		var columnsDesktop = readSetting( root, 'columns' ) || 3;
		var columnsTablet = readSetting( root, 'columns_tablet' ) || columnsDesktop;
		var columnsMobile = readSetting( root, 'columns_mobile' ) || columnsTablet;

		var rowsDesktop = readSetting( root, 'rlg_rows' ) || 2;
		var rowsTablet = readSetting( root, 'rlg_rows_tablet' ) || rowsDesktop;
		var rowsMobile = readSetting( root, 'rlg_rows_mobile' ) || rowsTablet;

		var values = {
			desktop: columnsDesktop * rowsDesktop,
			tablet: columnsTablet * rowsTablet,
			mobile: columnsMobile * rowsMobile
		};

		Object.keys( values ).forEach( function ( device ) {
			$box.find( '[data-rlg-device="' + device + '"] [data-rlg-value]' ).text( values[ device ] );
		} );
	}

	/**
	 * Attach change/input listeners, scoped to the currently open panel, so
	 * we never leak listeners across widget selections.
	 */
	function bindPanel() {
		var $panel = $( '#elementor-panel-content-wrapper' );
		if ( ! $panel.length ) {
			return;
		}

		$panel.off( 'input.rlg change.rlg' );

		var selector = WATCHED_SETTINGS
			.map( function ( setting ) {
				return '[data-setting="' + setting + '"]';
			} )
			.join( ',' );

		$panel.on( 'input.rlg change.rlg', selector, function () {
			recalculate( $panel );
		} );

		// Run once immediately in case the panel opened with the Responsive
		// Rows section already expanded.
		recalculate( $panel );
	}

	try {
		elementor.hooks.addAction( 'panel/open_editor/widget/loop-grid', function () {
			// Give Elementor a tick to finish rendering the panel's controls.
			setTimeout( bindPanel, 50 );
		} );
	} catch ( e ) {
		// Elementor's hooks API is unavailable/changed - fail silently.
	}
} )( window.jQuery );
