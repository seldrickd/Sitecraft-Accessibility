<?php
/**
 * Plugin bootstrap.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the container, registers modules and exposes plugin-wide metadata.
 *
 * The bootstrap is intentionally thin: it decides *what* runs, never *how* a
 * feature behaves. Features live in modules so each can be reasoned about, tested
 * and disabled on its own.
 */
final class Plugin {

	/**
	 * Single bootstrap instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Registered module instances.
	 *
	 * @var Module[]
	 */
	private array $modules = array();

	/**
	 * Whether `register()` has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Constructor.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 */
	private function __construct( string $file ) {
		$this->file      = $file;
		$this->container = new Container();
	}

	/**
	 * Returns the bootstrap, creating it on first call.
	 *
	 * @param string|null $file Absolute path to the main plugin file.
	 * @return Plugin
	 */
	public static function instance( ?string $file = null ): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self( (string) $file );
		}

		return self::$instance;
	}

	/**
	 * Returns the service container.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @return string
	 */
	public function file(): string {
		return $this->file;
	}

	/**
	 * Absolute filesystem path to the plugin directory, with trailing slash.
	 *
	 * @param string $append Optional relative path to append.
	 * @return string
	 */
	public function path( string $append = '' ): string {
		return plugin_dir_path( $this->file ) . ltrim( $append, '/' );
	}

	/**
	 * Public URL of the plugin directory, with trailing slash.
	 *
	 * @param string $append Optional relative path to append.
	 * @return string
	 */
	public function url( string $append = '' ): string {
		return plugin_dir_url( $this->file ) . ltrim( $append, '/' );
	}

	/**
	 * Registers a module instance.
	 *
	 * @param Module $module Module to register.
	 * @return self
	 */
	public function add_module( Module $module ): self {
		$this->modules[ $module->id() ] = $module;

		return $this;
	}

	/**
	 * Returns a registered module by id.
	 *
	 * @param string $id Module id.
	 * @return Module|null
	 */
	public function module( string $id ): ?Module {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Boots every registered module exactly once.
	 *
	 * Modules can be filtered out by third parties, which makes the whole feature
	 * set opt-out without forking the plugin.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		foreach ( $this->modules as $id => $module ) {
			/**
			 * Filters whether an individual module boots.
			 *
			 * @param bool   $enabled Whether the module should register its hooks.
			 * @param string $id      Module identifier.
			 */
			if ( ! apply_filters( 'sitecraft_a11y_module_enabled', true, $id ) ) {
				continue;
			}

			$module->register();
		}

		/**
		 * Fires once every module has registered its hooks.
		 *
		 * @param Plugin $plugin Bootstrap instance.
		 */
		do_action( 'sitecraft_a11y_registered', $this );
	}
}
