<?php
/**
 * A single recorded finding.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object carrying one finding between the analyser, the store,
 * the REST layer and the CLI.
 *
 * Passing an object rather than a loose array is what stops the four consumers
 * from disagreeing about which keys exist.
 */
final class Issue {

	/**
	 * Rule that produced the finding.
	 *
	 * @var string
	 */
	private string $rule_id;

	/**
	 * Translated rule title.
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * WCAG success criterion.
	 *
	 * @var string
	 */
	private string $criterion;

	/**
	 * Conformance level.
	 *
	 * @var string
	 */
	private string $level;

	/**
	 * Impact classification.
	 *
	 * @var string
	 */
	private string $severity;

	/**
	 * Offending markup, already truncated.
	 *
	 * @var string
	 */
	private string $context;

	/**
	 * Short CSS-like path to the offending element.
	 *
	 * @var string
	 */
	private string $selector_hint;

	/**
	 * Remediation advice.
	 *
	 * @var string
	 */
	private string $how_to_fix;

	/**
	 * Constructor.
	 *
	 * @param string $rule_id       Rule identifier.
	 * @param string $title         Translated rule title.
	 * @param string $criterion     WCAG criterion number.
	 * @param string $level         Conformance level.
	 * @param string $severity      Impact classification.
	 * @param string $context       Offending markup.
	 * @param string $selector_hint Selector hint.
	 * @param string $how_to_fix    Remediation advice.
	 */
	public function __construct(
		string $rule_id,
		string $title,
		string $criterion,
		string $level,
		string $severity,
		string $context,
		string $selector_hint,
		string $how_to_fix
	) {
		$this->rule_id       = $rule_id;
		$this->title         = $title;
		$this->criterion     = $criterion;
		$this->level         = $level;
		$this->severity      = $severity;
		$this->context       = $context;
		$this->selector_hint = $selector_hint;
		$this->how_to_fix    = $how_to_fix;
	}

	/**
	 * Builds a finding from a rule and one of its result rows.
	 *
	 * @param Rule                 $rule           Rule that fired.
	 * @param array<string, mixed> $row            Row returned by `evaluate()`.
	 * @param int                  $context_length Maximum stored snippet length.
	 * @return self
	 */
	public static function from_rule( Rule $rule, array $row, int $context_length ): self {
		$context = (string) ( $row['context'] ?? '' );

		if ( function_exists( 'mb_substr' ) ) {
			$context = mb_substr( $context, 0, $context_length );
		} else {
			$context = substr( $context, 0, $context_length );
		}

		return new self(
			$rule->id(),
			$rule->title(),
			$rule->criterion(),
			$rule->level(),
			$rule->severity(),
			$context,
			(string) ( $row['selector_hint'] ?? '' ),
			$rule->how_to_fix()
		);
	}

	/**
	 * Rule identifier.
	 *
	 * @return string
	 */
	public function rule_id(): string {
		return $this->rule_id;
	}

	/**
	 * Impact classification.
	 *
	 * @return string
	 */
	public function severity(): string {
		return $this->severity;
	}

	/**
	 * WCAG criterion.
	 *
	 * @return string
	 */
	public function criterion(): string {
		return $this->criterion;
	}

	/**
	 * Conformance level.
	 *
	 * @return string
	 */
	public function level(): string {
		return $this->level;
	}

	/**
	 * Offending markup.
	 *
	 * @return string
	 */
	public function context(): string {
		return $this->context;
	}

	/**
	 * Selector hint.
	 *
	 * @return string
	 */
	public function selector_hint(): string {
		return $this->selector_hint;
	}

	/**
	 * Columns for the issues table.
	 *
	 * @return array<string, string>
	 */
	public function to_row(): array {
		return array(
			'rule_id'       => $this->rule_id,
			'severity'      => $this->severity,
			'criterion'     => $this->criterion,
			'level'         => $this->level,
			'context'       => $this->context,
			'selector_hint' => $this->selector_hint,
		);
	}

	/**
	 * Serialisable form for REST responses and CLI output.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'rule_id'       => $this->rule_id,
			'title'         => $this->title,
			'criterion'     => $this->criterion,
			'level'         => $this->level,
			'severity'      => $this->severity,
			'context'       => $this->context,
			'selector_hint' => $this->selector_hint,
			'how_to_fix'    => $this->how_to_fix,
		);
	}
}
