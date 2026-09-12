<?php
/**
 * Accessibility dashboard screen.
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
use Sitecraft\Accessibility\Support\Installer;
use Sitecraft\Accessibility\Support\Settings;

/**
 * Landing screen: how bad is it, what is worst, and what do I do next.
 *
 * Every figure comes from the repository rather than from SQL written here, so
 * the statement that this plugin touches the database in exactly one class stays
 * true and stays checkable. Nothing is cached: the numbers change on every cron
 * batch, and a stale headline count on an audit dashboard is worse than the few
 * milliseconds three aggregates cost.
 */
final class DashboardScreen extends Screen {

	/**
	 * Name of the hidden field carrying the requested action.
	 *
	 * @var string
	 */
	private const ACTION_FIELD = 'sitecraft_a11y_dashboard_action';

	/**
	 * Nonce action and field name for dashboard actions.
	 *
	 * @var string
	 */
	private const ACTION_NONCE = 'sitecraft_a11y_dashboard';

	/**
	 * Maximum rules listed in the breakdown table.
	 *
	 * The rule set is fifteen entries plus whatever add-ons register, so this is a
	 * guard against a pathological third-party registration rather than a page size
	 * anyone is expected to hit.
	 *
	 * @var int
	 */
	private const RULE_ROWS = 60;

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
		return 'sitecraft-accessibility';
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Accessibility', 'sitecraft-accessibility' );
	}

	/**
	 * This screen has no tabs; its settings live on the settings screen.
	 *
	 * @return array<string, string>
	 */
	protected function tabs(): array {
		return array();
	}

	/**
	 * Handles the "audit now" and "clear findings" buttons.
	 *
	 * The base implementation only understands settings forms, so the guard rails
	 * (capability first, then nonce, then a redirect) are reproduced deliberately
	 * rather than inherited.
	 *
	 * @return void
	 */
	public function maybe_save(): void {
		if ( ! isset( $_POST[ self::ACTION_FIELD ] ) ) {
			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_die(
				esc_html__( 'You do not have permission to run an audit.', 'sitecraft-accessibility' ),
				403
			);
		}

		check_admin_referer( self::ACTION_NONCE, self::ACTION_NONCE );

		$action = sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) );

		if ( 'scan' === $action ) {
			Installer::reset_scan_state();

			// Dispatched rather than run inline: a full pass is chunked across cron runs.
			wp_schedule_single_event( time() + 5, Installer::SCAN_EVENT );
			spawn_cron();

			Notices::add(
				__( 'Audit queued. Findings appear here as each batch completes.', 'sitecraft-accessibility' ),
				'success'
			);
		} elseif ( 'clear' === $action ) {
			$removed = Installer::clear_issues();

			Notices::add(
				sprintf(
					/* translators: %s: number of findings removed */
					_n( '%s finding cleared.', '%s findings cleared.', $removed, 'sitecraft-accessibility' ),
					number_format_i18n( $removed )
				),
				'success'
			);
		}

		wp_safe_redirect( $this->redirect_url() );
		exit;
	}

	/**
	 * Renders the screen body.
	 *
	 * @return void
	 */
	protected function render_body(): void {
		$this->render_cards();
		$this->render_actions();
		$this->render_rule_breakdown();
	}

	/**
	 * Renders the four headline figures.
	 *
	 * @return void
	 */
	private function render_cards(): void {
		$severities = $this->repository->severity_counts();

		$total    = $this->repository->total();
		$objects  = $this->repository->object_count();
		$critical = (int) ( $severities['critical'] ?? 0 );

		$state     = Installer::scan_state();
		$last_run  = $state['finished_at'] > 0 ? $state['finished_at'] : $state['started_at'];
		$date_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$last_text = $last_run > 0
			? wp_date( (string) $date_fmt, $last_run )
			: __( 'Never', 'sitecraft-accessibility' );

		echo '<div class="sc-cards">';

		$this->render_card(
			__( 'Open findings', 'sitecraft-accessibility' ),
			number_format_i18n( $total ),
			sprintf(
				/* translators: %s: number of pieces of content */
				_n( 'across %s item of content', 'across %s items of content', $objects, 'sitecraft-accessibility' ),
				number_format_i18n( $objects )
			),
			$total > 0 ? 'warn' : 'good'
		);

		$this->render_card(
			__( 'Critical', 'sitecraft-accessibility' ),
			number_format_i18n( $critical ),
			__( 'blocks a task outright for someone', 'sitecraft-accessibility' ),
			$critical > 0 ? 'bad' : 'good'
		);

		$this->render_card(
			__( 'Content audited', 'sitecraft-accessibility' ),
			number_format_i18n( $state['scanned'] ),
			$state['queue_total'] > 0
				? sprintf(
					/* translators: %s: number of items queued for audit */
					__( 'of %s queued', 'sitecraft-accessibility' ),
					number_format_i18n( $state['queue_total'] )
				)
				: __( 'in the last run', 'sitecraft-accessibility' ),
			'good'
		);

		$this->render_card(
			__( 'Last audit', 'sitecraft-accessibility' ),
			$last_text,
			$state['finished_at'] > 0
				? __( 'completed', 'sitecraft-accessibility' )
				: __( 'in progress or not yet finished', 'sitecraft-accessibility' ),
			$last_run > 0 ? 'good' : 'warn'
		);

		echo '</div>';

		$this->render_progress( $state );
	}

	/**
	 * Renders one figure card.
	 *
	 * @param string $label    Card label.
	 * @param string $value    Card value, already localised.
	 * @param string $meta     Supporting line.
	 * @param string $modifier One of good, warn or bad.
	 * @return void
	 */
	private function render_card( string $label, string $value, string $meta, string $modifier ): void {
		printf(
			'<div class="sc-card sc-card--%1$s"><p class="sc-card-label">%2$s</p><p class="sc-card-value">%3$s</p><p class="sc-card-meta">%4$s</p></div>',
			esc_attr( $modifier ),
			esc_html( $label ),
			esc_html( $value ),
			esc_html( $meta )
		);
	}

	/**
	 * Renders the progress bar for an audit that has not finished its pass.
	 *
	 * @param array<string, int> $state Scan cursor.
	 * @return void
	 */
	private function render_progress( array $state ): void {
		if ( $state['queue_total'] < 1 || $state['scanned'] >= $state['queue_total'] ) {
			return;
		}

		$percent = (int) round( ( $state['scanned'] / $state['queue_total'] ) * 100 );

		printf(
			'<div class="sc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%1$d" aria-label="%2$s"><span class="sc-progress-bar" style="width:%1$d%%"></span></div>',
			esc_attr( (string) $percent ),
			esc_attr__( 'Audit progress', 'sitecraft-accessibility' )
		);
	}

	/**
	 * Renders the action form.
	 *
	 * @return void
	 */
	private function render_actions(): void {
		printf(
			'<form method="post" action="%s" class="sc-actions">',
			esc_url( $this->redirect_url() )
		);

		wp_nonce_field( self::ACTION_NONCE, self::ACTION_NONCE );

		printf(
			'<button type="submit" name="%1$s" value="scan" class="button button-primary">%2$s</button>',
			esc_attr( self::ACTION_FIELD ),
			esc_html__( 'Audit the site now', 'sitecraft-accessibility' )
		);

		printf(
			'<button type="submit" name="%1$s" value="clear" class="button button-secondary" data-sc-confirm="%2$s">%3$s</button>',
			esc_attr( self::ACTION_FIELD ),
			esc_attr__( 'This deletes every recorded finding. The next audit will find them again. Continue?', 'sitecraft-accessibility' ),
			esc_html__( 'Clear findings', 'sitecraft-accessibility' )
		);

		printf(
			'<a class="button button-link" href="%1$s">%2$s</a>',
			esc_url( add_query_arg( array( 'page' => 'sitecraft-accessibility-settings' ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Audit settings', 'sitecraft-accessibility' )
		);

		echo '</form>';
	}

	/**
	 * Renders findings grouped by rule, worst first.
	 *
	 * @return void
	 */
	private function render_rule_breakdown(): void {
		$rows = $this->repository->rule_counts( self::RULE_ROWS );

		printf( '<h2>%s</h2>', esc_html__( 'Findings by rule', 'sitecraft-accessibility' ) );

		if ( empty( $rows ) ) {
			printf(
				'<p class="sc-empty">%s</p>',
				esc_html__( 'Nothing recorded yet. Run an audit to populate this list. An empty list means the automated rules found nothing, not that the site is conformant: automated testing reaches roughly a third of WCAG, and the rest needs a person.', 'sitecraft-accessibility' )
			);

			return;
		}

		$titles = Settings::rule_choices();

		echo '<div class="sc-table-scroll"><table class="sc-table"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Rule', 'sitecraft-accessibility' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Criterion', 'sitecraft-accessibility' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Severity', 'sitecraft-accessibility' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Findings', 'sitecraft-accessibility' ) );
		echo '</tr></thead><tbody>';

		foreach ( (array) $rows as $row ) {
			$rule_id = (string) $row['rule_id'];
			$url     = add_query_arg(
				array(
					'page' => 'sitecraft-accessibility-issues',
					'rule' => $rule_id,
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			printf(
				'<td><a href="%1$s">%2$s</a><br /><code class="sc-code">%3$s</code></td>',
				esc_url( $url ),
				esc_html( $titles[ $rule_id ] ?? $rule_id ),
				esc_html( $rule_id )
			);
			printf(
				'<td>%1$s <span class="sc-badge sc-badge--info">%2$s</span></td>',
				esc_html( (string) $row['criterion'] ),
				esc_html( (string) $row['level'] )
			);
			printf(
				'<td><span class="sc-badge sc-badge--%1$s">%2$s</span></td>',
				esc_attr( (string) $row['severity'] ),
				esc_html( $this->severity_label( (string) $row['severity'] ) )
			);
			printf( '<td>%s</td>', esc_html( number_format_i18n( (int) $row['total'] ) ) );
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Translates a stored severity key into its label.
	 *
	 * @param string $severity Stored severity.
	 * @return string
	 */
	private function severity_label( string $severity ): string {
		$labels = Settings::severity_choices();

		return (string) ( $labels[ $severity ] ?? $severity );
	}
}
