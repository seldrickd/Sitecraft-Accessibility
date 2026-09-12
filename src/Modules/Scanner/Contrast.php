<?php
/**
 * WCAG relative luminance and contrast ratio maths.
 *
 * @package Sitecraft\Accessibility
 */

declare( strict_types=1 );

namespace Sitecraft\Accessibility\Modules\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the contrast algorithm from WCAG 2.x, definition "contrast ratio".
 *
 * Two mistakes are common in reimplementations of this and both are avoided here:
 * averaging the channels instead of weighting them, and comparing gamma-encoded
 * sRGB values without linearising first. Either produces ratios that look
 * plausible and pass text a real user cannot read.
 */
final class Contrast {

	/**
	 * Threshold below which a channel is linearised by division rather than a power curve.
	 *
	 * @var float
	 */
	private const LINEAR_THRESHOLD = 0.03928;

	/**
	 * Font size, in CSS pixels, at which text counts as large.
	 *
	 * @var float
	 */
	public const LARGE_TEXT_PX = 24.0;

	/**
	 * Font size, in CSS pixels, at which bold text counts as large.
	 *
	 * @var float
	 */
	public const LARGE_BOLD_PX = 18.66;

	/**
	 * The CSS named colours worth resolving.
	 *
	 * The full CSS list runs to 148 entries; these are the ones that actually turn up
	 * in inline styles pasted into an editor. An unknown name returns null, which the
	 * caller treats as "cannot decide" rather than "passes".
	 *
	 * @return array<string, string>
	 */
	private static function named_colors(): array {
		return array(
			'black'      => '#000000',
			'silver'     => '#c0c0c0',
			'gray'       => '#808080',
			'grey'       => '#808080',
			'white'      => '#ffffff',
			'maroon'     => '#800000',
			'red'        => '#ff0000',
			'purple'     => '#800080',
			'fuchsia'    => '#ff00ff',
			'magenta'    => '#ff00ff',
			'green'      => '#008000',
			'lime'       => '#00ff00',
			'olive'      => '#808000',
			'yellow'     => '#ffff00',
			'navy'       => '#000080',
			'blue'       => '#0000ff',
			'teal'       => '#008080',
			'aqua'       => '#00ffff',
			'cyan'       => '#00ffff',
			'orange'     => '#ffa500',
			'gold'       => '#ffd700',
			'pink'       => '#ffc0cb',
			'brown'      => '#a52a2a',
			'beige'      => '#f5f5dc',
			'ivory'      => '#fffff0',
			'khaki'      => '#f0e68c',
			'lavender'   => '#e6e6fa',
			'salmon'     => '#fa8072',
			'tan'        => '#d2b48c',
			'turquoise'  => '#40e0d0',
			'violet'     => '#ee82ee',
			'indigo'     => '#4b0082',
			'crimson'    => '#dc143c',
			'coral'      => '#ff7f50',
			'darkgray'   => '#a9a9a9',
			'darkgrey'   => '#a9a9a9',
			'lightgray'  => '#d3d3d3',
			'lightgrey'  => '#d3d3d3',
			'dimgray'    => '#696969',
			'dimgrey'    => '#696969',
			'whitesmoke' => '#f5f5f5',
			'snow'       => '#fffafa',
			'gainsboro'  => '#dcdcdc',
			'linen'      => '#faf0e6',
			'azure'      => '#f0ffff',
			'mintcream'  => '#f5fffa',
		);
	}

	/**
	 * Parses a CSS colour into 0-255 sRGB channels.
	 *
	 * Returns null for anything that cannot be resolved without a rendering engine:
	 * `currentColor`, `inherit`, `var()`, gradients and partially transparent values.
	 * Guessing at those would generate findings a developer cannot act on.
	 *
	 * @param string $value Raw CSS colour value.
	 * @return array{0:int,1:int,2:int}|null
	 */
	public static function parse_color( string $value ): ?array {
		$value = strtolower( trim( $value ) );

		if ( '' === $value || 'transparent' === $value ) {
			return null;
		}

		$named = self::named_colors();

		if ( isset( $named[ $value ] ) ) {
			$value = $named[ $value ];
		}

		if ( 1 === preg_match( '/^#([0-9a-f]{3,8})$/', $value, $matches ) ) {
			return self::from_hex( $matches[1] );
		}

		if ( 1 === preg_match( '/^rgba?\(([^)]+)\)$/', $value, $matches ) ) {
			return self::from_rgb_function( $matches[1] );
		}

		return null;
	}

	/**
	 * Converts hex digits to channels.
	 *
	 * @param string $hex Hex digits without the leading hash.
	 * @return array{0:int,1:int,2:int}|null
	 */
	private static function from_hex( string $hex ): ?array {
		$length = strlen( $hex );

		if ( 3 === $length || 4 === $length ) {
			// #abc and #abcd expand each digit; the fourth is alpha and is ignored below.
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]
				. ( 4 === $length ? $hex[3] . $hex[3] : '' );
		}

