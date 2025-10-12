<?php
/**
 * Abstract Feature base class.
 *
 * @package WordPress\AI\Abstracts
 */

namespace WordPress\AI\Abstracts;

use WordPress\AI\Interfaces\Feature;

/**
 * Base implementation for features.
 *
 * Provides common functionality for all features including hook management,
 * enable/disable state, and helper methods for registering actions and filters.
 *
 * @since 0.1.0
 */
abstract class Abstract_Feature implements Feature {
	/**
	 * Feature identifier.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $id;

	/**
	 * Feature label.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $label;

	/**
	 * Feature description.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $description;

	/**
	 * Whether the feature is enabled.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	protected $enabled = true;

	/**
	 * Registered hooks.
	 *
	 * @since 0.1.0
	 * @var array
	 */
	protected $hooks = array();

	/**
	 * Gets the feature ID.
	 *
	 * @since 0.1.0
	 *
	 * @return string Feature identifier.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Gets the feature label.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated feature label.
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Gets the feature description.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated feature description.
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Checks if feature is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_enabled(): bool {
		$enabled = $this->enabled;

		/**
		 * Filters the enabled status for a specific feature.
		 *
		 * The dynamic portion of the hook name, `$this->id`, refers to the feature ID.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Whether the feature is enabled.
		 */
		$enabled = apply_filters( "ai_feature_{$this->id}_enabled", $enabled );

		/**
		 * Filters the enabled status across all features.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $enabled    Whether the feature is enabled.
		 * @param string $feature_id The feature identifier.
		 */
		return apply_filters( 'ai_feature_enabled', $enabled, $this->id );
	}

	/**
	 * Registers the feature.
	 *
	 * Must be implemented by child classes to set up hooks and functionality.
	 *
	 * @since 0.1.0
	 */
	abstract public function register(): void;

	/**
	 * Helper method to add action hooks.
	 *
	 * Provides a convenient way to add actions while tracking them for debugging.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback function.
	 * @param int      $priority Optional. Priority. Default 10.
	 * @param int      $args     Optional. Number of arguments. Default 1.
	 */
	protected function add_action( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_action( $hook, $callback, $priority, $args );
		$this->hooks[] = array(
			'type'     => 'action',
			'hook'     => $hook,
			'priority' => $priority,
		);
	}

	/**
	 * Helper method to add filter hooks.
	 *
	 * Provides a convenient way to add filters while tracking them for debugging.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback function.
	 * @param int      $priority Optional. Priority. Default 10.
	 * @param int      $args     Optional. Number of arguments. Default 1.
	 */
	protected function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->hooks[] = array(
			'type'     => 'filter',
			'hook'     => $hook,
			'priority' => $priority,
		);
	}

	/**
	 * Gets all registered hooks for this feature.
	 *
	 * Useful for debugging and testing.
	 *
	 * @since 0.1.0
	 *
	 * @return array Array of registered hooks.
	 */
	public function get_hooks(): array {
		return $this->hooks;
	}
}
