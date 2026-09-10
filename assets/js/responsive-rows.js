/**
 * Responsive Loop Grid Rows - front-end correction script.
 *
 * What this does, in one sentence: every Loop Grid with Responsive Rows
 * enabled is first rendered server-side using a single, site-wide "fallback"
 * device (so the HTML is 100% cache-safe), and this script corrects it,
 * per visitor, to their real breakpoint - but only when it actually differs
 * from the fallback, to avoid unnecessary requests.
 *
 * No build step, no dependencies. Degrades silently if fetch/matchMedia are
 * unavailable (extremely old browsers keep the fallback-device content).
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}

	var RLG = window.RLG_Data || null;

	if ( ! RLG || ! RLG.ajaxUrl || ! RLG.nonce ) {
		return;
	}

	var hasFetch = typeof window.fetch === 'function';
	var hasMatchMedia = typeof window.matchMedia === 'function';

	/**
	 * Work out the visitor's current device using Elementor's own
	 * configured breakpoint values (never hard-coded 767/1024).
	 *
	 * Elementor breakpoints are expressed as max-width values: a viewport
	 * at or below the "mobile" value is mobile, at or below "tablet" is
	 * tablet, and anything above that is desktop.
	 *
	 * @return {string} one of "mobile", "tablet", "desktop".
	 */
	function detectDevice() {
		var bp = RLG.breakpoints || { mobile: 767, tablet: 1024 };

		if ( hasMatchMedia ) {
			if ( window.matchMedia( '(max-width: ' + bp.mobile + 'px)' ).matches ) {
				return 'mobile';
			}
			if ( window.matchMedia( '(max-width: ' + bp.tablet + 'px)' ).matches ) {
				return 'tablet';
			}
			return 'desktop';
		}

		// matchMedia unavailable - fall back to viewport width comparison.
		var width = window.innerWidth || document.documentElement.clientWidth || 0;
		if ( width <= bp.mobile ) {
			return 'mobile';
		}
		if ( width <= bp.tablet ) {
			return 'tablet';
		}
		return 'desktop';
	}

	function log() {
		if ( RLG.debug && window.console && window.console.log ) {
			var args = [ '[Responsive Loop Grid Rows]' ].concat( Array.prototype.slice.call( arguments ) );
			window.console.log.apply( window.console, args );
		}
	}

	/**
	 * Persist the resolved device in a short-lived, non-sensitive cookie.
	 *
	 * This is purely a UX/consistency optimisation: it lets Elementor's OWN
	 * native "Load More" AJAX pagination (which our script does not, and
	 * should not, intercept) pick up the right posts_per_page on subsequent
	 * pages, because that request also reaches the server as a normal,
	 * never-cached admin-ajax.php call, and PHP can read the cookie there.
	 * It is never used to vary the cacheable, server-rendered HTML itself.
	 *
	 * @param {string} device
	 */
	function persistDeviceCookie( device ) {
		try {
			document.cookie = 'rlg_device=' + device + '; path=/; max-age=86400; SameSite=Lax';
		} catch ( e ) {
			// Cookies blocked - ignore, this is only an optimisation.
		}
	}

	/**
	 * Ask the server to re-render one Loop Grid widget for a specific
	 * device, and swap the result into the DOM.
	 *
	 * @param {HTMLElement} el The .rlg-responsive-grid wrapper element.
	 * @param {string} device Resolved device.
	 */
	function correct( el, device ) {
		var widgetId = el.getAttribute( 'data-rlg-widget-id' );
		var documentId = el.getAttribute( 'data-rlg-document-id' );

		if ( ! widgetId || ! documentId ) {
			return;
		}

		el.classList.add( 'rlg-correcting' );

		var body = new URLSearchParams();
		body.set( 'action', 'rlg_render_grid' );
		body.set( 'nonce', RLG.nonce );
		body.set( 'widget_id', widgetId );
		body.set( 'document_id', documentId );
		body.set( 'device', device );
		body.set( 'query_string', window.location.search || '' );

		fetch( RLG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data && typeof json.data.html === 'string' && json.data.html.length ) {
					replaceElement( el, json.data.html );
					persistDeviceCookie( device );
					log( 'corrected widget', widgetId, 'to device', device );
				} else {
					log( 'correction returned no usable html for widget', widgetId, json );
				}
			} )
			.catch( function ( err ) {
				log( 'correction request failed for widget', widgetId, err );
			} )
			.finally( function () {
				if ( el && el.classList ) {
					el.classList.remove( 'rlg-correcting' );
				}
			} );
	}

	/**
	 * Replace an element in the DOM with a parsed HTML string, preserving
	 * its position. We replace the outer element itself (not just
	 * innerHTML) because the corrected markup is the full widget wrapper,
	 * including its own wrapper classes/data attributes.
	 *
	 * @param {HTMLElement} el
	 * @param {string} html
	 */
	function replaceElement( el, html ) {
		var template = document.createElement( 'template' );
		template.innerHTML = html.trim();
		var newEl = template.content.firstElementChild;

		if ( ! newEl || ! el.parentNode ) {
			// Could not parse a usable element - leave the existing,
			// fallback-device content in place rather than breaking the page.
			return;
		}

		el.parentNode.replaceChild( newEl, el );

		if ( window.elementorFrontend && window.elementorFrontend.elementsHandler ) {
			try {
				window.elementorFrontend.elementsHandler.runReadyTrigger( newEl );
			} catch ( e ) {
				// Best-effort re-init of Elementor's own front-end handlers
				// (e.g. equal-height, lazy load) on the swapped-in markup;
				// safe to ignore if Elementor's internal API differs.
			}
		}
	}

	function init() {
		if ( ! hasFetch ) {
			log( 'fetch unavailable - skipping AJAX correction, fallback-device content will remain' );
			return;
		}

		if ( RLG.correctionMode === 'off' ) {
			return;
		}

		var grids = document.querySelectorAll( '.rlg-responsive-grid' );

		if ( ! grids.length ) {
			return;
		}

		var device = detectDevice();

		grids.forEach( function ( el ) {
			var serverDevice = el.getAttribute( 'data-rlg-device' );

			if ( serverDevice === device ) {
				// Server already rendered the right thing - nothing to do.
				persistDeviceCookie( device );
				return;
			}

			correct( el, device );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
