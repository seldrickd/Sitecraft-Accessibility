<?php
/**
 * Settings schema factory.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Support\Fields\AdvancedFields;
use Sitecraft\Accessibility\Support\Fields\PanelFields;
use Sitecraft\Accessibility\Support\Fields\RemediationFields;
use Sitecraft\Accessibility\Support\Fields\ScannerFields;
use Sitecraft\Accessibility\Support\Fields\StatementFields;

/**
 * Builds the one settings schema the whole plugin reads from.
 *
 * The schema is data. Screens render it, the sanitizer validates against it, the
 * modules read values out of it, and `uninstall.php` derives the option list from
 * it. Adding a setting is a single entry in one of the field groups.
 */
final class Settings {

	/**
	 * Option name holding every setting.
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'sitecraft_a11y_settings';

	/**
	 * Memoized schema for the current request.
	 *
	 * A single instance matters: `Registry` caches the stored values, and two live
	 * instances would disagree the moment one of them saves.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $registry = null;

	/**
	 * Returns the fully populated settings schema.
	 *
	 * @return Registry
	 */
	public static function create(): Registry {
		if ( self::$registry instanceof Registry ) {
			return self::$registry;
		}

		$registry = new Registry( self::OPTION_NAME );

		self::add_sections( $registry );

		$groups = array(
			ScannerFields::class,
			RemediationFields::class,
			PanelFields::class,
			StatementFields::class,
			AdvancedFields::class,
		);

		foreach ( $groups as $group ) {
			foreach ( $group::fields() as $field ) {
				$registry->add_field( $field );
			}
		}

		/**
		 * Fires once the built-in schema is declared.
		 *
		 * The registry is mutable, so an add-on can append its own sections and
		 * fields here and inherit the sanitizer and renderer for free.
		 *
		 * @param Registry $registry Settings schema.
		 */
		do_action( 'sitecraft_a11y_settings_registry', $registry );

		self::$registry = $registry;

		return $registry;
	}

	/**
	 * Declares the five settings sections, which map one-to-one onto the settings tabs.
	 *
	 * @param Registry $registry Schema being built.
	 * @return void
	 */
	private static function add_sections( Registry $registry ): void {
		$registry->add_section(
			'scanner',
			__( 'Scanner', 'sitecraft-accessibility' ),
			__( 'Controls what the audit looks at and how hard it works. Scanning is always batched through WP-Cron, so raising the batch size trades a longer cron run for a faster full pass.', 'sitecraft-accessibility' )
		);

		$registry->add_section(
			'remediation',
			__( 'Remediation', 'sitecraft-accessibility' ),
			__( 'Server-side markup fixes. Each one changes the HTML your visitors actually receive, so each one can be turned off independently if your theme already handles it.', 'sitecraft-accessibility' )
		);

		$registry->add_section(
			'panel',
			__( 'Preference panel', 'sitecraft-accessibility' ),
			__( 'An optional reader-convenience panel. It is off by default and it is not a conformance mechanism: enabling it does not make an inaccessible page accessible, and it must never be presented to visitors as if it did.', 'sitecraft-accessibility' )
		);

		$registry->add_section(
			'statement',
			__( 'Accessibility statement', 'sitecraft-accessibility' ),
			__( 'The European Accessibility Act and most public-sector regulations require a published statement. These values populate the [sitecraft_accessibility_statement] shortcode.', 'sitecraft-accessibility' )
		);

		$registry->add_section(
			'advanced',
			__( 'Advanced', 'sitecraft-accessibility' ),
			__( 'Capabilities, retention, integration surfaces and what happens to your data when the plugin is deleted.', 'sitecraft-accessibility' )
		);
	}

	/**
	 * Capability required to manage this plugin.
	 *
	 * @return string
	 */
	public static function capability(): string {
		$capability = (string) self::create()->get( 'capability', 'manage_options' );

		if ( '' === trim( $capability ) ) {
			$capability = 'manage_options';
		}

		/**
		 * Filters the capability that guards every admin screen and write path.
		 *
		 * @param string $capability WordPress capability name.
		 */
		return (string) apply_filters( 'sitecraft_a11y_capability', $capability );
	}

