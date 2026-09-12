<?php
/**
 * Settings form renderer.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a {@see Registry} schema as an escaped settings form.
 *
 * Every value that reaches the browser passes through an `esc_*` call here, which
 * keeps escaping decisions in one reviewable file instead of scattered templates.
 */
final class Renderer {

	/**
	 * Settings schema being rendered.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Settings schema.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Renders every field in a section as a WordPress form table.
	 *
	 * @param string $section_id Section to render.
	 * @return void
	 */
	public function render_section( string $section_id ): void {
		$fields = $this->registry->fields( $section_id );

		if ( empty( $fields ) ) {
			return;
		}

		$sections = $this->registry->sections();
		$section  = $sections[ $section_id ] ?? null;

		if ( null !== $section && '' !== $section['description'] ) {
			printf(
				'<p class="description sc-section-description">%s</p>',
				esc_html( $section['description'] )
			);
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $fields as $field ) {
			$this->render_row( $field );
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders a single labelled table row.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return void
	 */
	private function render_row( array $field ): void {
		$input_id = $this->input_id( $field['id'] );
		$row_attr = '';

		if ( ! empty( $field['depends_on'] ) && is_array( $field['depends_on'] ) ) {
			$row_attr = sprintf(
				' data-sc-depends="%s"',
				esc_attr( (string) wp_json_encode( $field['depends_on'] ) )
			);
		}

		$classes = trim( 'sc-field-row ' . (string) $field['class'] );

		printf(
			'<tr class="%1$s"%2$s>',
			esc_attr( $classes ),
			$row_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_attr above.
		);

		// A fieldset legend is the accessible label for grouped controls; a plain
		// `for` label is correct for every single-control field.
		if ( in_array( $field['type'], array( 'radio', 'multicheck' ), true ) ) {
			printf( '<th scope="row">%s</th>', esc_html( (string) $field['label'] ) );
		} else {
			printf(
				'<th scope="row"><label for="%1$s">%2$s</label></th>',
				esc_attr( $input_id ),
				esc_html( (string) $field['label'] )
			);
		}

		echo '<td>';
		$this->render_control( $field );

		if ( '' !== (string) $field['description'] ) {
			printf(
				'<p class="description" id="%1$s-description">%2$s</p>',
				esc_attr( $input_id ),
				wp_kses(
					(string) $field['description'],
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
						'a'      => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				)
			);
		}

		echo '</td></tr>';
	}

	/**
	 * Renders the control itself for a field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return void
	 */
	private function render_control( array $field ): void {
		$id       = (string) $field['id'];
		$value    = $this->registry->get( $id );
		$name     = $this->input_name( $id );
		$input_id = $this->input_id( $id );
		$describe = '' !== (string) $field['description']
			? sprintf( ' aria-describedby="%s-description"', esc_attr( $input_id ) )
			: '';

		switch ( $field['type'] ) {
			case 'toggle':
				printf(
					'<label class="sc-toggle"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s%4$s /><span class="sc-toggle-track" aria-hidden="true"></span><span class="sc-toggle-text">%5$s</span></label>',
					esc_attr( $input_id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					$describe, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					esc_html__( 'Enabled', 'sitecraft-accessibility' )
				);
				break;

			case 'textarea':
			case 'code':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text %4$s" placeholder="%5$s"%6$s>%7$s</textarea>',
					esc_attr( $input_id ),
					esc_attr( $name ),
					(int) $field['rows'],
					'code' === $field['type'] ? 'code' : '',
					esc_attr( (string) $field['placeholder'] ),
					$describe, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					esc_textarea( (string) $value )
				);
				break;

			case 'list':
				$lines = is_array( $value ) ? implode( "\n", $value ) : (string) $value;

				printf(
					'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text code" placeholder="%4$s"%5$s>%6$s</textarea>',
					esc_attr( $input_id ),
					esc_attr( $name ),
					(int) $field['rows'],
					esc_attr( (string) $field['placeholder'] ),
					$describe, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					esc_textarea( $lines )
				);
				break;

			case 'select':
				printf(
					'<select id="%1$s" name="%2$s"%3$s>',
					esc_attr( $input_id ),
					esc_attr( $name ),
					$describe // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);

				foreach ( (array) $field['choices'] as $choice_value => $choice_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( (string) $choice_value ),
						selected( (string) $value, (string) $choice_value, false ),
						esc_html( (string) $choice_label )
					);
				}

				echo '</select>';
				break;

			case 'radio':
				echo '<fieldset class="sc-choice-group">';

				foreach ( (array) $field['choices'] as $choice_value => $choice_label ) {
					printf(
						'<label class="sc-choice"><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
						esc_attr( $name ),
						esc_attr( (string) $choice_value ),
						checked( (string) $value, (string) $choice_value, false ),
						esc_html( (string) $choice_label )
					);
				}

				echo '</fieldset>';
				break;

			case 'multicheck':
				$selected = is_array( $value ) ? $value : array();

				echo '<fieldset class="sc-choice-group">';

				foreach ( (array) $field['choices'] as $choice_value => $choice_label ) {
					printf(
						'<label class="sc-choice"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
						esc_attr( $name ),
						esc_attr( (string) $choice_value ),
						checked( in_array( (string) $choice_value, array_map( 'strval', $selected ), true ), true, false ),
						esc_html( (string) $choice_label )
					);
				}

				echo '</fieldset>';
				break;

			case 'password':
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="%3$s" class="regular-text" autocomplete="new-password" placeholder="%4$s"%5$s />',
					esc_attr( $input_id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) $field['placeholder'] ),
					$describe // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="small-text"%4$s%5$s%6$s%7$s />',
					esc_attr( $input_id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					null !== $field['min'] ? ' min="' . esc_attr( (string) $field['min'] ) . '"' : '',
					null !== $field['max'] ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : '',
					null !== $field['step'] ? ' step="' . esc_attr( (string) $field['step'] ) . '"' : '',
					$describe // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
				break;

			case 'color':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="sc-color-picker" data-default-color="%4$s"%5$s />',
					esc_attr( $input_id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) $field['default'] ),
					$describe // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
				break;

			case 'email':
			case 'url':
			case 'text':
			default:
				$html_type = in_array( $field['type'], array( 'email', 'url' ), true ) ? $field['type'] : 'text';

				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" placeholder="%5$s"%6$s />',
					esc_attr( (string) $html_type ),
					esc_attr( $input_id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) $field['placeholder'] ),
					$describe // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
				break;
		}
	}

	/**
	 * Builds the form input name for a field.
	 *
	 * @param string $id Field id.
	 * @return string
	 */
	private function input_name( string $id ): string {
		return $this->registry->option_name() . '[' . $id . ']';
	}

	/**
	 * Builds a DOM id for a field.
	 *
	 * @param string $id Field id.
	 * @return string
	 */
	private function input_id( string $id ): string {
		return 'sc-field-' . str_replace( '_', '-', $id );
	}
}
