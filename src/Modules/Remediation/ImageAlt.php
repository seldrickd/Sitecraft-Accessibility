<?php
/**
 * Fix: decorative images and missing alt text.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Remediation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Modules\Scanner\Issue;
use Sitecraft\Accessibility\Modules\Scanner\Repository;
use WP_Post;

/**
 * WCAG 1.1.1 — records images shipped without a text alternative, and marks the
 * ones a human has declared decorative.
 *
 * This fix never writes alt text. Generated descriptions read plausibly and are
 * routinely wrong, and a confidently wrong description is a worse barrier than a
 * missing one: a listener has no way to tell that what they were told about the
 * image is not what the image shows. The only alt text this plugin sets is the
 * empty string, and only where a person has said the image carries no information.
 */
final class ImageAlt extends AbstractFix {

	/**
	 * Post meta flagging an attachment as decorative.
	 *
	 * @var string
	 */
	public const META_KEY = '_sitecraft_a11y_decorative';

	/**
	 * Transient prefix used to throttle repeat logging of the same attachment.
	 *
	 * @var string
	 */
	private const THROTTLE_PREFIX = 'sitecraft_a11y_alt_';

	/**
	 * Findings store.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Registry   $settings   Settings schema.
	 * @param Stylesheet $stylesheet Shared inline stylesheet.
	 * @param Repository $repository Findings store.
	 */
	public function __construct( Registry $settings, Stylesheet $stylesheet, Repository $repository ) {
		parent::__construct( $settings, $stylesheet );

		$this->repository = $repository;
	}

	/**
	 * Fix id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'image-alt';
	}

	/**
	 * Whether the fix runs.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->settings->is_enabled( 'image_alt_audit_enabled' )
			|| $this->settings->is_enabled( 'decorative_alt_enabled' );
	}

	/**
	 * Binds the fix.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_attributes' ), 20, 2 );

		if ( ! $this->settings->is_enabled( 'decorative_alt_enabled' ) ) {
			return;
		}

		add_filter( 'attachment_fields_to_edit', array( $this, 'add_media_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_media_field' ), 10, 2 );

		// Filling in alt text resolves the finding; leaving the row behind would make
		// the report argue with the media library.
		add_action( 'updated_post_meta', array( $this, 'forget_on_alt_change' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'forget_on_alt_change' ), 10, 3 );
	}

	/**
	 * Applies the decorative marker and records images with no alternative.
	 *
	 * @param array<string, string> $attributes Image attributes.
	 * @param WP_Post|null          $attachment Attachment post.
	 * @return array<string, string>
	 */
	public function filter_attributes( $attributes, $attachment = null ): array {
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}

		if ( ! $attachment instanceof WP_Post ) {
			return $attributes;
		}

		$attachment_id = (int) $attachment->ID;

		if ( $this->settings->is_enabled( 'decorative_alt_enabled' ) && $this->is_decorative( $attachment_id ) ) {
			// An empty alt is the correct markup for an image that carries no meaning:
			// it tells assistive technology to skip the image rather than read its file
			// name. Role presentation reinforces that for the handful of screen readers
			// that still announce an image landmark regardless of the alt value.
			$attributes['alt']  = '';
			$attributes['role'] = 'presentation';

			return $attributes;
		}

		if ( $this->settings->is_enabled( 'image_alt_audit_enabled' ) && '' === trim( (string) ( $attributes['alt'] ?? '' ) ) ) {
			$this->record( $attachment_id, (string) ( $attributes['src'] ?? '' ) );
		}

