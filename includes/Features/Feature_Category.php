<?php
/**
 * Feature category constants.
 *
 * Defines the categories a feature can belong to, which helps with organizing features in the UI and codebase.
 *
 * @package WordPress\AI\Features
 *
 * @since 0.6.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Features;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Feature category constants.
 *
 * Provides type-safe-ish constants for features categorization.
 * These values correspond to where features are displayed in the settings UI.
 *
 * @since 0.6.0
 */
class Feature_Category {
	/**
	 * Other/fallback category constant.
	 *
	 * Used as a fallback for features whose category does not match any
	 * known category constant. Features in this category appear in the
	 * Other Features section.
	 *
	 * @since 0.6.0
	 *
	 * @var string
	 */
	public const OTHER = 'other';
}
