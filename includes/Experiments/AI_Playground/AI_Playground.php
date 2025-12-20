<?php
/**
 * AI Playground experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Playground;

use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;
use WordPress\AI_Client\Capabilities\Capabilities_Manager;

/**
 * AI Playground experiment.
 *
 * @since n.e.x.t
 */
class AI_Playground extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since n.e.x.t
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'ai-playground',
			'label'       => __( 'AI Playground', 'ai' ),
			'description' => __( 'Adds a playground UI to explore prompting AI models directly', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since n.e.x.t
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_menu', array( $this, 'add_playground_screen' ) );
	}

	/**
	 * Registers the REST routes for the AI Playground.
	 *
	 * @since n.e.x.t
	 */
	public function register_rest_routes(): void {
		$messages_controller = new AI_Playground_Messages_REST_Controller();
		$messages_controller->register_routes();
	}

	/**
	 * Adds the AI Playground admin screen.
	 *
	 * @since n.e.x.t
	 */
	public function add_playground_screen(): void {
		$hook_suffix = add_management_page(
			__( 'AI Playground', 'ai' ),
			__( 'AI Playground', 'ai' ),
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined
			Capabilities_Manager::PROMPT_AI_CAPABILITY,
			'ai-playground',
			array( $this, 'render_playground_screen' )
		);

		add_action( "load-$hook_suffix", array( $this, 'load_playground_screen' ) );
	}

	/**
	 * Loads the AI Playground admin screen.
	 *
	 * @since n.e.x.t
	 */
	public function load_playground_screen(): void {
		add_filter(
			'admin_body_class',
			static function ( $classes ) {
				return "$classes remove-screen-spacing";
			}
		);

		add_action(
			'admin_notices',
			static function () {
				remove_all_actions( 'admin_notices' );
			},
			-9999
		);

		add_action(
			'admin_enqueue_scripts',
			function (): void {
				// Enqueue the WordPress AI Client.
				wp_enqueue_script( 'wp-ai-client' );

				// Enqueue the media library.
				wp_enqueue_media();

				// Enqueue foundational stylesheets for the UI.
				wp_enqueue_style(
					'ai_wp-interface',
					AI_EXPERIMENTS_PLUGIN_URL . 'build/external/wp-interface/style.css',
					array( 'wp-components', 'wp-editor' ),
					'1.0.0'
				);
				wp_style_add_data( 'ai_wp-interface', 'rtl', 'replace' );
				wp_enqueue_style(
					'ai_wp-admin-components',
					AI_EXPERIMENTS_PLUGIN_URL . 'build/external/wp-admin-components/style.css',
					array( 'wp-components' ),
					'1.0.0'
				);
				wp_style_add_data( 'ai_wp-admin-components', 'rtl', 'replace' );

				// Enqueue AI Playground assets.
				Asset_Loader::enqueue_script( 'playground', 'experiments/ai-playground' );
				Asset_Loader::enqueue_style( 'playground', 'experiments/style-ai-playground' );

				// Preload REST API data.
				$this->preload_rest_api_data();
			}
		);
	}

	/**
	 * Renders the AI Playground admin screen.
	 *
	 * @since n.e.x.t
	 */
	public function render_playground_screen(): void {
		?>
		<div id="ai-playground-root" class="wrap">
			<?php esc_html_e( 'Loading…', 'ai' ); ?>
		</div>
		<?php
	}

	/**
	 * Preloads REST API data for the AI Playground.
	 *
	 * This avoids an extra round-trip when the admin screen is loaded.
	 *
	 * @since n.e.x.t
	 */
	private function preload_rest_api_data(): void {
		$preload_paths = array(
			'/ai/v1/playground-messages',
		);

		$preload_data = array_reduce(
			$preload_paths,
			'rest_preload_api_request',
			array()
		);

		wp_add_inline_script(
			'wp-api-fetch',
			sprintf(
				'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );',
				wp_json_encode( $preload_data )
			),
			'after'
		);
	}
}
