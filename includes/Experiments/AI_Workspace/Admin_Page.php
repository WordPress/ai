<?php
/**
 * Admin page hosting the AI Workspace app shell.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use Throwable;
use WordPress\AI\Asset_Loader;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

use function WordPress\AI\get_ai_connectors;
use function WordPress\AI\has_connector_authentication;
use function WordPress\AI\has_valid_ai_credentials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the full-screen AI Workspace admin screen.
 *
 * The screen is capability gated on every request: once when the menu entry is
 * built, once when WordPress dispatches the page, and once again in the render
 * callback so that a direct call can never emit the app shell or its localized
 * data to a user who lacks the capability.
 *
 * @since x.x.x
 */
final class Admin_Page {

	/**
	 * Menu slug used by the admin page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'ai-workspace';

	/**
	 * Parent menu used to anchor this page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PARENT_SLUG = 'tools.php';

	/**
	 * Script and style handle, without the Asset_Loader prefix.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ASSET_HANDLE = 'workspace';

	/**
	 * Built asset path, relative to the build directory and without extension.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ASSET_PATH = 'experiments/ai-workspace';

	/**
	 * Registers the admin menu entry.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
	}

	/**
	 * Returns the absolute admin URL for this page.
	 *
	 * @since x.x.x
	 *
	 * @return string The admin URL for the workspace screen.
	 */
	public static function url(): string {
		return admin_url( self::PARENT_SLUG . '?page=' . self::PAGE_SLUG );
	}

	/**
	 * Adds the submenu entry under Tools.
	 *
	 * @since x.x.x
	 */
	public function add_submenu(): void {
		$page_hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'AI Workspace', 'ai' ),
			__( 'AI Workspace', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		if ( ! $page_hook ) {
			return;
		}

		add_action( "load-{$page_hook}", array( $this, 'on_load' ) );
	}

	/**
	 * Hooks the screen-specific behaviour once WordPress dispatches this page.
	 *
	 * @since x.x.x
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'add_body_class' ) );
	}

	/**
	 * Applies the full-screen admin body class on this screen only.
	 *
	 * @since x.x.x
	 *
	 * @param string $classes Space-separated list of admin body classes.
	 * @return string The filtered list of admin body classes.
	 */
	public function add_body_class( string $classes ): string {
		return trim( $classes . ' is-fullscreen-mode' );
	}

	/**
	 * Enqueues the workspace bundle and passes its localized data.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Asset_Loader::enqueue_script(
			self::ASSET_HANDLE,
			self::ASSET_PATH,
			array( 'include_core_abilities' => true )
		);

		Asset_Loader::localize_script(
			self::ASSET_HANDLE,
			'Workspace',
			array(
				'rest'         => array(
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'root'   => esc_url_raw( rest_url() ),
					'routes' => array(
						'messages'  => 'ai/v1/workspace/messages',
						'proposals' => 'ai/v1/workspace/proposals',
					),
				),
				'availability' => $this->get_availability(),
				'settingsUrl'  => admin_url( 'options-general.php?page=ai-wp-admin' ),
			)
		);
	}

	/**
	 * Outputs the root DOM node the React application mounts into.
	 *
	 * @since x.x.x
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai' ) );
		}

		echo '<div class="wrap ai-workspace">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'AI Workspace', 'ai' ) . '</h1>';
		echo '<div id="ai-workspace-root"></div>';
		echo '</div>';
	}

	/**
	 * Describes whether the workspace can operate, and why not when it cannot.
	 *
	 * @since x.x.x
	 *
	 * @return array{status: string} The workspace availability status.
	 */
	private function get_availability(): array {
		if ( ! has_valid_ai_credentials() ) {
			return array( 'status' => 'no-credentials' );
		}

		if ( ! $this->has_function_calling_support() ) {
			return array( 'status' => 'no-function-calling' );
		}

		return array( 'status' => 'ready' );
	}

	/**
	 * Checks whether any configured connector exposes a function-calling-capable model.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if at least one authenticated connector supports function declarations.
	 */
	private function has_function_calling_support(): bool {
		$connectors  = array();
		$has_support = false;

		if ( class_exists( AiClient::class ) ) {
			$registry   = AiClient::defaultRegistry();
			$connectors = get_ai_connectors();

			foreach ( array_keys( $connectors ) as $connector_id ) {
				if ( ! has_connector_authentication( $connector_id ) ) {
					continue;
				}

				try {
					$provider_class = $registry->getProviderClassName( $connector_id );

					/** @var \WordPress\AiClient\Providers\Contracts\ProviderInterface $provider_class */
					$models = $provider_class::modelMetadataDirectory()->listModelMetadata();

					foreach ( $models as $model ) {
						foreach ( $model->getSupportedOptions() as $option ) {
							if ( OptionEnum::FUNCTION_DECLARATIONS === $option->getName()->value ) {
								$has_support = true;
								break 3;
							}
						}
					}
				} catch ( Throwable $e ) {
					continue;
				}
			}
		}

		/**
		 * Filters whether a function-calling-capable model is available.
		 *
		 * Allows third-party plugins to declare function-calling support for
		 * connectors that do not expose model metadata, without triggering a
		 * live API request.
		 *
		 * @since x.x.x
		 *
		 * @param bool  $has_support Whether function calling is supported.
		 * @param array $connectors  The registered connectors.
		 */
		return (bool) apply_filters( 'wpai_has_function_calling_support', $has_support, $connectors );
	}
}
