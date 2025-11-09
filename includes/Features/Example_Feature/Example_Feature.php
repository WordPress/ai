<?php
/**
 * Example feature implementation.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Features\Example_Feature;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Section;
use WordPress\AI\Admin\Settings\Settings_Toggle;
use WordPress\AI\Features\Traits\Provides_Settings_Section;

/**
 * Reference feature demonstrating hooks and REST endpoints.
 *
 * @since 0.1.0
 */
class Example_Feature extends Abstract_Feature {
	use Provides_Settings_Section;

	/**
	 * Loads feature metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	protected function load_feature_metadata(): array {
		return array(
			'id'          => 'example-feature',
			'label'       => __( 'Example Feature', 'ai' ),
			'description' => __( 'Demonstrates the AI feature system with example hooks and functionality.', 'ai' ),
		);
	}

	/**
	 * Registers hooks that must always run.
	 *
	 * @since 0.1.0
	 */
	protected function register_shared_hooks(): void {
		// Always register settings sections so the feature appears in admin.
		add_action(
			'ai_register_settings_sections',
			array( $this, 'register_settings_sections' )
		);
	}

	/**
	 * Registers hooks that run only when the feature is enabled.
	 *
	 * @since 0.1.0
	 */
	protected function register_enabled_hooks(): void {
		add_action( 'wp_footer', array( $this, 'add_footer_content' ), 20 );
		add_filter( 'document_title_parts', array( $this, 'modify_title' ), 10, 1 );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Registers the example settings section with the admin registry.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Registry $registry Registry instance.
	 */
	public function register_settings_sections( Settings_Registry $registry ): void {
		if ( $registry->has_section( 'example-feature' ) ) {
			return;
		}

		$this->register_feature_settings_section(
			$registry,
			'example-feature',
			__( 'Example Feature', 'ai' ),
			array( $this, 'render_settings_section' ),
			array(
				'description' => __( 'Demonstration controls rendered by the Example Feature.', 'ai' ),
				'priority'    => 20,
			)
		);
	}

	/**
	 * Renders the example settings panel.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Toggle  $toggle  Toggle service.
	 * @param \WordPress\AI\Admin\Settings\Settings_Section $section Section metadata.
	 */
	public function render_settings_section( Settings_Toggle $toggle, Settings_Section $section ): void {
		unset( $toggle, $section );
		?>
		<p>
			<?php esc_html_e( 'Example Feature does not expose additional controls yet. This section demonstrates registration via the Provides_Settings_Section trait.', 'ai' ); ?>
		</p>
		<?php
	}

	/**
	 * Adds example content to the footer for logged-in users.
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
	 * Modifies the document title parts when debugging.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $title Title parts.
	 * @return array<string, string>
	 */
	public function modify_title( array $title ): array {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( $title['site'] ) ) {
			$title['site'] = $title['site'] . ' [AI]';
		}
		return $title;
	}

	/**
	 * Registers the example REST API route.
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
	 * Callback for the example REST endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
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
	 * Permission check for the REST endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function rest_permission_callback(): bool {
		return current_user_can( 'manage_options' );
	}
}
