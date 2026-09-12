<?php
/**
 * Display formatting for one stored finding.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Support\Settings;

/**
 * Turns one row of the issues table into the strings and markup a screen shows.
 *
 * Presentation is separated from the list table for two reasons. The table stays
 * short enough to audit in one sitting, and - more to the point - every value that
 * reaches the page is escaped here, in one file, so "is this output escaped?" is a
 * question with a single place to look rather than one per column.
 */
final class FindingPresenter {

	/**
	 * Stored row.
	 *
	 * @var array<string, mixed>
	 */
	private array $row;

	/**
	 * Rule id => translated title.
	 *
	 * @var array<string, string>
	 */
	private array $rule_titles;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>       $row         Stored row.
	 * @param array<string, string>|null $rule_titles Rule id => title; read from the settings when omitted.
	 */
	public function __construct( array $row, ?array $rule_titles = null ) {
		$this->row         = $row;
		$this->rule_titles = null === $rule_titles ? Settings::rule_choices() : $rule_titles;
	}

	/**
	 * Primary key of the finding.
	 *
	 * @return int
	 */
	public function id(): int {
		return (int) ( $this->row['id'] ?? 0 );
	}

	/**
	 * Id of the audited object.
	 *
	 * @return int
	 */
	public function object_id(): int {
		return (int) ( $this->row['object_id'] ?? 0 );
	}

	/**
	 * Rule that produced the finding.
	 *
	 * @return string
	 */
	public function rule_id(): string {
		return (string) ( $this->row['rule_id'] ?? '' );
	}

	/**
	 * Title of the audited object, falling back to its id.
	 *
	 * @return string
	 */
	public function title(): string {
		$title = (string) get_the_title( $this->object_id() );

		if ( '' !== trim( $title ) ) {
			return $title;
		}

		return sprintf(
			/* translators: %d: object ID */
			__(
				'Item #%d',
				'sitecraft-accessibility'
			),
			$this->object_id()
		);
	}

	/**
	 * Editor URL, or an empty string when the object is gone or not editable.
	 *
	 * @return string
	 */
	public function edit_url(): string {
		$url = get_edit_post_link( $this->object_id() );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Public URL, or an empty string when the object has none.
	 *
	 * @return string
	 */
	public function view_url(): string {
		$url = get_permalink( $this->object_id() );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Singular label of the audited object's post type.
	 *
	 * Falls back to the stored object type, which is all that survives when the post
	 * itself has been deleted before the findings were pruned.
	 *
	 * @return string
	 */
	public function type_label(): string {
		$post_type = get_post_type( $this->object_id() );

		if ( is_string( $post_type ) && '' !== $post_type ) {
			$object = get_post_type_object( $post_type );

			return null === $object ? $post_type : (string) $object->labels->singular_name;
		}

		$stored = (string) ( $this->row['object_type'] ?? '' );

		return '' === $stored ? __( 'Unknown content', 'sitecraft-accessibility' ) : $stored;
	}

	/**
	 * The object's name, linked to the editor when the user can open it.
	 *
	 * @return string Escaped markup.
	 */
	public function name_cell(): string {
		$edit_url = $this->edit_url();

		if ( '' !== $edit_url ) {
			return sprintf(
				'<strong><a href="%1$s">%2$s</a></strong>',
				esc_url( $edit_url ),
				esc_html( $this->title() )
			);
		}

		return sprintf( '<strong>%s</strong>', esc_html( $this->title() ) );
	}

	/**
	 * The post type line under the object's name.
	 *
	 * @return string Escaped markup.
	 */
	public function type_cell(): string {
		return sprintf( '<div class="sc-card-meta">%s</div>', esc_html( $this->type_label() ) );
	}

	/**
	 * The rule, its id and the criterion it cites.
	 *
	 * @param string $filter_url URL that filters the list to this rule.
	 * @return string Escaped markup.
	 */
	public function rule_cell( string $filter_url ): string {
		$rule_id = $this->rule_id();

		return sprintf(
			'<a href="%1$s">%2$s</a><br /><code class="sc-code sc-code--inline">%3$s</code> <span class="sc-badge sc-badge--info">%4$s</span>',
			esc_url( $filter_url ),
			esc_html( $this->rule_titles[ $rule_id ] ?? $rule_id ),
			esc_html( $rule_id ),
			esc_html( $this->criterion() )
		);
	}

	/**
	 * Criterion and conformance level as one string, for example `1.1.1 A`.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return trim( (string) ( $this->row['criterion'] ?? '' ) . ' ' . (string) ( $this->row['level'] ?? '' ) );
	}

	/**
	 * Severity as a labelled badge.
	 *
	 * @return string Escaped markup.
	 */
	public function severity_cell(): string {
		$severity = (string) ( $this->row['severity'] ?? '' );
		$labels   = Settings::severity_choices();

		return sprintf(
			'<span class="sc-badge sc-badge--%1$s">%2$s</span>',
			esc_attr( $severity ),
			esc_html( $labels[ $severity ] ?? $severity )
		);
	}

	/**
	 * The offending markup inside a native disclosure.
	 *
	 * `<details>` rather than a scripted accordion: it is keyboard operable and
	 * announced as expandable before a line of JavaScript has run, which is the bar
	 * this plugin has to hold itself to.
	 *
	 * @return string Escaped markup.
	 */
	public function markup_cell(): string {
		$context = trim( (string) ( $this->row['context'] ?? '' ) );
		$hint    = trim( (string) ( $this->row['selector_hint'] ?? '' ) );

		if ( '' === $context && '' === $hint ) {
			return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">'
				. esc_html__( 'No markup recorded.', 'sitecraft-accessibility' )
				. '</span>';
		}

		$body = '';

		if ( '' !== $context ) {
			$body .= sprintf( '<pre class="sc-code">%s</pre>', esc_html( $context ) );
		}

		if ( '' !== $hint ) {
			$body .= sprintf(
				'<p class="sc-card-meta"><code class="sc-code sc-code--inline">%s</code></p>',
				esc_html( $hint )
			);
		}

		return sprintf(
			'<details class="sc-details"><summary>%1$s</summary>%2$s</details>',
			esc_html__( 'Show markup', 'sitecraft-accessibility' ),
			$body
		);
	}

	/**
	 * Detection time in the site's own date and time format.
	 *
	 * @return string Escaped text.
	 */
	public function detected_cell(): string {
		$stored = (string) ( $this->row['detected_at'] ?? '' );

		if ( '' === $stored ) {
			return esc_html__( 'Unknown', 'sitecraft-accessibility' );
		}

		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		return esc_html( (string) mysql2date( $format, $stored ) );
	}
}