		if ( 8 === strlen( $hex ) ) {
			$alpha = hexdec( substr( $hex, 6, 2 ) ) / 255;

			// A translucent colour composites against something we cannot see from here.
			if ( $alpha < 0.95 ) {
				return null;
			}

			$hex = substr( $hex, 0, 6 );
		}

		if ( 6 !== strlen( $hex ) ) {
			return null;
		}

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Converts the argument list of `rgb()` or `rgba()` to channels.
	 *
	 * @param string $arguments Everything between the parentheses.
	 * @return array{0:int,1:int,2:int}|null
	 */
	private static function from_rgb_function( string $arguments ): ?array {
		$arguments = str_replace( '/', ' ', $arguments );
		$parts     = preg_split( '/[\s,]+/', trim( $arguments ) );

		if ( ! is_array( $parts ) ) {
			return null;
		}

		$parts = array_values( array_filter( $parts, 'strlen' ) );

		if ( count( $parts ) < 3 ) {
			return null;
		}

		if ( isset( $parts[3] ) ) {
			$alpha = (float) rtrim( $parts[3], '%' );

			if ( false !== strpos( $parts[3], '%' ) ) {
				$alpha /= 100;
			}

			if ( $alpha < 0.95 ) {
				return null;
			}
		}

		$channels = array();

		foreach ( array_slice( $parts, 0, 3 ) as $part ) {
			$number = (float) rtrim( $part, '%' );

			if ( false !== strpos( $part, '%' ) ) {
				$number = ( $number / 100 ) * 255;
			}

			$channels[] = (int) max( 0, min( 255, (int) round( $number ) ) );
		}

		return array( $channels[0], $channels[1], $channels[2] );
	}

	/**
	 * Relative luminance of an sRGB colour, per WCAG.
	 *
	 * @param array{0:int,1:int,2:int} $rgb Channels in the 0-255 range.
	 * @return float Luminance between 0 and 1.
	 */
	public static function relative_luminance( array $rgb ): float {
		$linear = array();

		foreach ( $rgb as $channel ) {
			$value = max( 0.0, min( 1.0, ( (int) $channel ) / 255 ) );

			$linear[] = $value <= self::LINEAR_THRESHOLD
				? $value / 12.92
				: pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return ( 0.2126 * $linear[0] ) + ( 0.7152 * $linear[1] ) + ( 0.0722 * $linear[2] );
	}

	/**
	 * Contrast ratio between two colours, from 1 to 21.
	 *
	 * @param array{0:int,1:int,2:int} $foreground Text colour channels.
	 * @param array{0:int,1:int,2:int} $background Background colour channels.
	 * @return float
	 */
	public static function ratio( array $foreground, array $background ): float {
		$first  = self::relative_luminance( $foreground );
		$second = self::relative_luminance( $background );

		$lighter = max( $first, $second );
		$darker  = min( $first, $second );

		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * Whether a size and weight combination counts as large text.
	 *
	 * @param float $font_size_px Computed font size in CSS pixels.
	 * @param bool  $is_bold      Whether the text is bold.
	 * @return bool
	 */
	public static function is_large_text( float $font_size_px, bool $is_bold ): bool {
		if ( $is_bold ) {
			return $font_size_px >= self::LARGE_BOLD_PX;
		}

		return $font_size_px >= self::LARGE_TEXT_PX;
	}

	/**
	 * Converts a CSS length to pixels, as far as that is decidable statically.
	 *
	 * Relative units are resolved against the supplied inherited size, which is the
	 * best a static parse can do; `em` chains longer than the ancestors we walked are
	 * simply not resolvable and return null.
	 *
	 * @param string $value    CSS length, for example `1.25rem`.
	 * @param float  $inherited Size in pixels the value is relative to.
	 * @return float|null
	 */
	public static function length_to_px( string $value, float $inherited = 16.0 ): ?float {
		$value = strtolower( trim( $value ) );

		if ( 1 !== preg_match( '/^(-?[0-9.]+)\s*(px|pt|rem|em|%)?$/', $value, $matches ) ) {
			return null;
		}

		$number = (float) $matches[1];
		$unit   = $matches[2] ?? 'px';

		switch ( $unit ) {
			case 'pt':
				// The CSS reference pixel is 1/96in and a point is 1/72in.
				return $number * ( 96 / 72 );

			case 'rem':
				// Root size is not knowable from a fragment; 16px is the browser default.
				return $number * 16.0;

			case 'em':
				return $number * $inherited;

			case '%':
				return ( $number / 100 ) * $inherited;

			case 'px':
			default:
				return $number;
		}
	}

	/**
	 * Splits a `style` attribute into lowercase property => value pairs.
	 *
	 * @param string $style Raw style attribute.
	 * @return array<string, string>
	 */
	public static function parse_declarations( string $style ): array {
		$declarations = array();

		foreach ( explode( ';', $style ) as $declaration ) {
			$position = strpos( $declaration, ':' );

			if ( false === $position ) {
				continue;
			}

			$property = strtolower( trim( substr( $declaration, 0, $position ) ) );
			$value    = trim( substr( $declaration, $position + 1 ) );

			if ( '' === $property || '' === $value ) {
				continue;
			}

			$declarations[ $property ] = $value;
		}

		return $declarations;
	}
}
