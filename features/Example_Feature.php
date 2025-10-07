<?php
/**
 * Example Feature implementation.
 *
 * @package WordPress\AI\Features
 */

namespace WordPress\AI\Features;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Interfaces\Conditional_Feature;

/**
 * Example feature demonstrating the feature registration system.
 *
 * This feature shows how to:
 * - Extend Abstract_Feature
 * - Implement Conditional_Feature for requirement checking
 * - Register actions and filters
 * - Use feature enable/disable state
 *
 * ## Overview
 *
 * The Example Feature demonstrates:
 * - Extending `Abstract_Feature` for common functionality
 * - Implementing `Conditional_Feature` for requirement checking
 * - Registering WordPress actions and filters
 * - Creating REST API endpoints
 * - Using the feature enable/disable system
 *
 * ## Usage
 *
 * This feature is automatically registered when the plugin loads. It demonstrates:
 *
 * ### 1. Action Hooks
 *
 * The feature adds a comment to the footer for logged-in users:
 *
 * ```php
 * $this->add_action( 'wp_footer', array( $this, 'add_footer_content' ), 20 );
 * ```
 *
 * ### 2. Filter Hooks
 *
 * The feature modifies the document title in debug mode:
 *
 * ```php
 * $this->add_filter( 'document_title_parts', array( $this, 'modify_title' ), 10, 1 );
 * ```
 *
 * ### 3. REST API Endpoint
 *
 * The feature registers a REST API endpoint at `/wp-json/ai/v1/example`:
 *
 * ```bash
 * curl -X GET http://your-site.com/wp-json/ai/v1/example \
 *   -H "Authorization: Bearer YOUR_TOKEN"
 * ```
 *
 * Response:
 * ```json
 * {
 *   "feature_id": "example-feature",
 *   "label": "Example Feature",
 *   "description": "Demonstrates the AI feature system with example hooks and functionality.",
 *   "enabled": true,
 *   "message": "Example feature is active!"
 * }
 * ```
 *
 * ## Disabling the Feature
 *
 * You can disable this feature using the filter:
 *
 * ```php
 * add_filter( 'ai_feature_enabled', function( $enabled, $feature_id ) {
 *     if ( 'example-feature' === $feature_id ) {
 *         return false; // Disable example feature
 *     }
 *     return $enabled;
 * }, 10, 2 );
 * ```
 *
 * ## Creating Your Own Feature
 *
 * To create a new feature based on this example:
 *
 * 1. Create a new directory in `features/`
 * 2. Create a class that extends `Abstract_Feature`
 * 3. Implement the `register()` method
 * 4. Optionally implement `Conditional_Feature` for requirement checking
 * 5. Register your feature in `includes/Feature_Registry.php`
 *
 * ### Example Template
 *
 * ```php
 * <?php
 * namespace WordPress\AI\Features\My_Feature;
 *
 * use WordPress\AI\Abstracts\Abstract_Feature;
 *
 * class My_Feature extends Abstract_Feature {
 *     public function __construct() {
 *         $this->id          = 'my-feature';
 *         $this->label       = __( 'My Feature', 'ai' );
 *         $this->description = __( 'Description of my feature.', 'ai' );
 *     }
 *
 *     public function register(): void {
 *         // Add your hooks here
 *         $this->add_action( 'init', array( $this, 'initialize' ) );
 *     }
 *
 *     public function initialize(): void {
 *         // Feature initialization logic
 *     }
 * }
 * ```
 *
 * ## Architecture
 *
 * The feature system uses:
 *
 * - **Abstract_Feature**: Provides common functionality (hook tracking, enable/disable state)
 * - **Feature Interface**: Defines the contract all features must follow
 * - **Conditional_Feature Interface**: Optional interface for features with system requirements
 * - **Feature_Registry**: Central registry managing all features
 *
 * ## Hook Tracking
 *
 * All hooks registered via `add_action()` and `add_filter()` are automatically tracked and can be retrieved:
 *
 * ```php
 * $feature = $registry->get_feature( 'example-feature' );
 * $hooks = $feature->get_hooks();
 * // Returns array of registered hooks with type, name, and priority
 * ```
 *
 * ## Testing
 *
 * When writing tests for your feature:
 *
 * ```php
 * public function test_feature_registration() {
 *     $feature = new Example_Feature();
 *
 *     $this->assertEquals( 'example-feature', $feature->get_id() );
 *     $this->assertEquals( 'Example Feature', $feature->get_label() );
 *     $this->assertTrue( $feature->is_enabled() );
 * }
 *
 * public function test_feature_hooks() {
 *     $feature = new Example_Feature();
 *     $feature->register();
 *
 *     $hooks = $feature->get_hooks();
 *     $this->assertNotEmpty( $hooks );
 * }
 * ```
 *
 * @since 0.1.0
 */
class Example_Feature extends Abstract_Feature implements Conditional_Feature {
	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->id          = 'example-feature';
		$this->label       = __( 'Example Feature', 'ai' );
		$this->description = __( 'Demonstrates the AI feature system with example hooks and functionality.', 'ai' );
		$this->enabled     = true; // Can be controlled via filter or settings.
	}

	/**
	 * Checks if feature requirements are met.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if requirements are met, false otherwise.
	 */
	public function meets_requirements(): bool {
		// Example: Check if a required plugin is active.
		// For this example, we'll just return true.
		return true;
	}

	/**
	 * Gets requirements message.
	 *
	 * @since 0.1.0
	 *
	 * @return string User-friendly message about requirements.
	 */
	public function get_requirements_message(): string {
		return __( 'This feature has no special requirements.', 'ai' );
	}

	/**
	 * Registers the feature.
	 *
	 * This is where you add actions, filters, and set up functionality.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// Example action: Add content to the footer.
		$this->add_action( 'wp_footer', array( $this, 'add_footer_content' ), 20 );

		// Example filter: Modify the document title.
		$this->add_filter( 'document_title_parts', array( $this, 'modify_title' ), 10, 1 );

		// Example REST API endpoint registration.
		$this->add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Adds example content to the footer.
	 *
	 * @since 0.1.0
	 */
	public function add_footer_content(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		echo '<!-- Example Feature: AI Plugin Active -->';
	}

	/**
	 * Modifies the document title parts.
	 *
	 * @since 0.1.0
	 *
	 * @param array $title Title parts.
	 * @return array Modified title parts.
	 */
	public function modify_title( array $title ): array {
		// Only modify in development mode.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$title['site'] = $title['site'] . ' [AI]';
		}
		return $title;
	}

	/**
	 * Registers REST API route.
	 *
	 * @since 0.1.0
	 */
	public function register_rest_route(): void {
		register_rest_route(
			'ai/v1',
			'/example',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_endpoint_callback' ),
				'permission_callback' => array( $this, 'rest_permission_callback' ),
			)
		);
	}

	/**
	 * REST endpoint callback.
	 *
	 * @since 0.1.0
	 *
	 * @return array Response data.
	 */
	public function rest_endpoint_callback(): array {
		return array(
			'feature_id'  => $this->get_id(),
			'label'       => $this->get_label(),
			'description' => $this->get_description(),
			'enabled'     => $this->is_enabled(),
			'message'     => __( 'Example feature is active!', 'ai' ),
		);
	}

	/**
	 * REST permission callback.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if user can access endpoint.
	 */
	public function rest_permission_callback(): bool {
		return current_user_can( 'manage_options' );
	}
}
