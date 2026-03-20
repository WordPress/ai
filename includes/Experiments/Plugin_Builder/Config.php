<?php
/**
 * Configuration for the AI Plugin Builder experiment.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder;

/**
 * Central configuration for the AI Plugin Builder.
 *
 * @since x.x.x
 */
class Config {

	/**
	 * Required capability to install generated plugins.
	 *
	 * @return string
	 */
	public static function install_capability(): string {
		return 'install_plugins';
	}
}
