<?php
/**
 * Findings list table.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Modules\Scanner\Repository;
use Sitecraft\Accessibility\Support\Settings;
use WP_List_Table;

// The core class is not loaded on every admin request, and this file is reached
// through the autoloader, so the parent has to be pulled in before the class
// statement below is executed.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The findings browser.
 *
 * Extending `WP_List_Table` buys the behaviour administrators already know -
 * screen options, sortable headers, bulk actions, search, keyboard-reachable row
 * actions - and, more usefully, the accessible markup core maintains. Hand-written
 * admin tables are where `scope` attributes and sort state go missing, which would
 * be a poor look on this plugin in particular.
 *
 * The class owns no SQL and no escaping: reads go through `Repository::find()` and
 * cell markup through `FindingPresenter`.
 */
final class IssuesTable extends WP_List_Table {

	/**
	 * Request variable carrying the ids a bulk or row action applies to.
	 *
	 * @var string
	 */
	public const ITEM_FIELD = 'finding';

	/**
	 * Nonce action guarding bulk and row actions.
	 *
	 * Matches the `bulk-{plural}` action `WP_List_Table::display()` emits, so the
	 * table's own nonce field and the per-row delete links verify identically.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'bulk-findings';

	/**
	 * Findings store.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Validated request state driving the current view.
	 *
	 * @var array<string, mixed>
	 */
	private array $query;

	/**
	 * Menu slug of the screen hosting the table.
	 *
	 * @var string
	 */
	private string $page_slug;

	/**
	 * Rule id => translated title.
	 *
	 * @var array<string, string>
	 */
	private array $rule_titles;

	/**
	 * Constructor.
	 *
	 * @param Repository           $repository Findings store.
	 * @param array<string, mixed> $query      Validated request state.
	 * @param string               $page_slug  Menu slug of the hosting screen.
	 */
	public function __construct( Repository $repository, array $query, string $page_slug ) {
		parent::__construct(
			array(
				'singular' => 'finding',
				'plural'   => 'findings',
				'ajax'     => false,
			)
		);

		$this->repository  = $repository;
		$this->query       = $query;
		$this->page_slug   = $page_slug;
		$this->rule_titles = Settings::rule_choices();
	}

	/**
	 * Declares the columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'          => '<input type="checkbox" />',
			'object'      => __( 'Content', 'sitecraft-accessibility' ),
			'rule'        => __( 'Rule', 'sitecraft-accessibility' ),
			'severity'    => __( 'Severity', 'sitecraft-accessibility' ),
			'context'     => __( 'Offending markup', 'sitecraft-accessibility' ),
			'detected_at' => __( 'Detected', 'sitecraft-accessibility' ),
		);
	}

	/**
	 * Columns the header may sort by.
	 *
	 * The second element tells core which direction the first click applies:
	 * findings are most useful worst-first and newest-first, so those two columns
	 * open descending while the identifier columns open ascending.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function get_sortable_columns(): array {
		return array(
			'object'      => array( 'object_id', false, __( 'Content', 'sitecraft-accessibility' ), __( 'Table ordered by content.', 'sitecraft-accessibility' ) ),
			'rule'        => array( 'rule_id', false, __( 'Rule', 'sitecraft-accessibility' ), __( 'Table ordered by rule.', 'sitecraft-accessibility' ) ),
			'severity'    => array( 'severity', true, __( 'Severity', 'sitecraft-accessibility' ), __( 'Table ordered by severity.', 'sitecraft-accessibility' ) ),
			'detected_at' => array( 'detected_at', true, __( 'Detected', 'sitecraft-accessibility' ), __( 'Table ordered by detection time.', 'sitecraft-accessibility' ), 'desc' ),
		);
	}

	/**
	 * Column the row actions attach to.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name(): string {
		return 'object';
	}

	/**
	 * Bulk actions offered above and below the table.
	 *
	 * Deleting a finding is not a fix, and the confirmation text says so; it is for
	 * clearing rows whose content has already been corrected outside a re-audit.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete findings', 'sitecraft-accessibility' ),
		);
	}

	/**
	 * Runs the query and hands the results to core.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page = $this->per_page();

		// Hidden columns come from the screen so anything a user switches off in Screen
		// Options stays off; passing an empty array here would quietly override them.
		$hidden = null !== $this->screen ? get_hidden_columns( $this->screen ) : array();

		$this->_column_headers = array(
			$this->get_columns(),
			$hidden,
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		);

		$result = $this->repository->find(
			array(
				'severity' => (string) $this->query['severity'],
				'rule'     => (string) $this->query['rule'],
				'search'   => (string) $this->query['search'],
				'orderby'  => (string) $this->query['orderby'],
				'order'    => (string) $this->query['order'],
				'per_page' => $per_page,
				'page'     => (int) $this->query['paged'],
			)
		);

		$this->items = $result['rows'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Rows per page, from the screen option with the filter as its default.
	 *
	 * @return int
	 */
	private function per_page(): int {
		/**
		 * Filters the default number of findings listed per page.
		 *
		 * A per-user screen option overrides this once the user sets one.
		 *
		 * @param int $per_page Row count.
		 */
		$default = (int) apply_filters( 'sitecraft_a11y_issues_per_page', 25 );

		$per_page = (int) $this->get_items_per_page( IssuesScreen::PER_PAGE_OPTION, max( 1, $default ) );

		return max( 1, min( 500, $per_page ) );
	}

