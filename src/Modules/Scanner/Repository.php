<?php
/**
 * Persistence for recorded findings.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Support\Installer;

/**
 * Every read and write against the issues table.
 *
 * One class owning the SQL is what makes the "prepare everything, interpolate only
 * the table name" rule checkable: a reviewer reads this file and knows the rest of
 * the plugin touches no database.
 */
final class Repository {

	/**
	 * Object types the store accepts.
	 *
	 * `post` is content audited from `post_content`; `rendered` is markup observed
	 * on the front end that no post content produced, such as theme output.
	 *
	 * @var string[]
	 */
	private const OBJECT_TYPES = array( 'post', 'rendered' );

	/**
	 * Severities the store will filter on.
	 *
	 * Declared here rather than read from the settings schema so a query never
	 * depends on the admin layer, and so an unrecognised value is dropped before it
	 * reaches the statement rather than after.
	 *
	 * @var string[]
	 */
	private const SEVERITIES = array( 'critical', 'serious', 'moderate', 'minor' );

	/**
	 * Sortable keys mapped to the SQL fragment that orders them.
	 *
	 * A map rather than a column allowlist because severity has to sort by urgency
	 * rather than alphabetically. Callers pass a key; nothing a caller sends is ever
	 * interpolated into the statement.
	 *
	 * @var array<string, string>
	 */
	private const ORDER_BY = array(
		'detected_at' => 'detected_at',
		'severity'    => "FIELD( severity, 'critical', 'serious', 'moderate', 'minor' )",
		'rule_id'     => 'rule_id',
		'object_id'   => 'object_id',
	);

