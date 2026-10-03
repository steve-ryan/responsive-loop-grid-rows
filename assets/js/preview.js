/**
 * Responsive Loop Grid Rows - editor preview trimming.
 *
 * Inside the Elementor editor there is a single preview query for all device
 * modes, so the server renders the LARGEST item count across devices and tags
 * the grid with the per-device counts (`data-rlg-counts`). This script shows
 * only the first N items for the device currently being previewed, so
 * switching to Tablet or Mobile in the editor shows the right number of items.
 *
 * It only runs in the editor preview iframe (it is enqueued on
 * `elementor/preview/enqueue_scripts`), never on the live site.
 */
( function () {
	'use strict';

	var HIDDEN_ATTR = 'data-rlg-preview-hidden';

	function currentDevice() {
		if ( window.elementorFrontend && typeof window.elementorFrontend.getCurrentDeviceMode === 'function' ) {
			return window.elementorFrontend.getCurrentDeviceMode();
		}

		return 'desktop';
	}

	function parseCounts( grid ) {
		try {
			return JSON.parse( grid.getAttribute( 'data-rlg-counts' ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function trim( grid ) {
		var counts = parseCounts( grid );
		var limit = counts[ currentDevice() ];

		if ( ! limit ) {
			return;
		}

		var items = grid.querySelectorAll( '.e-loop-item' );

		Array.prototype.forEach.call( items, function ( item, index ) {
			if ( index >= limit ) {
				item.style.display = 'none';
				item.setAttribute( HIDDEN_ATTR, '1' );
			} else if ( item.hasAttribute( HIDDEN_ATTR ) ) {
				item.style.display = '';
				item.removeAttribute( HIDDEN_ATTR );
			}
		} );
	}

	function trimAll() {
		Array.prototype.forEach.call( document.querySelectorAll( '.rlg-responsive-grid[data-rlg-counts]' ), trim );
	}

	function init() {
		if ( ! window.elementorFrontend || ! window.elementorFrontend.hooks ) {
			return;
		}

		// A grid was (re)rendered, e.g. after changing Columns or Rows.
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/loop-grid.default', function ( $scope ) {
			var node = $scope && $scope[ 0 ] ? $scope[ 0 ] : null;

			if ( node && node.matches( '[data-rlg-counts]' ) ) {
				trim( node );
			}
		} );

		// The editor's device switcher resizes the preview, which fires this.
		window.addEventListener( 'resize', trimAll );

		trimAll();
	}

	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		init();
	} else if ( window.jQuery ) {
		// Elementor announces itself with a jQuery event on window.
		window.jQuery( window ).on( 'elementor/frontend/init', init );
	}
} )();
