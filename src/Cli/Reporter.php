<?php
/**
 * Report assembly for the WP-CLI commands.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Modules\Scanner\Repository;
use Sitecraft\Accessibility\Support\Settings;

/**
 * Validates report arguments and turns stored rows into printable records.
 *
 * Kept apart from the command class so the commands stay a readable list of what
 * the plugin exposes, and so the awkward parts - argument validation and the
 * chunked read - can be reasoned about without scrolling past three docblocks of
 * WP-CLI synopsis.
 */
final class Reporter {

	/**
	 * Rows fetched per query while paging through a report.
	 *
	 * @var int
	 */
	private const CHUNK = 200;

	/**
	 * Columns a report prints when `--fields` is not given.
	 *
	 * @var string[]
	 */
	public const DEFAULT_FIELDS = array(
		'id',
		'severity',
		'rule_id',
		'criterion',
		'level',
		'object_id',
		'title',
		'selector_hint',
		'detected_at',
	);

	/**
	 * Every column a report can print.
	 *
	 * @var string[]
	 */
	public const AVAILABLE_FIELDS = array(
		'id',
		'severity',
		'rule_id',
		'criterion',
		'level',
		'object_id',
		'object_type',
		'title',
		'post_type',
		'edit_url',
		'selector_hint',
		'context',
		'detected_at',
	);

	/**
	 * Output formats the report accepts.
	 *
	 * @var string[]
	 */
	public const FORMATS = array( 'table', 'json', 'csv', 'yaml', 'count' );

	/**
	 * Findings store.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Findings store.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Validates a requested output format.
	 *
	 * @param string $format Requested format.
	 * @return string|null Error message, or null when the format is usable.
	 */
	public static function check_format( string $format ): ?string {
		if ( in_array( $format, self::FORMATS, true ) ) {
			return null;
		}

		return sprintf( 'Unsupported format "%s". Use one of: %s.', $format, implode( ', ', self::FORMATS ) );
	}

	/**
	 * Validates a requested severity.
	 *
	 * @param string $severity Requested severity, or an empty string.
	 * @return string|null Error message, or null when the value is usable.
	 */
	public static function check_severity( string $severity ): ?string {
		if ( '' === $severity || array_key_exists( $severity, Settings::severity_choices() ) ) {
			return null;
		}

		return sprintf(
			'Unknown severity "%s". Use one of: %s.',
			$severity,
			implode( ', ', array_keys( Settings::severity_choices() ) )
		);
	}

	/**
	 * Validates a requested rule id.
	 *
	 * @param string $rule Requested rule id, or an empty string.
	 * @return string|null Error message, or null when the value is usable.
	 */
	public static function check_rule( string $rule ): ?string {
		if ( '' === $rule || array_key_exists( $rule, Settings::rule_choices() ) ) {
			return null;
		}

		return sprintf(
			'Unknown rule "%s". Use one of: %s.',
			$rule,
			implode( ', ', array_keys( Settings::rule_choices() ) )
		);
	}

	/**
	 * Turns `--fields=` into a validated column list.
	 *
	 * @param string $fields Comma separated column names.
	 * @return array{fields: string[], error: string|null}
	 */
	public static function parse_fields( string $fields ): array {
		if ( '' === trim( $fields ) ) {
			return array(
				'fields' => self::DEFAULT_FIELDS,
				'error'  => null,
			);
		}

		$requested = array_values( array_filter( array_map( 'trim', explode( ',', $fields ) ) ) );
		$unknown   = array_diff( $requested, self::AVAILABLE_FIELDS );

		if ( ! empty( $unknown ) ) {
			return array(
				'fields' => self::DEFAULT_FIELDS,
				'error'  => sprintf(
					'Unknown field(s): %s. Available: %s.',
					implode( ', ', $unknown ),
					implode( ', ', self::AVAILABLE_FIELDS )
				),
			);
		}

		return array(
			'fields' => $requested,
			'error'  => null,
		);
	}

	/**
	 * Pages through the store until the limit is reached.
	 *
	 * Chunked rather than fetched in one statement so `--limit=0` on a site with a
	 * six-figure backlog does not try to hydrate the whole table into memory before
	 * printing the first row. The offset is explicit because the final chunk of a
	 * capped run is smaller than the others, and a page number would then point at
	 * the wrong row.
	 *
	 * @param array<string, mixed> $filters Repository filters.
	 * @param int                  $limit   Maximum rows, or 0 for every row.
	 * @return array<int, array<string, mixed>>
	 */
	public function collect( array $filters, int $limit ): array {
		$items  = array();
		$offset = 0;

		while ( true ) {
			$per_page = $limit > 0 ? min( self::CHUNK, $limit - count( $items ) ) : self::CHUNK;

			if ( $per_page < 1 ) {
				break;
			}

			$result = $this->repository->find(
				array_merge(
					$filters,
					array(
						// Worst first: a report read top-down should open on what blocks
						// someone outright rather than on whatever was recorded last.
						'orderby'  => 'severity',
						'order'    => 'ASC',
						'per_page' => $per_page,
						'offset'   => $offset,
					)
				)
			);

			foreach ( $result['rows'] as $row ) {
				$items[] = $this->present( $row );
			}

			if ( count( $result['rows'] ) < $per_page ) {
				break;
			}

			$offset += $per_page;
		}

		return $items;
	}

	/**
	 * Flattens one stored row into the shape the formatter prints.
	 *
	 * Whitespace is folded out of the markup snippet because a multi-line cell
	 * breaks both the table renderer and any downstream `grep`.
	 *
	 * @param array<string, mixed> $row Stored row.
	 * @return array<string, mixed>
	 */
	private function present( array $row ): array {
		$object_id = (int) ( $row['object_id'] ?? 0 );
		$edit_url  = get_edit_post_link( $object_id, 'raw' );
		$post_type = get_post_type( $object_id );
		$context   = (string) preg_replace( '/\s+/', ' ', (string) ( $row['context'] ?? '' ) );

		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'severity'      => (string) ( $row['severity'] ?? '' ),
			'rule_id'       => (string) ( $row['rule_id'] ?? '' ),
			'criterion'     => (string) ( $row['criterion'] ?? '' ),
			'level'         => (string) ( $row['level'] ?? '' ),
			'object_id'     => $object_id,
			'object_type'   => (string) ( $row['object_type'] ?? '' ),
			'title'         => (string) get_the_title( $object_id ),
			'post_type'     => is_string( $post_type ) ? $post_type : '',
			'edit_url'      => is_string( $edit_url ) ? $edit_url : '',
			'selector_hint' => (string) ( $row['selector_hint'] ?? '' ),
			'context'       => trim( $context ),
			'detected_at'   => (string) ( $row['detected_at'] ?? '' ),
		);
	}
}