	/**
	 * Post types a site may reasonably audit.
	 *
	 * @return array<string, string>
	 */
	public static function post_type_choices(): array {
		$choices = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			// Attachments carry no post_content worth parsing; their alt text lives in meta.
			if ( 'attachment' === $post_type->name ) {
				continue;
			}

			$choices[ $post_type->name ] = $post_type->labels->name ?? $post_type->name;
		}

		if ( empty( $choices ) ) {
			$choices = array(
				'post' => __( 'Posts', 'sitecraft-accessibility' ),
				'page' => __( 'Pages', 'sitecraft-accessibility' ),
			);
		}

		return $choices;
	}

	/**
	 * Severity labels, ordered from most to least urgent.
	 *
	 * @return array<string, string>
	 */
	public static function severity_choices(): array {
		return array(
			'critical' => __( 'Critical', 'sitecraft-accessibility' ),
			'serious'  => __( 'Serious', 'sitecraft-accessibility' ),
			'moderate' => __( 'Moderate', 'sitecraft-accessibility' ),
			'minor'    => __( 'Minor', 'sitecraft-accessibility' ),
		);
	}

	/**
	 * The rule identifiers the scanner ships with, as id => human title.
	 *
	 * Declared here rather than derived from the rule classes so the settings screen
	 * never has to instantiate the whole rule set just to draw a checkbox list.
	 *
	 * @return array<string, string>
	 */
	public static function rule_choices(): array {
		$rules = array(
			'img-alt-missing'       => __( 'Image with no alt attribute (1.1.1)', 'sitecraft-accessibility' ),
			'img-alt-filename'      => __( 'Alt text that is only a filename (1.1.1)', 'sitecraft-accessibility' ),
			'link-empty'            => __( 'Link with no accessible name (2.4.4)', 'sitecraft-accessibility' ),
			'link-generic-text'     => __( 'Generic link text such as "click here" (2.4.4)', 'sitecraft-accessibility' ),
			'link-raw-url'          => __( 'Link text that is a bare URL (2.4.4)', 'sitecraft-accessibility' ),
			'heading-order-skip'    => __( 'Heading level skipped (1.3.1)', 'sitecraft-accessibility' ),
			'heading-empty'         => __( 'Empty heading (1.3.1)', 'sitecraft-accessibility' ),
			'table-no-header'       => __( 'Data table without header cells (1.3.1)', 'sitecraft-accessibility' ),
			'table-layout'          => __( 'Table used for layout without a presentation role (1.3.1)', 'sitecraft-accessibility' ),
			'iframe-no-title'       => __( 'Iframe without a title attribute (4.1.2)', 'sitecraft-accessibility' ),
			'input-no-label'        => __( 'Form control without a label (3.3.2)', 'sitecraft-accessibility' ),
			'tabindex-positive'     => __( 'Positive tabindex (2.4.3)', 'sitecraft-accessibility' ),
			'duplicate-id'          => __( 'Duplicate id attribute (4.1.1)', 'sitecraft-accessibility' ),
			'media-autoplay'        => __( 'Media that autoplays unmuted (1.4.2)', 'sitecraft-accessibility' ),
			'color-contrast-inline' => __( 'Insufficient contrast in inline styles (1.4.3)', 'sitecraft-accessibility' ),
		);

		/**
		 * Filters the rule list offered in the admin and accepted by the sanitizer.
		 *
		 * @param array<string, string> $rules Rule id => translated title.
		 */
		return (array) apply_filters( 'sitecraft_a11y_rule_choices', $rules );
	}

	/**
	 * Sanitizes a `YYYY-MM-DD` date, discarding anything that is not a real calendar date.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string Valid date, or an empty string.
	 */
	public static function sanitize_date( $value ): string {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$parts = explode( '-', $value );

		if ( 3 !== count( $parts ) || ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
			return '';
		}

		return sprintf( '%04d-%02d-%02d', (int) $parts[0], (int) $parts[1], (int) $parts[2] );
	}

	/**
	 * Sanitizes a newline separated list of post IDs into unique positive integers.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int[]
	 */
	public static function sanitize_id_list( $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/\R|,/', (string) $value );

		if ( ! is_array( $lines ) ) {
			return array();
		}

		$ids = array_filter( array_map( 'absint', $lines ) );

		return array_values( array_unique( $ids ) );
	}
}
