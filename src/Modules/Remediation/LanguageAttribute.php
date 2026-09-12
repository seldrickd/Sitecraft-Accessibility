<?php
/**
 * Fix: document language.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Remediation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCAG 3.1.1 — a correct `lang` attribute on the html element.
 *
 * The attribute decides which voice and pronunciation rules a screen reader uses.
 * A French page announced with an English synthesiser is not merely awkward: it is
 * frequently unintelligible, which is why this is a Level A criterion rather than
 * a nicety.
 */
final class LanguageAttribute extends AbstractFix {

	/**
	 * Post meta key holding a per-post language override.
	 *
	 * @var string
	 */
	public const META_KEY = '_sitecraft_a11y_lang';

	/**
	 * Fix id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'lang-attribute';
	}

	/**
	 * Whether the fix runs.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->settings->is_enabled( 'lang_attribute_enabled' );
	}

	/**
	 * Binds the fix.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'language_attributes', array( $this, 'filter_attributes' ), 20, 2 );

		if ( $this->settings->is_enabled( 'lang_per_post_enabled' ) ) {
			add_action( 'init', array( $this, 'register_meta' ), 20 );
		}
	}

	/**
	 * Exposes the override to the block editor.
	 *
	 * Registering the meta rather than adding a metabox means the editor sidebar can
	 * read and write it through the REST API with no extra endpoint, and the
	 * capability check lives with the data instead of with the form.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$post_types = array_map( 'strval', (array) $this->settings->get( 'scan_post_types', array( 'post', 'page' ) ) );

		foreach ( $post_types as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'description'       => __( 'BCP 47 language tag for this content, for example fr or pt-BR.', 'sitecraft-accessibility' ),
					'sanitize_callback' => array( $this, 'sanitize_tag' ),
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						unset( $allowed, $meta_key );

						return current_user_can( 'edit_post', (int) $post_id );
					},
				)
			);
		}
	}

	/**
	 * Sanitizes a language tag.
	 *
	 * BCP 47 allows a good deal more than this, but a tag outside
	 * `language[-Script][-REGION]` is almost always a typo, and an invalid tag is
	 * worse than none: it can switch a screen reader to the wrong voice entirely.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_tag( $value ): string {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( 1 !== preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z]{4})?(-([A-Za-z]{2}|\d{3}))?$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Ensures the html element carries a language.
	 *
	 * @param string $output Attribute string built by core.
	 * @param string $doctype Doctype the attributes are for.
	 * @return string
	 */
	public function filter_attributes( $output, $doctype = 'html' ): string {
		unset( $doctype );

		$output = (string) $output;
		$locale = $this->post_language();

		if ( '' !== $locale ) {
			// Replace rather than append: two lang attributes on one element is a parse
			// error, and browsers keep the first, which would be the site default.
			$stripped = (string) preg_replace( '/\blang=("|\')[^"\']*\1\s*/i', '', $output );

			return trim( 'lang="' . esc_attr( $locale ) . '" ' . trim( $stripped ) );
		}

		if ( 1 === preg_match( '/\blang=("|\')[^"\']+\1/i', $output ) ) {
			return $output;
		}

		$language = get_bloginfo( 'language' );

		if ( '' === $language ) {
			return $output;
		}

		return trim( $output . ' lang="' . esc_attr( $language ) . '"' );
	}

	/**
	 * Language override recorded on the post being viewed.
	 *
	 * @return string
	 */
	private function post_language(): string {
		if ( ! $this->settings->is_enabled( 'lang_per_post_enabled' ) || ! is_singular() ) {
			return '';
		}

		$post_id = get_queried_object_id();

		if ( $post_id < 1 ) {
			return '';
		}

		return $this->sanitize_tag( get_post_meta( $post_id, self::META_KEY, true ) );
	}
}
