<?php
/**
 * Global helper functions.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

/**
 * Gets the global Feature_Registry instance.
 *
 * @since 0.1.0
 *
 * @return Feature_Registry The Feature_Registry instance.
 */
function wp_ai_feature_registry(): Feature_Registry {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Feature_Registry();
	}

	return $instance;
}