		return $attributes;
	}

	/**
	 * Adds the decorative checkbox to the media modal and the attachment screen.
	 *
	 * @param array<string, mixed> $fields     Attachment fields.
	 * @param WP_Post|null         $attachment Attachment post.
	 * @return array<string, mixed>
	 */
	public function add_media_field( $fields, $attachment = null ): array {
		if ( ! is_array( $fields ) || ! $attachment instanceof WP_Post ) {
			return is_array( $fields ) ? $fields : array();
		}

		$fields['sitecraft_a11y_decorative'] = array(
			'label' => __( 'Decorative image', 'sitecraft-accessibility' ),
			'input' => 'html',
			'html'  => sprintf(
				'<label for="attachments-%1$d-sitecraft_a11y_decorative"><input type="checkbox" id="attachments-%1$d-sitecraft_a11y_decorative" name="attachments[%1$d][sitecraft_a11y_decorative]" value="1" %2$s /> %3$s</label>',
				(int) $attachment->ID,
				checked( $this->is_decorative( (int) $attachment->ID ), true, false ),
				esc_html__( 'This image carries no information of its own.', 'sitecraft-accessibility' )
			),
			'helps' => __( 'Ticking this sends the image to assistive technology with an empty alt attribute, so it is skipped instead of announced. Use it for dividers, background flourishes and icons that sit beside text saying the same thing.', 'sitecraft-accessibility' ),
		);

		return $fields;
	}

	/**
	 * Persists the decorative flag.
	 *
	 * Core verifies the nonce for both routes that reach this filter - the media
	 * modal's AJAX handler and the attachment edit form - before any field is passed
	 * here. The capability check is ours, because a nonce proves the request came from
	 * our form and never that the sender is allowed to edit this attachment.
	 *
	 * @param array<string, mixed> $post       Attachment post data.
	 * @param array<string, mixed> $attachment Submitted attachment fields.
	 * @return array<string, mixed>
	 */
	public function save_media_field( $post, $attachment ): array {
		if ( ! is_array( $post ) ) {
			return array();
		}

		$post_id = (int) ( $post['ID'] ?? 0 );

		if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
			return $post;
		}

		$decorative = is_array( $attachment ) && ! empty( $attachment['sitecraft_a11y_decorative'] );

		if ( $decorative ) {
			update_post_meta( $post_id, self::META_KEY, 1 );
			$this->repository->delete_for_object( $post_id, 'rendered' );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}

		return $post;
	}

	/**
	 * Clears a recorded finding once alt text is supplied.
	 *
	 * @param int    $meta_id  Meta row id.
	 * @param int    $post_id  Attachment id.
	 * @param string $meta_key Meta key that changed.
	 * @return void
	 */
	public function forget_on_alt_change( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );

		if ( '_wp_attachment_image_alt' !== $meta_key ) {
			return;
		}

		$this->repository->delete_for_object( (int) $post_id, 'rendered' );
		delete_transient( self::THROTTLE_PREFIX . (int) $post_id );
	}

	/**
	 * Whether an attachment has been marked decorative.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	public function is_decorative( int $attachment_id ): bool {
		return '' !== (string) get_post_meta( $attachment_id, self::META_KEY, true );
	}

	/**
	 * Records one attachment as rendered without a text alternative.
	 *
	 * Throttled per attachment: an image in a header appears on every page of the
	 * site, and writing a row each time would turn a reporting feature into a write
	 * amplifier.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $src           Rendered source URL.
	 * @return void
	 */
	private function record( int $attachment_id, string $src ): void {
		if ( $attachment_id < 1 || is_admin() ) {
			return;
		}

		$key = self::THROTTLE_PREFIX . $attachment_id;

		if ( false !== get_transient( $key ) ) {
			return;
		}

		set_transient( $key, time(), DAY_IN_SECONDS );

		$issue = new Issue(
			'img-alt-missing',
			__( 'Image with no alt attribute', 'sitecraft-accessibility' ),
			'1.1.1',
			'A',
			'critical',
			sprintf( '<img src="%s" alt="">', esc_url_raw( $src ) ),
			sprintf(
				/* translators: %d: attachment ID */
				__( 'Media library item #%d, rendered by the theme', 'sitecraft-accessibility' ),
				$attachment_id
			),
			__( 'Open this item in the media library and describe it in the "Alternative text" field, or tick "Decorative image" if it carries no information.', 'sitecraft-accessibility' )
		);

		$this->repository->replace_for_object( $attachment_id, 'rendered', array( $issue ) );
	}
}
