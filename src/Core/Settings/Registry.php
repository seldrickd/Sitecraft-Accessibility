<?php
/**
 * Schema-driven settings registry.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares settings as data and derives storage, defaults and sanitization from it.
 *
 * Every field is described once. The registry then provides the default value map,
 * a type-aware sanitizer, and the metadata the renderer needs. Adding a setting is a
 * one-line schema change rather than edits across three layers.
 */
final class Registry {

	/**
	 * Option name that stores the whole settings array.
	 *
	 * @var string
	 */
	private string $option_name;

	/**
	 * Section definitions keyed by section id.
	 *
	 * @var array<string, array{id:string,title:string,description:string}>
	 */
	private array $sections = array();

	/**
	 * Field definitions keyed by field id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields = array();

	/**
	 * Memoized settings for the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Constructor.
	 *
	 * @param string $option_name Option name used for storage.
	 */
	public function __construct( string $option_name ) {
		$this->option_name = $option_name;
	}

	/**
	 * Returns the storage option name.
	 *
	 * @return string
	 */
	public function option_name(): string {
		return $this->option_name;
	}

	/**
	 * Declares a settings section.
	 *
	 * @param string $id          Section identifier.
	 * @param string $title       Translated section title.
	 * @param string $description Optional translated description.
	 * @return self
	 */
	public function add_section( string $id, string $title, string $description = '' ): self {
		$this->sections[ $id ] = array(
			'id'          => $id,
			'title'       => $title,
			'description' => $description,
		);

		return $this;
	}

	/**
	 * Declares a settings field.
	 *
	 * Recognised keys: id, section, type, label, description, default, choices,
	 * placeholder, min, max, step, rows, sanitize, depends_on, class.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return self
	 */
	public function add_field( array $field ): self {
		$field = wp_parse_args(
			$field,
			array(
				'id'          => '',
				'section'     => 'general',
				'type'        => 'text',
				'label'       => '',
				'description' => '',
				'default'     => '',
				'choices'     => array(),
				'placeholder' => '',
				'min'         => null,
				'max'         => null,
				'step'        => null,
				'rows'        => 5,
				'sanitize'    => null,
				'depends_on'  => array(),
				'class'       => '',
			)
		);

		if ( '' === $field['id'] ) {
			return $this;
		}

		$this->fields[ $field['id'] ] = $field;

		return $this;
	}

	/**
	 * Returns every declared section.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function sections(): array {
		return $this->sections;
	}

	/**
	 * Returns every declared field, optionally limited to one section.
	 *
	 * @param string|null $section Section id, or null for all fields.
	 * @return array<string, array<string, mixed>>
	 */
	public function fields( ?string $section = null ): array {
		if ( null === $section ) {
			return $this->fields;
		}

		return array_filter(
			$this->fields,
			static function ( array $field ) use ( $section ): bool {
				return $field['section'] === $section;
			}
		);
	}

	/**
	 * Returns a single field definition.
	 *
	 * @param string $id Field id.
	 * @return array<string, mixed>|null
	 */
	public function field( string $id ): ?array {
		return $this->fields[ $id ] ?? null;
	}

	/**
	 * Builds the default value map from the schema.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		$defaults = array();

		foreach ( $this->fields as $id => $field ) {
			$defaults[ $id ] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * Returns all settings with defaults applied for missing keys.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( $this->option_name, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->cache = array_merge( $this->defaults(), $stored );

		return $this->cache;
	}

	/**
	 * Returns one setting value.
	 *
	 * @param string $id       Field id.
	 * @param mixed  $fallback Value returned when the field is unknown.
	 * @return mixed
	 */
	public function get( string $id, $fallback = null ) {
		$all = $this->all();

		if ( array_key_exists( $id, $all ) ) {
			return $all[ $id ];
		}

		return $fallback;
	}

	/**
	 * Convenience boolean accessor.
	 *
	 * @param string $id Field id.
	 * @return bool
	 */
	public function is_enabled( string $id ): bool {
		return (bool) $this->get( $id, false );
	}

	/**
	 * Persists a partial set of values, merged over what is already stored.
	 *
	 * @param array<string, mixed> $values Raw values keyed by field id.
	 * @return bool
	 */
	public function save( array $values ): bool {
		$merged = array_merge( $this->all(), $this->sanitize( $values ) );
		$this->cache = null;

		return update_option( $this->option_name, $merged );
	}

	/**
	 * Sanitizes a raw input array against the schema.
	 *
	 * Unknown keys are discarded. Each field is sanitized by its declared callback
	 * when present, otherwise by a sanitizer chosen from its type.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$clean = array();

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		foreach ( $this->fields as $id => $field ) {
			// Unchecked toggles and empty multichecks are absent from the POST body.
			if ( ! array_key_exists( $id, $input ) ) {
				if ( 'toggle' === $field['type'] ) {
					$clean[ $id ] = false;
				} elseif ( 'multicheck' === $field['type'] ) {
					$clean[ $id ] = array();
				}

				continue;
			}

			$clean[ $id ] = $this->sanitize_field( $field, $input[ $id ] );
		}

		return $clean;
	}

	/**
	 * Sanitizes one value according to its field definition.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Raw value.
	 * @return mixed
	 */
	private function sanitize_field( array $field, $value ) {
		if ( is_callable( $field['sanitize'] ) ) {
			return call_user_func( $field['sanitize'], $value, $field );
		}

		switch ( $field['type'] ) {
			case 'toggle':
				return (bool) $value;

			case 'number':
				$number = is_numeric( $value ) ? $value + 0 : 0;

				if ( null !== $field['min'] ) {
					$number = max( $field['min'], $number );
				}

				if ( null !== $field['max'] ) {
					$number = min( $field['max'], $number );
				}

				return is_float( $number ) ? (float) $number : (int) $number;

			case 'email':
				return sanitize_email( (string) $value );

			case 'url':
				return esc_url_raw( trim( (string) $value ) );

			case 'color':
				$color = sanitize_hex_color( (string) $value );

				return null === $color ? (string) $field['default'] : $color;

			case 'password':
				// Passwords are stored verbatim after control characters are stripped;
				// callers are responsible for encrypting before persistence.
				return (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $value );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'code':
				// Free-form text kept intact apart from line ending normalisation.
				return (string) str_replace( "\r\n", "\n", (string) $value );

			case 'select':
			case 'radio':
				$value = sanitize_text_field( (string) $value );

				return array_key_exists( $value, $field['choices'] ) ? $value : (string) $field['default'];

			case 'multicheck':
				if ( ! is_array( $value ) ) {
					return array();
				}

				$allowed = array_keys( $field['choices'] );

				return array_values(
					array_intersect(
						array_map( 'sanitize_text_field', $value ),
						$allowed
					)
				);

			case 'list':
				// Newline separated list normalised to a unique, trimmed array.
				if ( is_array( $value ) ) {
					$lines = $value;
				} else {
					$lines = preg_split( '/\R/', (string) $value ) ?: array();
				}

				$lines = array_map( 'sanitize_text_field', $lines );
				$lines = array_filter( array_map( 'trim', $lines ), 'strlen' );

				return array_values( array_unique( $lines ) );

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
