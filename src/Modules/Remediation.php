<?php
/**
 * Server-side remediation module.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Container;
use Sitecraft\Accessibility\Core\Module;
use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Modules\Remediation\Fix;
use Sitecraft\Accessibility\Modules\Remediation\FocusVisibility;
use Sitecraft\Accessibility\Modules\Remediation\ImageAlt;
use Sitecraft\Accessibility\Modules\Remediation\Landmarks;
use Sitecraft\Accessibility\Modules\Remediation\LanguageAttribute;
use Sitecraft\Accessibility\Modules\Remediation\ReducedMotion;
use Sitecraft\Accessibility\Modules\Remediation\SkipLink;
use Sitecraft\Accessibility\Modules\Remediation\Stylesheet;
use Sitecraft\Accessibility\Modules\Remediation\TargetSize;
use Sitecraft\Accessibility\Modules\Scanner\Repository;

/**
 * Applies the markup fixes the site has switched on.
 *
 * Everything here changes the HTML the server sends. That is the whole design:
 * an overlay repaints a finished page in the browser, where it competes with the
 * assistive technology the visitor already runs, and where anything that fails to
 * load leaves the original barrier in place. A fix emitted server-side is simply
 * part of the document.
 */
final class Remediation implements Module {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Shared inline stylesheet.
	 *
	 * @var Stylesheet
	 */
	private Stylesheet $stylesheet;

	/**
	 * Fixes that registered their hooks, keyed by fix id.
	 *
	 * @var array<string, Fix>
	 */
	private array $active = array();

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container  = $container;
		$this->stylesheet = new Stylesheet();
	}

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'remediation';
	}

	/**
	 * Binds the module to WordPress.
	 *
	 * Deferred to `init` because the language fix registers post meta and the image
	 * fix reads the audited post types, neither of which is settled on
	 * `plugins_loaded`.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_fixes' ), 5 );
	}

	/**
	 * Registers every enabled fix.
	 *
	 * @return void
	 */
	public function register_fixes(): void {
		$settings = $this->settings();

		if ( ! $settings->is_enabled( 'remediation_enabled' ) ) {
			return;
		}

		foreach ( $this->fixes() as $fix ) {
			if ( ! $fix->is_enabled() ) {
				continue;
			}

			/**
			 * Filters whether an individual remediation runs.
			 *
			 * Finer grained than the settings toggle, so a theme can suppress one fix on
			 * one template without turning the feature off site-wide.
			 *
			 * @param bool   $enabled Whether the fix should bind its hooks.
			 * @param string $id      Fix identifier.
			 */
			if ( ! apply_filters( 'sitecraft_a11y_fix_enabled', true, $fix->id() ) ) {
				continue;
			}

			$fix->register();

			$this->active[ $fix->id() ] = $fix;
		}
	}

	/**
	 * Ids of the fixes currently applying.
	 *
	 * @return string[]
	 */
	public function active_fixes(): array {
		return array_keys( $this->active );
	}

	/**
	 * Every fix this module can apply, in the order they affect the document.
	 *
	 * @return Fix[]
	 */
	private function fixes(): array {
		$settings = $this->settings();

		$fixes = array(
			new SkipLink( $settings, $this->stylesheet ),
			new Landmarks( $settings, $this->stylesheet ),
			new FocusVisibility( $settings, $this->stylesheet ),
			new LanguageAttribute( $settings, $this->stylesheet ),
			new ReducedMotion( $settings, $this->stylesheet ),
			new TargetSize( $settings, $this->stylesheet ),
			new ImageAlt( $settings, $this->stylesheet, $this->repository() ),
		);

		/**
		 * Filters the remediation set.
		 *
		 * @param Fix[]    $fixes    Fix instances.
		 * @param Registry $settings Settings schema.
		 */
		$filtered = (array) apply_filters( 'sitecraft_a11y_fixes', $fixes, $settings );

		return array_values(
			array_filter(
				$filtered,
				static function ( $fix ): bool {
					return $fix instanceof Fix;
				}
			)
		);
	}

	/**
	 * Findings store, shared with the scanner.
	 *
	 * @return Repository
	 */
	private function repository(): Repository {
		if ( ! $this->container->has( 'scanner.repository' ) ) {
			$this->container->set(
				'scanner.repository',
				static function (): Repository {
					return new Repository();
				}
			);
		}

		return $this->container->get( 'scanner.repository' );
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
