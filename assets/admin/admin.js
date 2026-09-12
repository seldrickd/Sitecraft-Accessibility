/**
 * Sitecraft shared admin behaviour.
 *
 * Progressive enhancement only: with JavaScript disabled every field stays
 * visible and the form still submits correctly.
 */
( function () {
	'use strict';

	/**
	 * Reads the current value of a settings control by field id.
	 *
	 * @param {string} fieldId Schema field id.
	 * @return {string|boolean|null} Current value, or null when not present.
	 */
	function readValue( fieldId ) {
		var domId = 'sc-field-' + fieldId.replace( /_/g, '-' );
		var el = document.getElementById( domId );

		if ( ! el ) {
			return null;
		}

		if ( 'checkbox' === el.type ) {
			return el.checked;
		}

		return el.value;
	}

	/**
	 * Compares an actual value against the expected value from the schema.
	 *
	 * @param {string|boolean|null} actual   Current control value.
	 * @param {*}                   expected Expected value from `depends_on`.
	 * @return {boolean} Whether the dependency is satisfied.
	 */
	function matches( actual, expected ) {
		if ( Array.isArray( expected ) ) {
			return expected.some( function ( candidate ) {
				return matches( actual, candidate );
			} );
		}

		if ( 'boolean' === typeof actual ) {
			return actual === Boolean( expected );
		}

		return String( actual ) === String( expected );
	}

	/**
	 * Shows or hides every dependent row based on current form state.
	 */
	function syncDependencies() {
		var rows = document.querySelectorAll( '[data-sc-depends]' );

		Array.prototype.forEach.call( rows, function ( row ) {
			var spec;

			try {
				spec = JSON.parse( row.getAttribute( 'data-sc-depends' ) );
			} catch ( error ) {
				return;
			}

			var satisfied = Object.keys( spec ).every( function ( fieldId ) {
				var actual = readValue( fieldId );

				return null === actual ? true : matches( actual, spec[ fieldId ] );
			} );

			row.hidden = ! satisfied;
		} );
	}

	/**
	 * Adds a confirmation step to any control marked as destructive.
	 */
	function guardDestructiveActions() {
		var targets = document.querySelectorAll( '[data-sc-confirm]' );

		Array.prototype.forEach.call( targets, function ( target ) {
			target.addEventListener( 'click', function ( event ) {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( target.getAttribute( 'data-sc-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.querySelector( '.sc-form' );

		if ( form ) {
			syncDependencies();
			form.addEventListener( 'change', syncDependencies );
		}

		guardDestructiveActions();
	} );
}() );