	/**
	 * Replaces every finding recorded for one object.
	 *
	 * Delete-then-insert rather than a diff: a finding has no stable identity across
	 * runs once the markup around it moves, and a stale row is worse than a new id.
	 *
	 * @param int     $object_id   Object the findings belong to.
	 * @param string  $object_type Object type.
	 * @param Issue[] $issues      Findings to store.
	 * @return int Number of rows written.
	 */
	public function replace_for_object( int $object_id, string $object_type, array $issues ): int {
		global $wpdb;

		if ( $object_id < 1 ) {
			return 0;
		}

		$object_type = in_array( $object_type, self::OBJECT_TYPES, true ) ? $object_type : 'post';

		$this->delete_for_object( $object_id, $object_type );

		$table    = Installer::issues_table();
		$now      = current_time( 'mysql', true );
		$written  = 0;

		foreach ( $issues as $issue ) {
			if ( ! $issue instanceof Issue ) {
				continue;
			}

			$row = array_merge(
				$issue->to_row(),
				array(
					'object_id'   => $object_id,
					'object_type' => $object_type,
					'detected_at' => $now,
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert into the plugin's own table; $wpdb->insert prepares every value.
			$inserted = $wpdb->insert(
				$table,
				$row,
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);

			if ( false !== $inserted ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Removes every finding for one object.
	 *
	 * @param int         $object_id   Object id.
	 * @param string|null $object_type Optional type filter.
	 * @return int Rows removed.
	 */
	public function delete_for_object( int $object_id, ?string $object_type = null ): int {
		global $wpdb;

		if ( $object_id < 1 ) {
			return 0;
		}

		$where = array( 'object_id' => $object_id );

		if ( null !== $object_type && in_array( $object_type, self::OBJECT_TYPES, true ) ) {
			$where['object_type'] = $object_type;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete from the plugin's own table; $wpdb->delete prepares every value.
		$removed = $wpdb->delete( Installer::issues_table(), $where );

		return false === $removed ? 0 : (int) $removed;
	}

	/**
	 * Removes specific findings by primary key.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows removed.
	 */
	public function delete_ids( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = Installer::issues_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete from the plugin's own table.
		$removed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; placeholders generated from a count, never from input.
				"DELETE FROM {$table} WHERE id IN ({$placeholders})",
				$ids
			)
		);

		return false === $removed ? 0 : (int) $removed;
	}

	/**
	 * Total recorded findings.
	 *
	 * @return int
	 */
	public function total(): int {
		global $wpdb;

		$table = Installer::issues_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate over the plugin's own table; the only interpolation is $wpdb->prefix plus a class constant.
	}

	/**
	 * Number of distinct objects with at least one finding.
	 *
	 * @return int
	 */
	public function object_count(): int {
		global $wpdb;

		$table = Installer::issues_table();

		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT object_id) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate over the plugin's own table; the only interpolation is $wpdb->prefix plus a class constant.
	}

	/**
	 * Finding counts keyed by severity, with every severity present.
	 *
	 * @return array<string, int>
	 */
	public function severity_counts(): array {
		global $wpdb;

		$table  = Installer::issues_table();
		$counts = array(
			'critical' => 0,
			'serious'  => 0,
			'moderate' => 0,
			'minor'    => 0,
		);

		$rows = $wpdb->get_results( "SELECT severity, COUNT(*) AS total FROM {$table} GROUP BY severity", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate over the plugin's own table; the only interpolation is $wpdb->prefix plus a class constant.

		foreach ( (array) $rows as $row ) {
			$severity = (string) $row['severity'];

			// A severity retired from the code can still exist in stored rows.
			$counts[ $severity ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Finding counts grouped by rule, worst first.
	 *
	 * @param int $limit Maximum rules returned.
	 * @return array<int, array<string, mixed>>
	 */
	public function rule_counts( int $limit = 60 ): array {
		global $wpdb;

		$table = Installer::issues_table();
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate over the plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix and a class constant.
				"SELECT rule_id, criterion, level, severity, COUNT(*) AS total
				FROM {$table}
				GROUP BY rule_id, criterion, level, severity
				ORDER BY total DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Findings recorded for one object.
	 *
	 * @param int $object_id Object id.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_object( int $object_id ): array {
		global $wpdb;

		if ( $object_id < 1 ) {
			return array();
		}

		$table = Installer::issues_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read of the plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix and a class constant.
				"SELECT * FROM {$table} WHERE object_id = %d ORDER BY FIELD( severity, 'critical', 'serious', 'moderate', 'minor' ), id ASC",
				$object_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sort keys `find()` accepts.
	 *
	 * @return string[]
	 */
	public static function sort_keys(): array {
		return array_keys( self::ORDER_BY );
	}

	/**
	 * Filtered, sorted, paginated read used by the findings table and by WP-CLI.
	 *
	 * The two consumers had been building the same statement in two places, which is
	 * exactly how a sort allowlist ends up enforced in one of them and not the other.
	 * Every filter value here is bound; only the fragments from `self::ORDER_BY` and
	 * the locally assembled `WHERE` skeleton are interpolated.
	 *
	 * @param array<string, mixed> $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $severity  Severity key, or an empty string for all.
	 *     @type string $rule      Rule id, or an empty string for all.
	 *     @type string $search    Substring matched against markup, hint and rule id.
	 *     @type int    $object_id Restrict to one object.
	 *     @type string $orderby   Key from `sort_keys()`.
	 *     @type string $order     `ASC` or `DESC`.
	 *     @type int    $per_page  Rows per page, 1-500.
	 *     @type int    $page      1-based page number.
	 *     @type int    $offset    Explicit row offset; overrides `page` when not null.
	 * }
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function find( array $args = array() ): array {
		global $wpdb;

		$args = array_merge(
			array(
				'severity'  => '',
				'rule'      => '',
				'search'    => '',
				'object_id' => 0,
				'orderby'   => 'detected_at',
				'order'     => 'DESC',
				'per_page'  => 25,
				'page'      => 1,
				'offset'    => null,
			),
			$args
		);

		$table  = Installer::issues_table();
		$where  = array( '1 = 1' );
		$params = array();

		$severity = (string) $args['severity'];

		if ( in_array( $severity, self::SEVERITIES, true ) ) {
			$where[]  = 'severity = %s';
			$params[] = $severity;
		}

		if ( '' !== (string) $args['rule'] ) {
			$where[]  = 'rule_id = %s';
			$params[] = (string) $args['rule'];
		}

		if ( (int) $args['object_id'] > 0 ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $args['object_id'];
		}

		if ( '' !== (string) $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '( context LIKE %s OR selector_hint LIKE %s OR rule_id LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$clause   = implode( ' AND ', $where );
		$orderby  = self::ORDER_BY[ (string) $args['orderby'] ] ?? self::ORDER_BY['detected_at'];
		$order    = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );

		// A caller streaming results in chunks cannot always use a page number: the last
		// chunk of a capped run is smaller than the others, and page * size would then
		// point at the wrong row. An explicit offset says exactly where to resume.
		$offset = null === $args['offset']
			? ( max( 1, (int) $args['page'] ) - 1 ) * $per_page
			: max( 0, (int) $args['offset'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paginated read of the plugin's own table; a cached page would go stale on the next audit batch.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; $clause is built from literals above and $orderby comes from self::ORDER_BY. Every value is bound.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( empty( $params )
			? $wpdb->get_var( $count_sql )
			: $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}
}
