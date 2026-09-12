/**
 * Sitecraft Accessibility - block editor sidebar.
 *
 * Written in ES5 against the wp.* globals: the plugin ships without a build step,
 * so there is no JSX and no bundler. `wp.element.createElement` is aliased to `el`
 * below, which is the same thing JSX compiles to.
 *
 * The rules are not reimplemented here. The sidebar posts the serialised content
 * to `sitecraft/v1/a11y/analyse` and renders what the PHP rule engine returns.
 * A second, client-side copy of fifteen WCAG checks would drift from the server
 * copy within one release, and the editor would then disagree with the audit
 * report about the same post - which is worse than no sidebar at all.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var apiFetch = wp.apiFetch;

	var config = window.sitecraftA11yEditor || {};
	var PLUGIN_NAME = 'sitecraft-accessibility';
	var DEBOUNCE_MS = 1200;

	// WordPress 6.6 moved the sidebar components from wp.editPost to wp.editor and
	// kept the old namespace as a deprecated alias. Preferring the new one keeps the
	// sidebar out of the deprecation log without dropping support for 6.5.
	var editor = wp.editor && wp.editor.PluginSidebar ? wp.editor : wp.editPost;

	if ( ! editor || ! editor.PluginSidebar ) {
		return;
	}

	var PluginSidebar = editor.PluginSidebar;
	var PluginSidebarMoreMenuItem = editor.PluginSidebarMoreMenuItem;

	var Spinner = wp.components.Spinner;
	var PanelBody = wp.components.PanelBody;
	var Notice = wp.components.Notice;
	var TextControl = wp.components.TextControl;
	var Button = wp.components.Button;

	var SEVERITY_ORDER = [ 'critical', 'serious', 'moderate', 'minor' ];

	var SEVERITY_LABELS = {
		critical: __( 'Critical', 'sitecraft-accessibility' ),
		serious: __( 'Serious', 'sitecraft-accessibility' ),
		moderate: __( 'Moderate', 'sitecraft-accessibility' ),
		minor: __( 'Minor', 'sitecraft-accessibility' )
	};

	/**
	 * Sorts findings worst first so the list opens on what matters.
	 *
	 * @param {Array} issues Findings from the REST route.
	 * @return {Array} Sorted copy.
	 */
	function bySeverity( issues ) {
		return issues.slice().sort( function ( first, second ) {
			var a = SEVERITY_ORDER.indexOf( first.severity );
			var b = SEVERITY_ORDER.indexOf( second.severity );

			return ( -1 === a ? 99 : a ) - ( -1 === b ? 99 : b );
		} );
	}

	/**
	 * Renders one finding.
	 *
	 * @param {Object} issue Finding.
	 * @param {number} index Position in the list, used only as a React key.
	 * @return {Object} Element.
	 */
	function renderIssue( issue, index ) {
		return el(
			'li',
			{ key: 'sc-issue-' + index, className: 'sc-a11y-editor-issue' },
			el(
				'p',
				{ className: 'sc-a11y-editor-issue__title' },
				el(
					'span',
					{ className: 'sc-a11y-editor-badge sc-a11y-editor-badge--' + issue.severity },
					SEVERITY_LABELS[ issue.severity ] || issue.severity
				),
				' ',
				issue.title
			),
			el(
				'p',
				{ className: 'sc-a11y-editor-issue__meta' },
				sprintf(
					/* translators: 1: WCAG success criterion number, 2: conformance level */
					__( 'WCAG %1$s (Level %2$s)', 'sitecraft-accessibility' ),
					issue.criterion,
					issue.level
				)
			),
			issue.context
				? el( 'code', { className: 'sc-a11y-editor-issue__context' }, issue.context )
				: null,
			el( 'p', { className: 'sc-a11y-editor-issue__fix' }, issue.how_to_fix )
		);
	}

	/**
	 * Per-post language control, shown only when the site enabled the override.
	 *
	 * @return {Object|null} Element.
	 */
	function LanguageControl() {
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );

		if ( ! config.perPostLanguage ) {
			return null;
		}

		var key = config.languageMetaKey || '_sitecraft_a11y_lang';

		return el(
			PanelBody,
			{
				title: __( 'Content language', 'sitecraft-accessibility' ),
				initialOpen: false
			},
			el( TextControl, {
				label: __( 'Language tag', 'sitecraft-accessibility' ),
				help: __( 'Leave empty to use the site language. Use a BCP 47 tag such as fr, de-AT or pt-BR when this post is written in another language, so screen readers pronounce it correctly.', 'sitecraft-accessibility' ),
				value: meta[ key ] || '',
				onChange: function ( value ) {
					var update = {};

					update[ key ] = value;

					wp.data.dispatch( 'core/editor' ).editPost( { meta: update } );
				}
			} )
		);
	}

	/**
	 * The sidebar itself.
	 *
	 * @return {Object} Element.
	 */
	function AccessibilitySidebar() {
		var content = useSelect( function ( select ) {
			var store = select( 'core/editor' );

			return store ? store.getEditedPostContent() : '';
		}, [] );

		var stateIssues = useState( [] );
		var issues = stateIssues[ 0 ];
		var setIssues = stateIssues[ 1 ];

		var stateBusy = useState( false );
		var busy = stateBusy[ 0 ];
		var setBusy = stateBusy[ 1 ];

		var stateError = useState( '' );
		var error = stateError[ 0 ];
		var setError = stateError[ 1 ];

		useEffect( function () {
			if ( ! apiFetch ) {
				return undefined;
			}

			var cancelled = false;

			// Typing produces a new content string on every keystroke. Waiting for a
			// pause keeps the editor responsive and the request count sane.
			var timer = window.setTimeout( function () {
				setBusy( true );

				apiFetch( {
					path: '/sitecraft/v1/a11y/analyse',
					method: 'POST',
					data: { content: content }
				} ).then( function ( response ) {
					if ( cancelled ) {
						return;
					}

					setIssues( response && response.issues ? response.issues : [] );
					setError( '' );
					setBusy( false );
				} ).catch( function ( failure ) {
					if ( cancelled ) {
						return;
					}

					setError( failure && failure.message ? failure.message : __( 'The accessibility check could not run.', 'sitecraft-accessibility' ) );
					setBusy( false );
				} );
			}, DEBOUNCE_MS );

			return function () {
				cancelled = true;
				window.clearTimeout( timer );
			};
		}, [ content ] );

		var body;

		if ( error ) {
			body = el( Notice, { status: 'error', isDismissible: false }, error );
		} else if ( busy && ! issues.length ) {
			body = el( 'p', { className: 'sc-a11y-editor-status' }, el( Spinner, null ), ' ', __( 'Checking...', 'sitecraft-accessibility' ) );
		} else if ( ! issues.length ) {
			body = el(
				'div',
				null,
				el(
					Notice,
					{ status: 'success', isDismissible: false },
					__( 'No automated issues found in this content.', 'sitecraft-accessibility' )
				),
				el(
					'p',
					{ className: 'sc-a11y-editor-caveat' },
					__( 'Automated rules catch roughly a third of WCAG. Keyboard operation, focus order, meaningful alt text and reading order still need a person.', 'sitecraft-accessibility' )
				)
			);
		} else {
			body = el(
				'div',
				null,
				el(
					'p',
					{ className: 'sc-a11y-editor-status' },
					sprintf(
						/* translators: %d: number of issues found */
						wp.i18n._n( '%d issue found.', '%d issues found.', issues.length, 'sitecraft-accessibility' ),
						issues.length
					)
				),
				el( 'ul', { className: 'sc-a11y-editor-list' }, bySeverity( issues ).map( renderIssue ) )
			);
		}

		return el(
			wp.element.Fragment,
			null,
			PluginSidebarMoreMenuItem
				? el(
					PluginSidebarMoreMenuItem,
					{ target: PLUGIN_NAME, icon: 'universal-access' },
					__( 'Accessibility', 'sitecraft-accessibility' )
				)
				: null,
			el(
				PluginSidebar,
				{
					name: PLUGIN_NAME,
					title: __( 'Accessibility', 'sitecraft-accessibility' ),
					icon: 'universal-access'
				},
				el(
					PanelBody,
					{ title: __( 'This post', 'sitecraft-accessibility' ), initialOpen: true },
					body,
					config.settingsUrl
						? el(
							Button,
							{ variant: 'link', href: config.settingsUrl },
							__( 'Audit settings', 'sitecraft-accessibility' )
						)
						: null
				),
				el( LanguageControl, null )
			)
		);
	}

	wp.plugins.registerPlugin( PLUGIN_NAME, {
		render: AccessibilitySidebar,
		icon: 'universal-access'
	} );
}( window.wp ) );