	/**
	 * Message shown when nothing matches.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No findings match these filters. An empty list means the automated rules found nothing here, not that this content is conformant.', 'sitecraft-accessibility' );
	}

	/**
	 * Renders the severity and rule filters.
	 *
	 * @param string $which Either `top` or `bottom`.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions">';

		$this->render_filter(
			'severity',
			__( 'Filter by severity', 'sitecraft-accessibility' ),
			__( 'All severities', 'sitecraft-accessibility' ),
			Settings::severity_choices(),
			(string) $this->query['severity']
		);

		$this->render_filter(
			'rule',
			__( 'Filter by rule', 'sitecraft-accessibility' ),
			__( 'All rules', 'sitecraft-accessibility' ),
			$this->rule_titles,
			(string) $this->query['rule']
		);

		submit_button( __( 'Filter', 'sitecraft-accessibility' ), '', 'filter_action', false );

		if ( $this->has_filters() ) {
			printf(
				'<a class="button button-link" href="%1$s">%2$s</a>',
				esc_url( add_query_arg( array( 'page' => $this->page_slug ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Reset filters', 'sitecraft-accessibility' )
			);
		}

		echo '</div>';
	}

	/**
	 * Renders one labelled filter dropdown.
	 *
	 * The label is visually hidden rather than absent: an unlabelled select is a
	 * 3.3.2 failure, and shipping one on this screen would be its own punchline.
	 *
	 * @param string                $name     Request variable name.
	 * @param string                $label    Accessible label.
	 * @param string                $any      Label for the unfiltered option.
	 * @param array<string, string> $choices  Value => label.
	 * @param string                $selected Currently selected value.
	 * @return void
	 */
	private function render_filter( string $name, string $label, string $any, array $choices, string $selected ): void {
		$id = 'sc-filter-' . $name;

		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label><select id="%1$s" name="%3$s">',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name )
		);

		printf( '<option value="">%s</option>', esc_html( $any ) );

