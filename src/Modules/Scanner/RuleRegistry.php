<?php
/**
 * Rule set assembly.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Modules\Scanner\Rules\ColorContrastInline;
use Sitecraft\Accessibility\Modules\Scanner\Rules\DuplicateId;
use Sitecraft\Accessibility\Modules\Scanner\Rules\HeadingEmpty;
use Sitecraft\Accessibility\Modules\Scanner\Rules\HeadingOrderSkip;
use Sitecraft\Accessibility\Modules\Scanner\Rules\IframeNoTitle;
use Sitecraft\Accessibility\Modules\Scanner\Rules\ImageAltFilename;
use Sitecraft\Accessibility\Modules\Scanner\Rules\ImageAltMissing;
use Sitecraft\Accessibility\Modules\Scanner\Rules\InputNoLabel;
use Sitecraft\Accessibility\Modules\Scanner\Rules\LinkEmpty;
use Sitecraft\Accessibility\Modules\Scanner\Rules\LinkGenericText;
use Sitecraft\Accessibility\Modules\Scanner\Rules\LinkRawUrl;
use Sitecraft\Accessibility\Modules\Scanner\Rules\MediaAutoplay;
use Sitecraft\Accessibility\Modules\Scanner\Rules\TableLayout;
use Sitecraft\Accessibility\Modules\Scanner\Rules\TableNoHeader;
use Sitecraft\Accessibility\Modules\Scanner\Rules\TabindexPositive;

/**
 * Builds the active rule set from the settings schema.
 *
 * Two filters apply, and they mean different things. A conformance level that is
 * not selected removes criteria the site has chosen not to measure against; a
 * disabled rule id removes a check the site handles elsewhere. Both are honoured
 * here so no rule has to know it might be switched off.
 */
final class RuleRegistry {

	/**
	 * Active rules keyed by rule id.
	 *
	 * @var array<string, Rule>
	 */
	private array $rules = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, Rule> $rules Active rules keyed by id.
	 */
	private function __construct( array $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Builds the rule set the settings ask for.
	 *
	 * @param Registry $settings Settings schema.
	 * @return self
	 */
	public static function create( Registry $settings ): self {
		$levels   = (array) $settings->get( 'scan_levels', array( 'A', 'AA' ) );
		$disabled = (array) $settings->get( 'scan_disabled_rules', array() );

		$candidates = self::all_rules( $settings );

		/**
		 * Filters the complete rule set before levels and exclusions are applied.
		 *
		 * An add-on adds a rule here; it is then subject to the same level filtering
		 * and the same per-site opt-out as the built-in ones.
		 *
		 * @param Rule[]   $candidates Rule instances.
		 * @param Registry $settings   Settings schema.
		 */
		$candidates = (array) apply_filters( 'sitecraft_a11y_rules', $candidates, $settings );

		$active = array();

		foreach ( $candidates as $rule ) {
			if ( ! $rule instanceof Rule ) {
				continue;
			}

			if ( ! empty( $levels ) && ! in_array( $rule->level(), array_map( 'strval', $levels ), true ) ) {
				continue;
			}

			if ( in_array( $rule->id(), array_map( 'strval', $disabled ), true ) ) {
				continue;
			}

			$active[ $rule->id() ] = $rule;
		}

		return new self( $active );
	}

	/**
	 * Every rule this plugin ships, before filtering.
	 *
	 * @param Registry $settings Settings schema.
	 * @return Rule[]
	 */
	private static function all_rules( Registry $settings ): array {
		return array(
			new ImageAltMissing(),
			new ImageAltFilename(),
			new LinkEmpty(),
			new LinkGenericText(),
			new LinkRawUrl(),
			new HeadingOrderSkip(),
			new HeadingEmpty(),
			new TableNoHeader(),
			new TableLayout(),
			new IframeNoTitle(),
			new InputNoLabel(),
			new TabindexPositive(),
			new DuplicateId(),
			new MediaAutoplay(),
			new ColorContrastInline(
				(float) $settings->get( 'contrast_ratio_normal', 4.5 ),
				(float) $settings->get( 'contrast_ratio_large', 3.0 )
			),
		);
	}

	/**
	 * Active rules keyed by id.
	 *
	 * @return array<string, Rule>
	 */
	public function all(): array {
		return $this->rules;
	}

	/**
	 * Returns one active rule.
	 *
	 * @param string $id Rule id.
	 * @return Rule|null
	 */
	public function get( string $id ): ?Rule {
		return $this->rules[ $id ] ?? null;
	}

	/**
	 * Number of active rules.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->rules );
	}

	/**
	 * Rule metadata for REST responses and the editor sidebar.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function describe(): array {
		$described = array();

		foreach ( $this->rules as $rule ) {
			$described[] = array(
				'id'         => $rule->id(),
				'title'      => $rule->title(),
				'criterion'  => $rule->criterion(),
				'level'      => $rule->level(),
				'severity'   => $rule->severity(),
				'how_to_fix' => $rule->how_to_fix(),
			);
		}

		return $described;
	}
}
