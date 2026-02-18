<?php
/**
 * Experiment area constants.
 *
 * Defines the categories/areas where experiments can be displayed.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI;

/**
 * Experiment area constants.
 *
 * Provides type-safe-ish constants for experiment categorization.
 * These values correspond to where experiments are displayed in the settings UI.
 *
 * @since x.x.x
 */
class Experiment_Area {

	/**
	 * Editor area constant.
	 *
	 * Experiments in this area appear in the block editor context.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const EDITOR = 'editor';

	/**
	 * Admin area constant.
	 *
	 * Experiments in this area appear in the WordPress admin context.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ADMIN = 'admin';
}
