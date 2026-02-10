<?php
/**
 * WebMCP adapter experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\WebMCP;

use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Settings\Settings_Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebMCP adapter experiment.
 *
 * Exposes layered WebMCP tools that discover, inspect, and execute
 * agent-safe WordPress abilities.
 *
 * @since 0.4.0
 */
class WebMCP extends Abstract_Experiment {
	/**
	 * Script handle suffix used by Asset_Loader.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	private const SCRIPT_HANDLE = 'webmcp_adapter';

	/**
	 * Option key suffix for debug panel enablement.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	private const DEBUG_PANEL_OPTION_KEY = 'debug_panel_enabled';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'webmcp-adapter',
			'label'       => __( 'WebMCP Adapter', 'ai' ),
			'description' => __( 'Exposes abilities to in-browser agents via navigator.modelContext with WordPress context-aware filtering.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues and localizes WebMCP adapter assets.
	 *
	 * @since 0.4.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->should_enqueue_for_hook( $hook_suffix ) ) {
			return;
		}

		$this->enqueue_script_modules();

		Asset_Loader::enqueue_script(
			self::SCRIPT_HANDLE,
			'experiments/webmcp-adapter',
			array(
				'dependencies' => $this->get_script_dependencies(),
				'version'      => AI_EXPERIMENTS_VERSION,
			)
		);

		Asset_Loader::localize_script(
			self::SCRIPT_HANDLE,
			'WebMCPAdapterData',
			array(
				'toolNames'         => $this->get_tool_names(),
				'wpContext'         => $this->get_wp_context(),
				'debugPanelEnabled' => $this->is_debug_panel_enabled(),
			)
		);
	}

	/**
	 * Registers custom WebMCP settings.
	 *
	 * @since 0.4.0
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			$this->get_debug_panel_option_name(),
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		add_filter(
			"sanitize_option_{$this->get_enabled_option_name()}",
			array( $this, 'sanitize_enabled_setting' ),
			10,
			3
		);
	}

	/**
	 * Renders custom WebMCP settings fields.
	 *
	 * @since 0.4.0
	 */
	public function render_settings_fields(): void {
		$option_name = $this->get_debug_panel_option_name();
		$option_id   = $option_name;
		$is_enabled  = $this->is_debug_panel_enabled();
		$desc_id     = "ai-experiment-{$this->get_id()}-debug-panel-desc";
		?>
		<div class="ai-experiments__custom-settings">
			<label class="components-toggle-control" for="<?php echo esc_attr( $option_id ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>" value="0" />
				<input
					type="checkbox"
					name="<?php echo esc_attr( $option_name ); ?>"
					id="<?php echo esc_attr( $option_id ); ?>"
					value="1"
					<?php checked( $is_enabled ); ?>
					aria-describedby="<?php echo esc_attr( $desc_id ); ?>"
				/>
				<span><?php esc_html_e( 'Enable WebMCP debug panel', 'ai' ); ?></span>
			</label>
			<p class="description" id="<?php echo esc_attr( $desc_id ); ?>">
				<?php esc_html_e( 'Displays a client-side debug panel for WebMCP tool registration and test invocations. You can override this in the console with window.aiWebMCPDebug = true/false.', 'ai' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 */
	public function has_settings(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 */
	public function is_available(): bool {
		$is_available = $this->has_abilities_api_support();

		/**
		 * Filters whether the WebMCP Adapter is available for enablement.
		 *
		 * @since 0.4.0
		 *
		 * @param bool $is_available True when dependencies are available.
		 */
		return (bool) apply_filters( 'ai_webmcp_adapter_is_available', $is_available );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 */
	public function get_unavailable_reason(): string {
		if ( $this->is_available() ) {
			return '';
		}

		return __( 'Requires the WordPress Abilities API (currently provided by the Gutenberg plugin). Install and activate Gutenberg to enable this experiment.', 'ai' );
	}

	/**
	 * Sanitizes the experiment enabled toggle.
	 *
	 * Prevents enabling the experiment when required dependencies are missing.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed  $value          Sanitized option value.
	 * @param string $option         Option name.
	 * @param mixed  $original_value Original submitted value.
	 * @return bool True when enabled value is allowed, false otherwise.
	 */
	public function sanitize_enabled_setting( $value, string $option, $original_value ): bool {
		if ( is_bool( $value ) ) {
			$enabled = $value;
		} elseif ( is_scalar( $value ) ) {
			$enabled = rest_sanitize_boolean( (string) $value );
		} else {
			$enabled = false;
		}

		if ( ! $enabled || $this->is_available() ) {
			return $enabled;
		}

		add_settings_error(
			Settings_Registration::OPTION_GROUP,
			"{$option}_unavailable",
			$this->get_unavailable_reason(),
			'error'
		);

		return false;
	}

	/**
	 * Returns whether the adapter should load on the current admin hook.
	 *
	 * @since 0.4.0
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 * @return bool True when the hook should load the adapter.
	 */
	private function should_enqueue_for_hook( string $hook_suffix ): bool {
		$default_hooks = array(
			'post.php',
			'post-new.php',
			'site-editor.php',
			'appearance_page_gutenberg-edit-site',
			'admin_page_gutenberg-edit-site',
			'appearance_page_site-editor-v2',
		);

		/**
		 * Filters admin hooks where the WebMCP adapter is enqueued.
		 *
		 * @since 0.4.0
		 *
		 * @param array<string> $allowed_hooks List of allowed hook suffixes.
		 * @param string        $hook_suffix   Current admin hook suffix.
		 */
		$allowed_hooks = apply_filters( 'ai_webmcp_adapter_allowed_hooks', $default_hooks, $hook_suffix );

		return in_array( $hook_suffix, $allowed_hooks, true );
	}

	/**
	 * Returns script dependencies that are currently registered.
	 *
	 * @since 0.4.0
	 *
	 * @return array<string> Script dependency handles.
	 */
	private function get_script_dependencies(): array {
		$candidate_handles = array(
			'wp-hooks',
			'wp-abilities',
			'wp-core-abilities',
		);

		$dependencies = array();

		foreach ( $candidate_handles as $handle ) {
			if ( ! wp_script_is( $handle, 'registered' ) ) {
				continue;
			}

			$dependencies[] = $handle;
		}

		return $dependencies;
	}

	/**
	 * Enqueues script modules used to initialize and expose abilities in modern Gutenberg environments.
	 *
	 * @since 0.4.0
	 */
	private function enqueue_script_modules(): void {
		if ( ! function_exists( 'wp_enqueue_script_module' ) || ! function_exists( 'wp_script_modules' ) ) {
			return;
		}

		$script_modules = wp_script_modules();
		if ( ! method_exists( $script_modules, 'is_registered' ) ) {
			return;
		}

		$candidate_module_ids = array(
			'@wordpress/core-abilities',
			'@wordpress/abilities',
		);

		foreach ( $candidate_module_ids as $module_id ) {
			if ( ! $script_modules->is_registered( $module_id ) ) {
				continue;
			}

			wp_enqueue_script_module( $module_id );
		}
	}

	/**
	 * Returns WebMCP tool names, filterable by developers.
	 *
	 * @since 0.4.0
	 *
	 * @return array{discover: string, info: string, execute: string} Tool names.
	 */
	private function get_tool_names(): array {
		$tool_names = array(
			'discover' => 'wp-discover-abilities',
			'info'     => 'wp-get-ability-info',
			'execute'  => 'wp-execute-ability',
		);

		/**
		 * Filters WebMCP layered tool names.
		 *
		 * @since 0.4.0
		 *
		 * @param array<string, string> $tool_names Tool name map.
		 */
		$tool_names = apply_filters( 'ai_webmcp_adapter_tool_names', $tool_names );

		$defaults = array(
			'discover' => 'wp-discover-abilities',
			'info'     => 'wp-get-ability-info',
			'execute'  => 'wp-execute-ability',
		);

		foreach ( $defaults as $key => $default_value ) {
			$candidate        = $tool_names[ $key ] ?? '';
			$defaults[ $key ] = is_string( $candidate ) && '' !== $candidate ? $candidate : $default_value;
		}

		return $defaults;
	}

	/**
	 * Returns current WordPress context for WebMCP filtering.
	 *
	 * @since 0.4.0
	 *
	 * @return array{
	 *   screen: string,
	 *   adminPage: string,
	 *   postType: string,
	 *   query: array<string, string>
	 * } Context payload.
	 */
	private function get_wp_context(): array {
		global $admin_page;
		global $pagenow;

		$screen         = get_current_screen();
		$raw_query_vars = wp_unslash(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context hinting for client-side filtering.
			$_GET
		);
		$query_vars = is_array( $raw_query_vars ) ? $raw_query_vars : array();

		return array(
			'screen'    => is_string( $pagenow ) ? $pagenow : '',
			'adminPage' => is_string( $admin_page ) ? $admin_page : '',
			'postType'  => isset( $screen->post_type ) && is_string( $screen->post_type ) ? $screen->post_type : '',
			'query'     => $this->sanitize_query_vars( $query_vars ),
		);
	}

	/**
	 * Sanitizes query vars for safe client-side context usage.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string, mixed> $query_vars Raw query vars.
	 * @return array<string, string> Sanitized query vars.
	 */
	private function sanitize_query_vars( array $query_vars ): array {
		$sanitized = array();

		foreach ( $query_vars as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$sanitized_key = sanitize_key( $key );

			if ( '' === $sanitized_key ) {
				continue;
			}

			$sanitized[ $sanitized_key ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Returns the debug panel option name.
	 *
	 * @since 0.4.0
	 *
	 * @return string Debug panel option name.
	 */
	private function get_debug_panel_option_name(): string {
		return $this->get_field_option_name( self::DEBUG_PANEL_OPTION_KEY );
	}

	/**
	 * Returns the experiment enabled option name.
	 *
	 * @since 0.4.0
	 *
	 * @return string Enabled option name.
	 */
	private function get_enabled_option_name(): string {
		return "ai_experiment_{$this->get_id()}_enabled";
	}

	/**
	 * Returns whether the debug panel is enabled by settings.
	 *
	 * @since 0.4.0
	 *
	 * @return bool True when enabled.
	 */
	private function is_debug_panel_enabled(): bool {
		return (bool) get_option( $this->get_debug_panel_option_name(), false );
	}

	/**
	 * Checks whether required Abilities API functions are available.
	 *
	 * @since 0.4.0
	 *
	 * @return bool True when WebMCP can rely on the Abilities API.
	 */
	private function has_abilities_api_support(): bool {
		return function_exists( 'wp_get_abilities' ) && function_exists( 'wp_register_ability' );
	}
}
