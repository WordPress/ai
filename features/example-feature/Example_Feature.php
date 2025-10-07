<?php
/**
 * Example Feature implementation.
 *
 * @package WordPress\AI\Features
 */

namespace WordPress\AI\Features\Example_Feature;

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
