<?php
/**
 * Runs the rule set over a document.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DOMXPath;
use Sitecraft\Accessibility\Core\Settings\Registry;
use Sitecraft\Accessibility\Core\Support\Logger;
use Throwable;
use WP_Post;

/**
 * The audit itself: parse once, run every active rule against the same DOM.
 *
 * Parsing is the expensive part, so it happens once per document rather than once
 * per rule. A rule that throws is logged and skipped: one broken check must not
 * cost a site the other fourteen.
 */
final class Analyzer {

	/**
	 * Active rule set.
	 *
	 * @var RuleRegistry
	 */
	private RuleRegistry $rules;

	/**
	 * Settings schema.
	 *
	 * @var Registry
	 */
	private Registry $settings;

	/**
	 * Constructor.
	 *
	 * @param RuleRegistry $rules    Active rule set.
	 * @param Registry     $settings Settings schema.
	 */
	public function __construct( RuleRegistry $rules, Registry $settings ) {
		$this->rules    = $rules;
		$this->settings = $settings;
	}

	/**
	 * Builds an analyser from the settings schema.
	 *
	 * @param Registry $settings Settings schema.
	 * @return self
	 */
	public static function create( Registry $settings ): self {
		return new self( RuleRegistry::create( $settings ), $settings );
	}

	/**
	 * The rule set this analyser runs.
	 *
	 * @return RuleRegistry
	 */
	public function rules(): RuleRegistry {
		return $this->rules;
	}

	/**
	 * Audits a fragment of HTML.
	 *
	 * @param string $html HTML to audit.
	 * @return Issue[]
	 */
	public function analyse( string $html ): array {
		$document = Document::load( $html );

		if ( null === $document ) {
			return array();
		}

		$xpath  = new DOMXPath( $document );
		$length = $this->context_length();
		$cap    = $this->issue_cap();
		$issues = array();

		foreach ( $this->rules->all() as $rule ) {
			try {
				$rows = $rule->evaluate( $xpath, $document );
			} catch ( Throwable $error ) {
				Logger::error(
					'Rule threw during evaluation and was skipped.',
					array(
						'rule'  => $rule->id(),
						'error' => $error->getMessage(),
					)
				);

				continue;
			}

			foreach ( $rows as $row ) {
				if ( count( $issues ) >= $cap ) {
					// A generated page can contain thousands of instances of one mistake.
					// Recording all of them fills the table without telling anyone more.
					if ( $this->settings->is_enabled( 'debug_logging' ) ) {
						Logger::warning(
							'Findings capped for one document.',
							array(
								'cap'  => $cap,
								'rule' => $rule->id(),
							)
						);
					}

					return $issues;
				}

				$issues[] = Issue::from_rule( $rule, (array) $row, $length );
			}
		}

		return $issues;
	}

	/**
	 * Audits a post, rendering its content first when configured to.
	 *
	 * @param WP_Post $post Post to audit.
	 * @return Issue[]
	 */
	public function analyse_post( WP_Post $post ): array {
		$content = Document::prepare(
			(string) $post->post_content,
			$this->settings->is_enabled( 'scan_apply_content_filters' )
		);

		/**
		 * Filters the markup audited for a post.
		 *
		 * Lets a site fold in output its templates add outside `the_content`, such as a
		 * custom-field driven hero block.
		 *
		 * @param string  $content Markup about to be audited.
		 * @param WP_Post $post    Post being audited.
		 */
		$content = (string) apply_filters( 'sitecraft_a11y_post_content', $content, $post );

		return $this->analyse( $content );
	}

	/**
	 * Stored snippet length.
	 *
	 * @return int
	 */
	private function context_length(): int {
		$length = (int) $this->settings->get( 'scan_context_length', 512 );

		return max( 64, min( 2048, $length ) );
	}

	/**
	 * Maximum findings recorded for a single document.
	 *
	 * @return int
	 */
	private function issue_cap(): int {
		/**
		 * Filters the per-document findings cap.
		 *
		 * @param int $cap Maximum findings recorded for one object.
		 */
		return max( 1, (int) apply_filters( 'sitecraft_a11y_max_issues_per_object', 200 ) );
	}
}
