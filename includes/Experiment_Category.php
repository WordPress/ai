<?php
/**
 * Experiment category constants.
 *
 * Defines the categories an experiment can belong to.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI;

/**
 * Experiment category constants.
 *
 * Provides type-safe-ish constants for experiment categorization.
 * These values correspond to where experiments are displayed in the settings UI.
 *
 * @since x.x.x
 */
class Experiment_Category {

	/**
	 * Editor category constant.
	 *
	 * Experiments in this category appear in the Editor Experiments.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const EDITOR = 'editor';

	/**
	 * Admin category constant.
	 *
	 * Experiments in this category appear in the WordPress admin context.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const OTHER = 'other';
}
