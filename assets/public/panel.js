/**
 * Sitecraft Accessibility - reading preference panel behaviour.
 *
 * Written against the DOM directly, in ES5, because the plugin ships without a
 * build step and this file has to run on the front end of any theme.
 *
 * Design notes worth knowing before changing anything here:
 *
 * - Preferences are applied by setting `data-sc-a11y-*` attributes on the html
 *   element. Nothing writes inline styles onto page elements, so turning a
 *   preference off restores the theme exactly.
 * - The panel is non-modal. It does not trap focus, because it is a set of
 *   controls beside the page rather than a task blocking it. Escape closes it and
 *   returns focus to the toggle, which is the behaviour the pattern calls for.
 * - Every storage access is wrapped: Safari in private mode throws on write, and
 *   an exception here would leave the panel half initialised.
 */
( function () {
	'use strict';

	var config = window.sitecraftA11yPanel || {};
	var storageKey = config.storageKey || 'sitecraftA11yPrefs';
	var labels = config.labels || {};

	var root = document.querySelector( '.sc-a11y-panel-root' );

	if ( ! root ) {
		return;
	}

	var toggle = root.querySelector( '.sc-a11y-panel-toggle' );
	var panel = root.querySelector( '.sc-a11y-panel' );
	var status = root.querySelector( '.sc-a11y-panel-status' );
	var closeButton = root.querySelector( '.sc-a11y-panel-close' );
	var resetButton = root.querySelector( '.sc-a11y-panel-reset' );

	if ( ! toggle || ! panel ) {
		return;
	}

	/**
	 * Maps a preference key to the attribute and value it sets on <html>.
	 */
	var ATTRIBUTES = {
		line_spacing: [ 'data-sc-a11y-line-spacing', 'on' ],
		underline_links: [ 'data-sc-a11y-underline-links', 'on' ],
		high_contrast: [ 'data-sc-a11y-contrast', 'high' ],
		greyscale: [ 'data-sc-a11y-greyscale', 'on' ],
		pause_animations: [ 'data-sc-a11y-motion', 'pause' ],
		reading_mask: [ 'data-sc-a11y-mask', 'on' ],
		dyslexia_font: [ 'data-sc-a11y-font', 'dyslexia' ]
	};

	var state = read();

	/**
	 * Reads stored preferences, tolerating every way storage can fail.
	 *
	 * @return {Object} Stored preferences, or an empty set.
	 */
	function read() {
		try {
			var raw = window.localStorage.getItem( storageKey );

			if ( ! raw ) {
				return {};
			}

			var parsed = JSON.parse( raw );

			return parsed && 'object' === typeof parsed ? parsed : {};
		} catch ( error ) {
			return {};
		}
	}

	/**
	 * Persists preferences. A failure is not surfaced: the preference still
	 * applies for this page view, which is more useful than an error nobody asked
	 * for.
	 */
	function write() {
		try {
			window.localStorage.setItem( storageKey, JSON.stringify( state ) );
		} catch ( error ) {
			// Private browsing and quota limits both land here; nothing to do.
		}
	}

	/**
	 * Announces a change to assistive technology without moving focus.
	 *
	 * @param {string} message Text to announce.
	 */
	function announce( message ) {
		if ( ! status || ! message ) {
			return;
		}

		status.textContent = message;
	}

	/**
	 * Writes the current state onto the html element.
	 */
	function apply() {
		var html = document.documentElement;
		var key;

		for ( key in ATTRIBUTES ) {
			if ( ! Object.prototype.hasOwnProperty.call( ATTRIBUTES, key ) ) {
				continue;
			}

			if ( state[ key ] ) {
				html.setAttribute( ATTRIBUTES[ key ][ 0 ], ATTRIBUTES[ key ][ 1 ] );
			} else {
				html.removeAttribute( ATTRIBUTES[ key ][ 0 ] );
			}
		}

		if ( state.text_size && 100 !== parseInt( state.text_size, 10 ) ) {
			html.setAttribute( 'data-sc-a11y-text-size', String( state.text_size ) );
		} else {
			html.removeAttribute( 'data-sc-a11y-text-size' );
		}

		syncControls();
		syncMask();
	}

	/**
	 * Brings the visible controls back in line with the state.
	 */
	function syncControls() {
		var checkboxes = panel.querySelectorAll( '.sc-a11y-pref' );
		var sizes = panel.querySelectorAll( '.sc-a11y-size' );
		var index;

		for ( index = 0; index < checkboxes.length; index++ ) {
			checkboxes[ index ].checked = !! state[ checkboxes[ index ].getAttribute( 'data-sc-pref' ) ];
		}

		var current = state.text_size ? parseInt( state.text_size, 10 ) : 100;

		for ( index = 0; index < sizes.length; index++ ) {
			var value = parseInt( sizes[ index ].getAttribute( 'data-sc-size' ), 10 );

			sizes[ index ].setAttribute( 'aria-pressed', value === current ? 'true' : 'false' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Reading mask
	 *
	 * Two dimmed bands leave a clear strip that follows the pointer, and the
	 * keyboard focus when there is no pointer. It is pointer-events:none in CSS
	 * so it can never intercept a click.
	 * ------------------------------------------------------------------ */

	var maskTop = null;
	var maskBottom = null;
	var maskBound = false;
	var maskCentre = 0;
	var BAND = 120;

	/**
	 * Creates or removes the mask elements to match the current state.
	 */
	function syncMask() {
		if ( ! state.reading_mask ) {
			removeMask();

			return;
		}

		if ( maskTop ) {
			return;
		}

		maskTop = document.createElement( 'div' );
		maskTop.className = 'sc-a11y-mask';
		maskTop.setAttribute( 'aria-hidden', 'true' );

		maskBottom = maskTop.cloneNode( false );

		document.body.appendChild( maskTop );
		document.body.appendChild( maskBottom );

		positionMask( window.innerHeight / 2 );

		if ( ! maskBound ) {
			maskBound = true;
			document.addEventListener( 'mousemove', onPointerMove );
			document.addEventListener( 'focusin', onFocusMove );

			// The bands are sized against the viewport height, so a resize or an
			// orientation change leaves them covering the wrong part of the page until
			// the pointer next moves - and a keyboard-only visitor never moves it.
			window.addEventListener( 'resize', onViewportChange );
		}
	}

	/**
	 * Removes the mask elements.
	 */
	function removeMask() {
		if ( maskTop && maskTop.parentNode ) {
			maskTop.parentNode.removeChild( maskTop );
		}

		if ( maskBottom && maskBottom.parentNode ) {
			maskBottom.parentNode.removeChild( maskBottom );
		}

		maskTop = null;
		maskBottom = null;
	}

	/**
	 * Places the clear band around a viewport y coordinate.
	 *
	 * @param {number} centre Viewport y coordinate to keep clear.
	 */
	function positionMask( centre ) {
		if ( ! maskTop || ! maskBottom ) {
			return;
		}

		maskCentre = centre;

		var top = Math.max( 0, centre - ( BAND / 2 ) );
		var bottom = Math.max( 0, window.innerHeight - centre - ( BAND / 2 ) );

		maskTop.style.top = '0';
		maskTop.style.height = top + 'px';
		maskBottom.style.bottom = '0';
		maskBottom.style.height = bottom + 'px';
	}

	/**
	 * Tracks the pointer.
	 *
	 * @param {MouseEvent} event Pointer event.
	 */
	function onPointerMove( event ) {
		if ( state.reading_mask ) {
			positionMask( event.clientY );
		}
	}

	/**
	 * Tracks keyboard focus, so the mask is usable without a mouse.
	 *
	 * @param {FocusEvent} event Focus event.
	 */
	function onFocusMove( event ) {
		if ( ! state.reading_mask || ! event.target || ! event.target.getBoundingClientRect ) {
			return;
		}

		var box = event.target.getBoundingClientRect();

		positionMask( box.top + ( box.height / 2 ) );
	}

	/**
	 * Re-sizes the bands after the viewport changes.
	 */
	function onViewportChange() {
		if ( state.reading_mask ) {
			positionMask( Math.min( maskCentre, window.innerHeight ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Open and close
	 * ------------------------------------------------------------------ */

	/**
	 * Opens or closes the panel.
	 *
	 * @param {boolean} open Whether the panel should be open.
	 */
	function setOpen( open ) {
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

		if ( open ) {
			panel.removeAttribute( 'hidden' );

			var heading = panel.querySelector( '.sc-a11y-panel-heading' );

			// Focus lands on the heading rather than the first control so the panel's
			// name is announced before its options.
			if ( heading ) {
				heading.setAttribute( 'tabindex', '-1' );
				heading.focus();
			}

			announce( labels.opened || '' );
		} else {
			// Focus only goes back to the toggle if it was inside the panel to begin
			// with. Escape is a global listener, so closing the panel while the visitor
			// is typing somewhere else must not yank the caret across the page.
			var focusWasInside = root.contains( document.activeElement );

			panel.setAttribute( 'hidden', 'hidden' );

			if ( focusWasInside ) {
				toggle.focus();
			}

			announce( labels.closed || '' );
		}
	}

	toggle.addEventListener( 'click', function () {
		setOpen( 'true' !== toggle.getAttribute( 'aria-expanded' ) );
	} );

	if ( closeButton ) {
		closeButton.addEventListener( 'click', function () {
			setOpen( false );
		} );
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key && 'Esc' !== event.key ) {
			return;
		}

		if ( 'true' === toggle.getAttribute( 'aria-expanded' ) ) {
			setOpen( false );
		}
	} );

	// A non-modal panel should get out of the way when attention moves elsewhere,
	// but must not close while focus is still inside it.
	document.addEventListener( 'click', function ( event ) {
		if ( 'true' !== toggle.getAttribute( 'aria-expanded' ) ) {
			return;
		}

		if ( ! root.contains( event.target ) ) {
			// setOpen() only returns focus when focus was inside the panel, so a click
			// elsewhere on the page closes it without interrupting what the visitor is
			// doing, and the closure is still announced.
			setOpen( false );
		}
	} );

	/* ---------------------------------------------------------------------
	 * Controls
	 * ------------------------------------------------------------------ */

	panel.addEventListener( 'change', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.classList || ! target.classList.contains( 'sc-a11y-pref' ) ) {
			return;
		}

		var key = target.getAttribute( 'data-sc-pref' );

		if ( ! key ) {
			return;
		}

		if ( target.checked ) {
			state[ key ] = true;
		} else {
			delete state[ key ];
		}

		apply();
		write();
	} );

	panel.addEventListener( 'click', function ( event ) {
		var target = event.target;

		while ( target && target !== panel && ! ( target.classList && target.classList.contains( 'sc-a11y-size' ) ) ) {
			target = target.parentNode;
		}

		if ( ! target || target === panel ) {
			return;
		}

		var size = parseInt( target.getAttribute( 'data-sc-size' ), 10 );

		if ( ! size ) {
			return;
		}

		if ( 100 === size ) {
			delete state.text_size;
		} else {
			state.text_size = size;
		}

		apply();
		write();
	} );

	if ( resetButton ) {
		resetButton.addEventListener( 'click', function () {
			state = {};

			apply();
			write();
			announce( labels.reset || '' );
		} );
	}

	apply();
}() );
