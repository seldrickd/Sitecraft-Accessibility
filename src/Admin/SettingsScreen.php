<?php
/**
 * Settings screen.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Admin\Screen;
use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Support\Installer;

/**
 * Tabbed settings form.
 *
 * Each tab renders exactly one schema section, so the tab list and the section
 * list cannot drift: adding a section to the registry is all it takes to get a
 * working tab, and every field on it inherits the shared sanitizer.
 */
final class SettingsScreen extends Screen {

	/**
	 * Constructor.
	 *
	 * @param Registry $settings Settings schema.
	 */
	public function __construct( Registry $settings ) {
		parent::__construct( $settings );

		// Read from the schema directly: Settings::capability() would resolve the
		// same registry, and the value must reflect what is stored right now.
		$capability = (string) $settings->get( 'capability', 'manage_options' );

		$this->capability = '' === trim( $capability ) ? 'manage_options' : $capability;
	}

	/**
	 * Menu slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'sitecraft-accessibility-settings';
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Accessibility settings', 'sitecraft-accessibility' );
	}

	/**
	 * One tab per schema section, in declaration order.
	 *
	 * @return array<string, string>
	 */
	protected function tabs(): array {
		$tabs = array();

		foreach ( $this->settings->sections() as $id => $section ) {
			$tabs[ $id ] = (string) $section['title'];
		}

		return $tabs;
	}

	/**
	 * Renders the form for the active tab.
	 *
	 * @return void
	 */
	protected function render_body(): void {
		$tab = $this->current_tab();

		$this->open_form();
		$this->renderer->render_section( $tab );
		$this->close_form();

		/**
		 * Fires after a settings tab has been rendered.
		 *
		 * Gives a module somewhere to put an action that belongs beside its own
		 * settings but is not itself a setting, such as the statement generator's
		 * "create page" button. Rendered outside the settings form so its own nonce
		 * and its own handler stay separate from the save path.
		 *
		 * @param string $tab Tab that was rendered.
		 */
		do_action( 'sitecraft_a11y_settings_after_section', $tab );
	}

	/**
	 * Reconciles everything that lives outside the settings option after a save.
	 *
	 * Cron cadence and the standalone uninstall flag are both derived state. Writing
	 * them here means the UI is the single place a site owner has to touch.
	 *
	 * @return void
	 */
	protected function after_save(): void {
		Installer::schedule_events();

		// uninstall.php runs with the plugin unloaded, so the flag it reads must be a
		// plain option rather than a key inside the serialised settings array.
		update_option(
			Installer::KEEP_DATA_OPTION,
			(bool) $this->settings->get( 'keep_data_on_uninstall', false ),
			false
		);
	}
}
