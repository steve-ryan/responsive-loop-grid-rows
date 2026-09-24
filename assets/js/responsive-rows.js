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

	if ( ! RLG || ! RLG.ajaxUrl ) {
		return;
	}

	var COOKIE_NAME = 'rlg_device';
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
	 * Keep the short-lived, non-sensitive `rlg_device` cookie in step with
	 * the visitor's real device.
	 *
	 * Why it exists: Elementor's own "Load More" / Numbers pagination (which
	 * this script does not, and should not, intercept) requests page 2+ as a
	 * normal server request. The server reads this cookie - on those
	 * paginated requests only - so page 2+ uses the same items-per-page as
	 * the corrected page 1 (otherwise products would be skipped/repeated).
	 *
	 * The cookie is only stored when it is needed, i.e. when the visitor's
	 * device differs from the site's fallback device; when they match the
	 * server's default is already right, so any stale cookie is removed. It
	 * is never used to vary a plain (cacheable) page load.
	 *
	 * @param {string} device The visitor's resolved device.
	 */
	function syncDeviceCookie( device ) {
		try {
			var secure = window.location && window.location.protocol === 'https:' ? '; Secure' : '';

			if ( device === RLG.fallbackDevice ) {
				document.cookie = COOKIE_NAME + '=; path=/; max-age=0; SameSite=Lax' + secure;
			} else {
				document.cookie = COOKIE_NAME + '=' + device + '; path=/; max-age=86400; SameSite=Lax' + secure;
			}
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
		var postId = el.getAttribute( 'data-rlg-post-id' );

		if ( ! widgetId || ! documentId ) {
			return;
		}

		el.classList.add( 'rlg-correcting' );

		var body = new URLSearchParams();
		body.set( 'action', 'rlg_render_grid' );
		body.set( 'widget_id', widgetId );
		body.set( 'document_id', documentId );
		body.set( 'device', device );
		body.set( 'query_string', window.location.search || '' );
		if ( postId ) {
			body.set( 'post_id', postId );
		}

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
					if ( replaceElement( el, json.data.html ) ) {
						syncDeviceCookie( device );
						log( 'corrected widget', widgetId, 'to device', device );
					}
				} else {
					log( 'correction returned no usable html for widget', widgetId, json );
				}
			} )
			.catch( function ( err ) {
				log( 'correction request failed for widget', widgetId, err );
			} )
			.then( function () {
				// Runs on success and failure alike. (Promise#finally is
				// avoided so very old browsers behave the same way.)
				if ( el && el.classList ) {
					el.classList.remove( 'rlg-correcting' );
				}
			} );
	}

	/**
	 * Re-run Elementor's front-end handlers on freshly inserted markup, so
	 * the widget (and any widgets inside its loop items) behave exactly as
	 * they do on a normal page load.
	 *
	 * `runReadyTrigger` expects a jQuery-wrapped element and initialises
	 * only that one element, so it is called for the wrapper and for every
	 * Elementor element nested inside it.
	 *
	 * @param {HTMLElement} newEl
	 */
	function reinitElementor( newEl ) {
		var $ = window.jQuery;

		if ( ! $ || ! window.elementorFrontend || ! window.elementorFrontend.elementsHandler ) {
			return;
		}

		var handler = window.elementorFrontend.elementsHandler;

		if ( typeof handler.runReadyTrigger !== 'function' ) {
			return;
		}

		try {
			handler.runReadyTrigger( $( newEl ) );

			$( newEl )
				.find( '[data-element_type]' )
				.each( function () {
					handler.runReadyTrigger( $( this ) );
				} );
		} catch ( e ) {
			// Best-effort re-init of Elementor's own front-end handlers;
			// safe to ignore if Elementor's internal API differs.
		}
	}

	/**
	 * Replace an element in the DOM with a parsed HTML string, preserving
	 * its position. We replace the outer element itself (not just
	 * innerHTML) because the corrected markup is the full widget wrapper,
	 * including its own wrapper classes/data attributes.
	 *
	 * @param {HTMLElement} el
	 * @param {string} html
	 * @return {boolean} Whether the swap happened.
	 */
	function replaceElement( el, html ) {
		var template = document.createElement( 'template' );
		template.innerHTML = html.trim();
		var newEl = template.content.firstElementChild;

		if ( ! newEl || ! el.parentNode ) {
			// Could not parse a usable element - leave the existing,
			// fallback-device content in place rather than breaking the page.
			return false;
		}

		el.parentNode.replaceChild( newEl, el );
		reinitElementor( newEl );

		return true;
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

		Array.prototype.forEach.call( grids, function ( el ) {
			// Grids the server marked as not correctable (e.g. "Current
			// Query" archives) carry no document id; leave them entirely
			// alone, including the cookie, so they stay self-consistent.
			if ( ! el.getAttribute( 'data-rlg-document-id' ) ) {
				return;
			}

			var serverDevice = el.getAttribute( 'data-rlg-device' );

			if ( serverDevice === device ) {
				// Server already rendered the right thing - nothing to fetch.
				syncDeviceCookie( device );
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
