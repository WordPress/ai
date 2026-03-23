<?php
/**
 * Experiment category constants.
 *
 * Defines the categories an experiment can belong to.
 *
 * @package WordPress\AI\Experiments
 *
 * @since 0.6.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments;

use WordPress\AI\Features\Feature_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Experiment category constants.
 *
 * Provides type-safe-ish constants for experiment categorization.
 * These values correspond to where experiments are displayed in the settings UI.
 *
 * @since 0.6.0
 */
class Experiment_Category extends Feature_Category {

	/**
	 * Editor category constant.
	 *
	 * Experiments in this category appear in the Editor Experiments.
	 *
	 * @since 0.6.0
	 *
	 * @var string
	 */
	public const EDITOR = 'editor';

	/**
	 * Admin category constant.
	 *
	 * Experiments in this category appear in the WordPress admin context.
	 *
	 * @since 0.6.0
	 *
	 * @var string
	 */
	public const ADMIN = 'admin';
}
