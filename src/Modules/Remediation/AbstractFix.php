<?php
/**
 * Shared state for remediation fixes.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Remediation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Settings\Registry;

/**
 * Gives every fix its settings and the shared stylesheet collector.
 */
abstract class AbstractFix implements Fix {

	/**
	 * Settings schema.
	 *
	 * @var Registry
	 */
	protected Registry $settings;

	/**
	 * Shared inline stylesheet.
	 *
	 * @var Stylesheet
	 */
	protected Stylesheet $stylesheet;

	/**
	 * Constructor.
	 *
	 * @param Registry   $settings   Settings schema.
	 * @param Stylesheet $stylesheet Shared inline stylesheet.
	 */
	public function __construct( Registry $settings, Stylesheet $stylesheet ) {
		$this->settings   = $settings;
		$this->stylesheet = $stylesheet;
	}

	/**
	 * Clamps a numeric setting to a sane pixel range.
	 *
	 * Values reach here from the settings option, which a WP-CLI `option update` can
	 * write past the schema, so the CSS writer never trusts the stored number.
	 *
	 * @param string $key      Setting id.
	 * @param int    $fallback Value used when the setting is missing.
	 * @param int    $min      Lowest permitted value.
	 * @param int    $max      Highest permitted value.
	 * @return int
	 */
	protected function pixels( string $key, int $fallback, int $min, int $max ): int {
		$value = (int) $this->settings->get( $key, $fallback );

		return max( $min, min( $max, $value ) );
	}

	/**
	 * Returns a stored colour, falling back when it is not a valid hex value.
	 *
	 * @param string $key      Setting id.
	 * @param string $fallback Colour used when the stored value is unusable.
	 * @return string
	 */
	protected function color( string $key, string $fallback ): string {
		$color = sanitize_hex_color( (string) $this->settings->get( $key, $fallback ) );

		return null === $color ? $fallback : $color;
	}
}
