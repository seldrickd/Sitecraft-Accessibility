<?php
/**
 * Findings browser screen.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Core\Admin\Notices;
use Sitecraft\Accessibility\Core\Admin\Screen;
use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Modules\Scanner\Repository;
use Sitecraft\Accessibility\Support\Settings;

/**
 * Hosts the findings list table.
 *
 * The screen owns three things and nothing else: the request state, the delete
 * handler and the form the table is drawn inside. Querying belongs to the
 * repository and rendering belongs to `IssuesTable`, which keeps the security
 * surface of this file down to one write path that can be read in one screenful.
 *
 * Every request parameter is validated against an allowlist before it is used,
 * including the sort key, which is checked against the repository's own list
 * rather than against a copy that could drift from it.
 */
final class IssuesScreen extends Screen {

	/**
	 * User meta key holding the per-user rows-per-page preference.
	 *
	 * Also listed in `uninstall.php`; the two must stay in step.
	 *
	 * @var string
	 */
	public const PER_PAGE_OPTION = 'sitecraft_a11y_issues_per_page';

	/**
	 * Findings store.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Registry        $settings   Settings schema.
	 * @param Repository|null $repository Findings store.
	 */
	public function __construct( Registry $settings, ?Repository $repository = null ) {
		parent::__construct( $settings );

		$this->capability = Settings::capability();
		$this->repository = $repository instanceof Repository ? $repository : new Repository();
	}

	/**
	 * Menu slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'sitecraft-accessibility-issues';
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Accessibility findings', 'sitecraft-accessibility' );
	}

	/**
	 * This screen is a report, not a form.
	 *
	 * @return array<string, string>
	 */
	protected function tabs(): array {
		return array();
	}

	/**
	 * Runs on this screen's `load-` hook, once WordPress has a current screen.
	 *
	 * Declaring the per-page control here rather than in the table is deliberate:
	 * `add_screen_option()` has to run before the screen renders, and the table is
	 * not built until it does.
	 *
	 * @return void
	 */
	public function on_load(): void {
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Findings per page', 'sitecraft-accessibility' ),
				'default' => 25,
				'option'  => self::PER_PAGE_OPTION,
			)
		);
	}

	/**
	 * Handles the bulk and per-row delete actions.
	 *
	 * Capability first, then nonce, then the write, then a redirect that strips the
	 * action out of the URL so a refresh cannot replay it. The base class only knows
	 * how to save settings forms, so this path is written out in full rather than
	 * inherited.
	 *
	 * @return void
	 */
	public function maybe_save(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Routing only; the nonce is verified below, before anything is written.
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';

		if ( $page !== $this->slug() ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( 'delete' !== $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}

		if ( 'delete' !== $action ) {
			return;
		}

		$raw_ids = isset( $_REQUEST[ IssuesTable::ITEM_FIELD ] ) ? wp_unslash( $_REQUEST[ IssuesTable::ITEM_FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to positive integers below.
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! current_user_can( $this->capability ) ) {
			wp_die(
				esc_html__( 'You do not have permission to delete accessibility findings.', 'sitecraft-accessibility' ),
				403
			);
		}

		check_admin_referer( IssuesTable::NONCE_ACTION );

		$ids = array_values( array_filter( array_map( 'absint', (array) $raw_ids ) ) );

		if ( empty( $ids ) ) {
			Notices::add(
				__( 'No findings were selected, so nothing was deleted.', 'sitecraft-accessibility' ),
				'warning'
			);

			wp_safe_redirect( $this->redirect_url() );
			exit;
		}

		$removed = $this->repository->delete_ids( $ids );

		Notices::add(
			sprintf(
				/* translators: %s: number of findings deleted */
				_n(
					'%s finding deleted. Deleting the record does not fix the barrier; the next audit will report it again if the content still fails.',
					'%s findings deleted. Deleting the records does not fix the barriers; the next audit will report them again if the content still fails.',
					$removed,
					'sitecraft-accessibility'
				),
				number_format_i18n( $removed )
			),
			'success'
		);

		wp_safe_redirect( $this->redirect_url() );
		exit;
	}

	/**
	 * URL to return to after a delete, keeping the view the user was looking at.
	 *
	 * @return string
	 */
	protected function redirect_url(): string {
		$query = $this->request();

		$args = array(
			'page'     => $this->slug(),
			'severity' => (string) $query['severity'],
			'rule'     => (string) $query['rule'],
			's'        => (string) $query['search'],
			'orderby'  => (string) $query['orderby'],
			'order'    => strtolower( (string) $query['order'] ),
			'paged'    => (int) $query['paged'],
		);

		$args = array_filter(
			$args,
			static function ( $value ): bool {
				return '' !== (string) $value;
			}
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Renders the search form and the table.
	 *
	 * @return void
	 */
	protected function render_body(): void {
		$query = $this->request();
		$table = new IssuesTable( $this->repository, $query, $this->slug() );

		$table->prepare_items();

		printf(
			'<form method="get" action="%s">',
			esc_url( admin_url( 'admin.php' ) )
		);

		// The current sort is not repeated here: `WP_List_Table::search_box()` already
		// emits hidden orderby and order fields when the request carries them, and a
		// second pair would double the parameters on every filter submission.
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( $this->slug() ) );

		$table->search_box( __( 'Search findings', 'sitecraft-accessibility' ), 'sitecraft-a11y-findings' );
		$table->display();

		echo '</form>';

		$this->render_footnote();
	}

	/**
	 * Renders the standing caveat under the table.
	 *
	 * It is repeated on every screen that shows a count because a low number is the
	 * easiest thing in this plugin to misread as a conformance claim.
	 *
	 * @return void
	 */
	private function render_footnote(): void {
		printf(
			'<p class="sc-card-meta">%s</p>',
			esc_html__( 'Automated rules cover a minority of WCAG. They cannot tell you whether alt text is accurate, whether a keyboard user can finish a task, or whether a video is captioned. Treat this list as the floor, not the ceiling.', 'sitecraft-accessibility' )
		);
	}

	/**
	 * Reads and validates the request parameters driving the list.
	 *
	 * @return array<string, mixed>
	 */
	private function request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list state; the one write path on this screen verifies its own nonce.
		$severity = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '';
		$rule     = isset( $_GET['rule'] ) ? sanitize_text_field( wp_unslash( $_GET['rule'] ) ) : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'detected_at';
		$order    = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC';
		$paged    = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'severity' => array_key_exists( $severity, Settings::severity_choices() ) ? $severity : '',
			'rule'     => array_key_exists( $rule, Settings::rule_choices() ) ? $rule : '',
			'search'   => $search,
			'orderby'  => in_array( $orderby, Repository::sort_keys(), true ) ? $orderby : 'detected_at',
			'order'    => 'ASC' === $order ? 'ASC' : 'DESC',
			'paged'    => max( 1, $paged ),
		);
	}
}
