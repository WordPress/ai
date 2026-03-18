<?php
/**
 * Feature category constants.
 *
 * Defines the categories a feature can belong to, which helps with organizing features in the UI and codebase.
 *
 * @package WordPress\AI\Features
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Features;

/**
 * Feature category constants.
 *
 * Provides type-safe-ish constants for features categorization.
 * These values correspond to where features are displayed in the settings UI.
 *
 * @since x.x.x
 */
class Feature_Category {
	/**
	 * Other/fallback category constant.
	 *
	 * Used as a fallback for features whose category does not match any
	 * known category constant. Features in this category appear in the
	 * Other Features section.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const OTHER = 'other';
}