		foreach ( $choices as $value => $choice_label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( $selected, (string) $value, false ),
				esc_html( $choice_label )
			);
		}

		echo '</select>';
	}

	/**
	 * Whether the current view is narrowed by anything.
	 *
	 * @return bool
	 */
	private function has_filters(): bool {
		return '' !== (string) $this->query['severity']
			|| '' !== (string) $this->query['rule']
			|| '' !== (string) $this->query['search'];
	}

	/**
	 * Row selection checkbox.
	 *
	 * @param array<string, mixed> $item Result row.
	 * @return string
	 */
	public function column_cb( $item ): string {
		$id = (int) ( $item['id'] ?? 0 );

		return sprintf(
			'<label class="screen-reader-text" for="sc-finding-%1$d">%2$s</label><input type="checkbox" id="sc-finding-%1$d" name="%3$s[]" value="%1$d" />',
			$id,
			esc_html(
				sprintf(
					/* translators: %d: finding id */
					__( 'Select finding %d', 'sitecraft-accessibility' ),
					$id
				)
			),
			esc_attr( self::ITEM_FIELD )
		);
	}

	/**
	 * Content column: the item the finding belongs to, plus its row actions.
	 *
	 * @param array<string, mixed> $item Result row.
	 * @return string
	 */
	public function column_object( $item ): string {
		$finding = $this->presenter( $item );

		return $finding->name_cell()
			. $finding->type_cell()
			. $this->row_actions( $this->actions_for( $finding ) );
	}

	/**
	 * Row actions for one finding.
	 *
	 * @param FindingPresenter $finding Presenter for the row.
	 * @return array<string, string>
	 */
	private function actions_for( FindingPresenter $finding ): array {
		$actions  = array();
		$edit_url = $finding->edit_url();
		$view_url = $finding->view_url();

		if ( '' !== $edit_url ) {
			$actions['edit'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit post', 'sitecraft-accessibility' )
			);
		}

		if ( '' !== $view_url ) {
			$actions['view'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $view_url ),
				esc_html__( 'View', 'sitecraft-accessibility' )
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%1$s" class="submitdelete" data-sc-confirm="%2$s">%3$s</a>',
			esc_url( $this->delete_url( $finding->id() ) ),
			esc_attr__( 'Deleting a finding removes the record, not the barrier. The next audit will report it again if the content still fails. Continue?', 'sitecraft-accessibility' ),
			esc_html__( 'Delete finding', 'sitecraft-accessibility' )
		);

		return $actions;
	}

	/**
	 * Nonce-signed URL that deletes one finding and returns to this view.
	 *
	 * The current filters and page travel with it so a delete does not drop the user
	 * back onto the unfiltered first page of a thousand-row list.
	 *
	 * @param int $id Finding id.
	 * @return string
	 */
	private function delete_url( int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'           => $this->page_slug,
					'action'         => 'delete',
					self::ITEM_FIELD => array( $id ),
					'severity'       => (string) $this->query['severity'],
					'rule'           => (string) $this->query['rule'],
					's'              => (string) $this->query['search'],
					'orderby'        => (string) $this->query['orderby'],
					'order'          => strtolower( (string) $this->query['order'] ),
					'paged'          => (int) $this->query['paged'],
				),
				admin_url( 'admin.php' )
			),
			self::NONCE_ACTION
		);
	}

	/**
	 * Rule column.
	 *
	 * @param array<string, mixed> $item Result row.
	 * @return string
	 */
	public function column_rule( $item ): string {
		$finding = $this->presenter( $item );

		$url = add_query_arg(
			array(
				'page' => $this->page_slug,
				'rule' => $finding->rule_id(),
			),
			admin_url( 'admin.php' )
		);

		return $finding->rule_cell( $url );
	}

	/**
	 * Severity column.
	 *
	 * @param array<string, mixed> $item Result row.
	 * @return string
	 */
	public function column_severity( $item ): string {
		return $this->presenter( $item )->severity_cell();
	}

	/**
	 * Markup column.
	 *
	 * @param array<string, mixed> $item Result row.
	 * @return string
	 */
	public function column_context( $item ): string {
		return $this->presenter( $item )->markup_cell();
	}

	/**
	 * Detection time column.
	 *
	 * @param array<string, mixed> $item Result row.
	 * @return string
	 */
	public function column_detected_at( $item ): string {
		return $this->presenter( $item )->detected_cell();
	}

	/**
	 * Fallback renderer for any column without a dedicated method.
	 *
	 * @param array<string, mixed> $item        Result row.
	 * @param string               $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * Wraps a result row in its presenter.
	 *
	 * @param array<string, mixed> $item Result row.
	 * @return FindingPresenter
	 */
	private function presenter( array $item ): FindingPresenter {
		return new FindingPresenter( $item, $this->rule_titles );
	}
}
