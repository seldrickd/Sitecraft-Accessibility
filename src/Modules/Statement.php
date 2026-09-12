<?php
/**
 * Accessibility statement generator.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Admin\Notices;
use Sitecraft\Accessibility\Core\Container;
use Sitecraft\Accessibility\Core\Module;
use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Core\Support\Logger;
use Sitecraft\Accessibility\Support\Settings;

/**
 * Builds the published accessibility statement from the settings.
 *
 * A statement is a legal artefact, not marketing copy. The European Accessibility
 * Act and the public-sector directives both require one, and they require specific
 * things in it: what standard was measured against, what is known not to conform,
 * how to report a barrier, and where to escalate when the reply is unsatisfactory.
 * The generator refuses to invent any of that - an unanswered field is simply
 * omitted, because a statement that claims a process nobody operates is worse than
 * a short one.
 */
final class Statement implements Module {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	public const SHORTCODE = 'sitecraft_accessibility_statement';

	/**
	 * `admin_post` action that creates the statement page.
	 *
	 * @var string
	 */
	public const CREATE_ACTION = 'sitecraft_a11y_create_statement_page';

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'statement';
	}

	/**
	 * Binds the module to WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_shortcode' ) );

		if ( is_admin() ) {
			add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle_create_page' ) );
			add_action( 'sitecraft_a11y_settings_after_section', array( $this, 'render_page_action' ) );
		}
	}

	/**
	 * Registers the shortcode when the feature is on.
	 *
	 * @return void
	 */
	public function register_shortcode(): void {
		if ( ! $this->settings()->is_enabled( 'statement_enabled' ) ) {
			return;
		}

		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	/**
	 * Renders the statement.
	 *
	 * @param array<string, string>|string $attributes Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts(
			array(
				'heading' => 'h2',
			),
			is_array( $attributes ) ? $attributes : array(),
			self::SHORTCODE
		);

		// The statement is usually placed under the page title, so the sub-heading level
		// has to be adjustable rather than hard coded to h2.
		$tag = in_array( strtolower( (string) $attributes['heading'] ), array( 'h2', 'h3', 'h4' ), true )
			? strtolower( (string) $attributes['heading'] )
			: 'h2';

		$parts = array();

		$parts[] = '<div class="sc-a11y-statement">';
		$parts[] = $this->intro( $tag );
		$parts[] = $this->conformance_section( $tag );
		$parts[] = $this->limitations_section( $tag );
		$parts[] = $this->feedback_section( $tag );
		$parts[] = $this->enforcement_section( $tag );
		$parts[] = $this->assessment_section( $tag );
		$parts[] = '</div>';

		return implode( "\n", array_filter( $parts ) );
	}

	/**
	 * Opening paragraph.
	 *
	 * @param string $tag Heading tag.
	 * @return string
	 */
	private function intro( string $tag ): string {
		$organisation = $this->organisation();

		return sprintf(
			'<%1$s>%2$s</%1$s><p>%3$s</p>',
			esc_attr( $tag ),
			esc_html__( 'Accessibility statement', 'sitecraft-accessibility' ),
			esc_html(
				sprintf(
					/* translators: 1: organisation name, 2: site name */
					__( '%1$s is committed to making %2$s accessible to as many people as possible, including people who use assistive technology.', 'sitecraft-accessibility' ),
					$organisation,
					get_bloginfo( 'name' )
				)
			)
		);
	}

	/**
	 * Conformance claim.
	 *
	 * @param string $tag Heading tag.
	 * @return string
	 */
	private function conformance_section( string $tag ): string {
		$settings = $this->settings();
		$standard = $this->standard_label( (string) $settings->get( 'statement_standard', 'wcag-22-aa' ) );
		$claim    = (string) $settings->get( 'statement_conformance', 'partial' );

		$sentences = array(
			'full'    => __( 'This website is fully conformant with %s. Fully conformant means that the content meets the standard in full, with no exceptions found at the time of assessment.', 'sitecraft-accessibility' ),
			'partial' => __( 'This website is partially conformant with %s. Partially conformant means that some parts of the content do not fully conform to the standard; those parts are listed below.', 'sitecraft-accessibility' ),
			'none'    => __( 'This website is not conformant with %s. The known barriers are listed below, together with what is being done about them.', 'sitecraft-accessibility' ),
		);

		$sentence = $sentences[ $claim ] ?? $sentences['partial'];

		return sprintf(
			'<%1$s>%2$s</%1$s><p>%3$s</p>',
			esc_attr( $tag ),
			esc_html__( 'Conformance status', 'sitecraft-accessibility' ),
			esc_html( sprintf( $sentence, $standard ) )
		);
	}

	/**
	 * Known limitations, one list item per line.
	 *
	 * @param string $tag Heading tag.
	 * @return string
	 */
	private function limitations_section( string $tag ): string {
		$raw = trim( (string) $this->settings()->get( 'statement_limitations', '' ) );

		if ( '' === $raw ) {
			return '';
		}

		$lines = array_filter( array_map( 'trim', (array) preg_split( '/\R/', $raw ) ), 'strlen' );

		if ( empty( $lines ) ) {
			return '';
		}

		$items = '';

		foreach ( $lines as $line ) {
			$items .= sprintf( '<li>%s</li>', esc_html( $line ) );
		}

		return sprintf(
			'<%1$s>%2$s</%1$s><p>%3$s</p><ul>%4$s</ul>',
			esc_attr( $tag ),
			esc_html__( 'Known limitations', 'sitecraft-accessibility' ),
			esc_html__( 'The following content is known not to meet the standard:', 'sitecraft-accessibility' ),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each item escaped with esc_html above.
		);
	}

	/**
	 * Feedback route and process.
	 *
	 * @param string $tag Heading tag.
	 * @return string
	 */
	private function feedback_section( string $tag ): string {
		$settings = $this->settings();
		$email    = sanitize_email( (string) $settings->get( 'statement_contact_email', '' ) );
		$url      = esc_url_raw( (string) $settings->get( 'statement_contact_url', '' ) );
		$process  = trim( (string) $settings->get( 'statement_feedback_process', '' ) );

		if ( '' === $email ) {
			$email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		}

		$routes = array();

		if ( '' !== $email && is_email( $email ) ) {
			$routes[] = sprintf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( 'mailto:' . $email ),
				esc_html( $email )
			);
		}

		if ( '' !== $url ) {
			$routes[] = sprintf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( $url ),
				esc_html__( 'Accessibility feedback form', 'sitecraft-accessibility' )
			);
		}

		if ( empty( $routes ) ) {
			return '';
		}

		return sprintf(
			'<%1$s>%2$s</%1$s><p>%3$s</p><ul>%4$s</ul>%5$s',
			esc_attr( $tag ),
			esc_html__( 'Reporting a problem', 'sitecraft-accessibility' ),
			esc_html__( 'If you find a barrier on this site, or you need content in a different format, please tell us:', 'sitecraft-accessibility' ),
			implode( '', $routes ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each route escaped above.
			'' === $process ? '' : sprintf( '<p>%s</p>', esc_html( $process ) )
		);
	}

	/**
	 * Enforcement procedure.
	 *
	 * @param string $tag Heading tag.
	 * @return string
	 */
	private function enforcement_section( string $tag ): string {
		$text = trim( (string) $this->settings()->get( 'statement_enforcement_contact', '' ) );

		if ( '' === $text ) {
			return '';
		}

		return sprintf(
			'<%1$s>%2$s</%1$s><p>%3$s</p>',
			esc_attr( $tag ),
			esc_html__( 'Enforcement procedure', 'sitecraft-accessibility' ),
			esc_html( $text )
		);
	}

	/**
	 * How and when the site was assessed.
	 *
	 * @param string $tag Heading tag.
	 * @return string
	 */
	private function assessment_section( string $tag ): string {
		$settings = $this->settings();
		$date     = Settings::sanitize_date( $settings->get( 'statement_assessment_date', '' ) );
		$method   = (string) $settings->get( 'statement_assessment_method', 'self' );

		$methods = array(
			'self'     => __( 'a self-assessment carried out by the organisation itself', 'sitecraft-accessibility' ),
			'external' => __( 'an independent audit carried out by a third party', 'sitecraft-accessibility' ),
			'mixed'    => __( 'a self-assessment reviewed by an external specialist', 'sitecraft-accessibility' ),
		);

		$description = $methods[ $method ] ?? $methods['self'];

		$sentence = '' === $date
			? sprintf(
				/* translators: %s: assessment method */
				__( 'This statement is based on %s.', 'sitecraft-accessibility' ),
				$description
			)
			: sprintf(
				/* translators: 1: assessment method, 2: assessment date */
				__( 'This statement is based on %1$s, last carried out on %2$s.', 'sitecraft-accessibility' ),
				$description,
				wp_date( (string) get_option( 'date_format', 'Y-m-d' ), (int) strtotime( $date . ' 12:00:00' ) )
			);

		return sprintf(
			'<%1$s>%2$s</%1$s><p>%3$s</p><p>%4$s</p>',
			esc_attr( $tag ),
			esc_html__( 'How this site was assessed', 'sitecraft-accessibility' ),
			esc_html( $sentence ),
			esc_html__( 'Automated testing was part of that assessment. Automated tools detect a minority of accessibility problems, so manual testing with a keyboard and a screen reader was used alongside them.', 'sitecraft-accessibility' )
		);
	}

	/**
	 * Human readable name of the referenced standard.
	 *
	 * @param string $key Stored standard key.
	 * @return string
	 */
	private function standard_label( string $key ): string {
		$labels = array(
			'wcag-21-aa'  => __( 'the Web Content Accessibility Guidelines 2.1 Level AA', 'sitecraft-accessibility' ),
			'wcag-22-aa'  => __( 'the Web Content Accessibility Guidelines 2.2 Level AA', 'sitecraft-accessibility' ),
			'en-301-549'  => __( 'EN 301 549, which incorporates the Web Content Accessibility Guidelines', 'sitecraft-accessibility' ),
			'section-508' => __( 'Section 508 of the US Rehabilitation Act', 'sitecraft-accessibility' ),
		);

		return $labels[ $key ] ?? $labels['wcag-22-aa'];
	}

	/**
	 * Organisation responsible for the site.
	 *
	 * @return string
	 */
	private function organisation(): string {
		$organisation = trim( (string) $this->settings()->get( 'statement_organisation', '' ) );

		return '' === $organisation ? (string) get_bloginfo( 'name' ) : $organisation;
	}

	/**
	 * Renders the "create statement page" control below the statement settings tab.
	 *
	 * @param string $tab Settings tab being rendered.
	 * @return void
	 */
	public function render_page_action( $tab ): void {
		if ( 'statement' !== $tab || ! current_user_can( Settings::capability() ) ) {
			return;
		}

		$page_id = (int) $this->settings()->get( 'statement_page_id', 0 );
		$page    = $page_id > 0 ? get_post( $page_id ) : null;

		echo '<div class="sc-actions">';

		if ( null !== $page && 'trash' !== $page->post_status ) {
			printf(
				'<a class="button button-secondary" href="%1$s">%2$s</a><a class="button button-link" href="%3$s">%4$s</a>',
				esc_url( (string) get_edit_post_link( $page_id ) ),
				esc_html__( 'Edit the statement page', 'sitecraft-accessibility' ),
				esc_url( (string) get_permalink( $page_id ) ),
				esc_html__( 'View it', 'sitecraft-accessibility' )
			);

			echo '</div>';

			return;
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::CREATE_ACTION, self::CREATE_ACTION );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::CREATE_ACTION ) );
		printf(
			'<button type="submit" class="button button-secondary">%s</button>',
			esc_html__( 'Create the statement page', 'sitecraft-accessibility' )
		);
		printf(
			'<span class="sc-card-meta">%s</span>',
			esc_html__( 'Creates a published page containing the shortcode, and remembers its ID here.', 'sitecraft-accessibility' )
		);
		echo '</form></div>';
	}

	/**
	 * Creates the statement page.
	 *
	 * @return void
	 */
	public function handle_create_page(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to create the statement page.', 'sitecraft-accessibility' ),
				403
			);
		}

		check_admin_referer( self::CREATE_ACTION, self::CREATE_ACTION );

		$settings = $this->settings();
		$existing = (int) $settings->get( 'statement_page_id', 0 );

		if ( $existing > 0 && null !== get_post( $existing ) ) {
			$this->redirect_back();
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Accessibility statement', 'sitecraft-accessibility' ),
				'post_name'    => 'accessibility-statement',
				'post_content' => '[' . self::SHORTCODE . ']',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			Logger::error( 'Could not create the statement page.', array( 'error' => $page_id->get_error_message() ) );

			Notices::add(
				__( 'The statement page could not be created. See the debug log for the reason.', 'sitecraft-accessibility' ),
				'error'
			);

			$this->redirect_back();
		}

		$settings->save( array( 'statement_page_id' => (int) $page_id ) );

		Notices::add( __( 'Statement page created and published.', 'sitecraft-accessibility' ), 'success' );

		$this->redirect_back();
	}

	/**
	 * Returns to the statement settings tab.
	 *
	 * @return void
	 */
	private function redirect_back(): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'sitecraft-accessibility-settings',
					'tab'  => 'statement',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Settings schema.
	 *
	 * @return Registry
	 */
	private function settings(): Registry {
		return $this->container->get( 'settings' );
	}
}
