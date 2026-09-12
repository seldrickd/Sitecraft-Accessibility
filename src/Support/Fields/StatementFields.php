<?php
/**
 * Accessibility statement settings fields.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Support\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sitecraft\Accessibility\Support\Settings;

/**
 * Declares the inputs for the generated accessibility statement.
 *
 * The statement is a legal artefact under the European Accessibility Act and the
 * public-sector directives, so every field maps onto something a regulator or a
 * complainant expects to find.
 */
final class StatementFields {

	/**
	 * Field definitions for the `statement` section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			array(
				'id'          => 'statement_enabled',
				'section'     => 'statement',
				'type'        => 'toggle',
				'label'       => __( 'Enable the statement shortcode', 'sitecraft-accessibility' ),
				'description' => __( 'Registers <code>[sitecraft_accessibility_statement]</code> so you can place the statement on any page. Turn it off if you publish a hand-written statement instead.', 'sitecraft-accessibility' ),
				'default'     => true,
			),
			array(
				'id'          => 'statement_organisation',
				'section'     => 'statement',
				'type'        => 'text',
				'label'       => __( 'Organisation name', 'sitecraft-accessibility' ),
				'description' => __( 'The legal entity responsible for the site. Falls back to the site title when left empty.', 'sitecraft-accessibility' ),
				'default'     => '',
				'depends_on'  => array( 'statement_enabled' => true ),
			),
			array(
				'id'          => 'statement_contact_email',
				'section'     => 'statement',
				'type'        => 'email',
				'label'       => __( 'Feedback email address', 'sitecraft-accessibility' ),
				'description' => __( 'A monitored address a visitor can use to report a barrier. Falls back to the site administration email. A statement without a working contact route is the single most common compliance failure.', 'sitecraft-accessibility' ),
				'default'     => '',
				'depends_on'  => array( 'statement_enabled' => true ),
			),
			array(
				'id'          => 'statement_contact_url',
				'section'     => 'statement',
				'type'        => 'url',
				'label'       => __( 'Feedback form URL', 'sitecraft-accessibility' ),
				'description' => __( 'Optional alternative to email. Offer both where you can: a visitor who cannot use your form needs the address, and a visitor without email needs the form.', 'sitecraft-accessibility' ),
				'default'     => '',
				'placeholder' => 'https://example.com/contact',
				'depends_on'  => array( 'statement_enabled' => true ),
			),
			array(
				'id'          => 'statement_standard',
				'section'     => 'statement',
				'type'        => 'select',
				'label'       => __( 'Standard referenced', 'sitecraft-accessibility' ),
				'description' => __( 'Name the standard you measured against. EN 301 549 is the European harmonised standard and incorporates WCAG by reference; cite it directly if you are in scope of the EAA.', 'sitecraft-accessibility' ),
				'default'     => 'wcag-22-aa',
				'choices'     => array(
					'wcag-21-aa'  => __( 'WCAG 2.1 Level AA', 'sitecraft-accessibility' ),
					'wcag-22-aa'  => __( 'WCAG 2.2 Level AA', 'sitecraft-accessibility' ),
					'en-301-549'  => __( 'EN 301 549 (which incorporates WCAG)', 'sitecraft-accessibility' ),
					'section-508' => __( 'Section 508 / US federal procurement', 'sitecraft-accessibility' ),
				),
				'depends_on'  => array( 'statement_enabled' => true ),
			),
			array(
				'id'          => 'statement_conformance',
				'section'     => 'statement',
				'type'        => 'radio',
				'label'       => __( 'Conformance claimed', 'sitecraft-accessibility' ),
				'description' => __( 'Claim only what you can evidence. "Partially conformant" alongside an honest list of known limitations is a defensible position; an unsupported claim of full conformance is the thing regulators act on.', 'sitecraft-accessibility' ),
				'default'     => 'partial',
				'choices'     => array(
					'full'    => __( 'Fully conformant', 'sitecraft-accessibility' ),
					'partial' => __( 'Partially conformant', 'sitecraft-accessibility' ),
					'none'    => __( 'Not conformant', 'sitecraft-accessibility' ),
				),
				'depends_on'  => array( 'statement_enabled' => true ),
			),
			array(
				'id'          => 'statement_assessment_method',
				'section'     => 'statement',
				'type'        => 'select',
				'label'       => __( 'How the site was assessed', 'sitecraft-accessibility' ),
				'description' => __( 'Automated tooling, this plugin included, finds a minority of barriers. Say plainly whether a person tested the site, because that is what the claim rests on.', 'sitecraft-accessibility' ),
				'default'     => 'self',
				'choices'     => array(
					'self'     => __( 'Self-assessment', 'sitecraft-accessibility' ),
					'external' => __( 'Independent third-party audit', 'sitecraft-accessibility' ),
					'mixed'    => __( 'Self-assessment with an external review', 'sitecraft-accessibility' ),
				),
				'depends_on'  => array( 'statement_enabled' => true ),
			),
			array(
				'id'          => 'statement_assessment_date',
				'section'     => 'statement',
				'type'        => 'text',
				'label'       => __( 'Date of last assessment', 'sitecraft-accessibility' ),
				'description' => __( 'Format YYYY-MM-DD. Regulators expect a statement to be reviewed at least annually; a date more than a year old undermines everything above it.', 'sitecraft-accessibility' ),
				'default'     => '',
				'placeholder' => '2026-01-31',
				'sanitize'    => array( Settings::class, 'sanitize_date' ),
				'depends_on'  => array( 'statement_enabled' => true ),
			),
			array(
				'id'          => 'statement_limitations',
				'section'     => 'statement',
				'type'        => 'textarea',
				'label'       => __( 'Known limitations', 'sitecraft-accessibility' ),
				'description' => __( 'One per line. Name the content, the barrier and what you are doing about it. Listing a known problem is not an admission of failure; hiding one is.', 'sitecraft-accessibility' ),
				'default'     => '',
				'rows'        => 5,
				'placeholder' => 'Archived PDFs published before 2023 are not tagged for screen readers; they are being replaced with HTML.',
				'depends_on'  => array( 'statement_enabled' => true ),
			),
			array(
				'id'          => 'statement_feedback_process',
				'section'     => 'statement',
				'type'        => 'textarea',
				'label'       => __( 'Feedback and response process', 'sitecraft-accessibility' ),
				'description' => __( 'Tell a visitor what happens after they report a barrier and how long a reply takes. The EAA expects a described process, not just an address.', 'sitecraft-accessibility' ),
				'default'     => 'We aim to acknowledge accessibility reports within five working days and to agree a fix or a workaround with you.',
				'rows'        => 4,
				'depends_on'  => array( 'statement_enabled' => true ),
			),
			array(
				'id'          => 'statement_enforcement_contact',
				'section'     => 'statement',
				'type'        => 'textarea',
				'label'       => __( 'Enforcement procedure', 'sitecraft-accessibility' ),
				'description' => __( 'The body a visitor may escalate to if your response does not satisfy them. This differs by country, so name the regulator that covers you rather than a generic address.', 'sitecraft-accessibility' ),
				'default'     => '',
				'rows'        => 4,
				'depends_on'  => array( 'statement_enabled' => true ),
			),
			array(
				'id'          => 'statement_page_id',
				'section'     => 'statement',
				'type'        => 'number',
				'label'       => __( 'Statement page', 'sitecraft-accessibility' ),
				'description' => __( 'ID of the page holding the statement shortcode. Set for you by the "Create statement page" action, and kept here so the plugin can link to the statement without guessing.', 'sitecraft-accessibility' ),
				'default'     => 0,
				'min'         => 0,
				'step'        => 1,
				'depends_on'  => array( 'statement_enabled' => true ),
			),
		);
	}
}
