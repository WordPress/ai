<?php
/**
 * Experiment category constants.
 *
 * Defines the categories an experiment can belong to.
 *
 * @package WordPress\AI
 *
 * @since 0.4.0
 */

declare( strict_types=1 );

namespace WordPress\AI;

/**
 * Experiment category constants.
 *
 * Provides type-safe-ish constants for experiment categorization.
 * These values correspond to where experiments are displayed in the settings UI.
 *
 * @since 0.4.0
 */
class Experiment_Category {

	/**
	 * Editor category constant.
	 *
	 * Experiments in this category appear in the Editor Experiments.
	 *
	 * @since 0.4.0
	 *
	 * @var string
	 */
	public const EDITOR = 'editor';

	/**
	 * Admin category constant.
	 *
	 * Experiments in this category appear in the WordPress admin context.
	 *
	 * @since 0.4.0
	 *
	 * @var string
	 */
	public const ADMIN = 'admin';

	/**
	 * Other/fallback category constant.
	 *
	 * Used as a fallback for experiments whose category does not match any
	 * known category constant. Experiments in this category appear in the
	 * Other Experiments section.
	 *
	 * @since 0.4.0
	 *
	 * @var string
	 */
	public const OTHER = 'other';
}
