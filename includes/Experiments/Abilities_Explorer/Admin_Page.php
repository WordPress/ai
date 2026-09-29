<?php
/**
 * Admin Page Class
 *
 * Handles admin menu, pages, and UI rendering.
 *
 * @package WordPress\AI\Experiments\Abilities_Explorer
 * @since 0.2.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Abilities_Explorer;

use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;
use WordPress\AI\Experiments\Abilities_Explorer\REST\Abilities_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Page Class
 *
 * Manages the admin interface for Abilities Explorer.
 *
 * @since 0.2.0
 */
class Admin_Page {

	/**
	 * Menu slug of the Explorer screen.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'ai-abilities-explorer';

	/**
	 * Script and style handle, without the Asset_Loader prefix.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ASSET_HANDLE = 'abilities_explorer';

	/**
	 * Built asset path, relative to the build directory and without extension.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ASSET_PATH = 'experiments/abilities-explorer';

	/**
	 * The `wp_ajax_` action that changes the AI Workspace's tool surface.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const SURFACE_AJAX_ACTION = 'ai_ability_explorer_surface';

	/**
	 * The nonce action guarding a surface change.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const SURFACE_NONCE_ACTION = 'ai_ability_explorer_surface';

	/**
	 * Initialize admin functionality.
	 *
	 * @since 0.2.0
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'wp_ajax_ai_ability_explorer_invoke', array( $this, 'ajax_invoke_ability' ) );
		add_action( 'wp_ajax_' . self::SURFACE_AJAX_ACTION, array( $this, 'ajax_set_surface_membership' ) );
	}

	/**
	 * Add admin menu item.
	 *
	 * @since 0.2.0
	 */
	public function add_admin_menu(): void {
		$hook = add_submenu_page(
			'tools.php',
			__( 'Abilities Explorer', 'ai' ),
			__( 'Abilities Explorer', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( ! $hook ) {
			return;
		}

		add_action( "load-{$hook}", array( $this, 'add_help_tabs' ) );
		add_action( "load-{$hook}", array( $this, 'on_load' ) );
	}

	/**
	 * Hooks the screen's assets once WordPress dispatches the Explorer page.
	 *
	 * @since x.x.x
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues the React bundle and passes its localized settings.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/*
		 * The list renders with DataViews, whose styles ship with the plugin
		 * because `wp-dataviews` is not a registered style on every supported
		 * WordPress version. The bundled copy is used only when WordPress does
		 * not register its own.
		 */
		$dataviews_css = WPAI_PLUGIN_DIR . 'build/admin/dataviews.css';

		if ( ! wp_styles()->query( 'wp-dataviews' ) && file_exists( $dataviews_css ) ) {
			wp_enqueue_style(
				'ai-dataviews',
				WPAI_PLUGIN_URL . 'build/admin/dataviews.css',
				array(),
				(string) filemtime( $dataviews_css )
			);
		}

		Asset_Loader::enqueue_script( self::ASSET_HANDLE, self::ASSET_PATH );
		Asset_Loader::enqueue_style( self::ASSET_HANDLE, self::ASSET_PATH );

		/*
		 * DataViews ships its own UI strings, which WordPress only inlines in
		 * block-editor contexts, so they are loaded explicitly here.
		 */
		wp_set_script_translations( 'wp-dataviews', 'default' );

		Asset_Loader::localize_script(
			self::ASSET_HANDLE,
			'AbilitiesExplorer',
			array(
				'rest'                => array(
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'root'   => esc_url_raw( rest_url() ),
					/*
					 * Every path is sourced from the constant the controller
					 * registers with, so the map cannot drift from the routes.
					 */
					'routes' => array(
						'abilities' => Abilities_Controller::ABILITIES_ROUTE,
						'item'      => Abilities_Controller::ITEM_ROUTE,
						'invoke'    => Abilities_Controller::INVOKE_ROUTE,
						'surface'   => Abilities_Controller::SURFACE_ROUTE,
					),
				),
				'pageSlug'            => self::PAGE_SLUG,
				'surfaceReasonLabels' => self::get_surface_reason_labels(),
				'providerLabels'      => Ability_Handler::get_provider_labels(),
			)
		);
	}

	/**
	 * Returns the translated label for every assistant-surface exclusion reason.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, string> Map of `Tool_Policy::REASON_*` code to label.
	 */
	private static function get_surface_reason_labels(): array {
		$reasons = array(
			Tool_Policy::REASON_WITHHELD,
			Tool_Policy::REASON_NOT_PUBLIC,
			Tool_Policy::REASON_EFFECT_CLASS,
			Tool_Policy::REASON_CAPABILITY,
			Tool_Policy::REASON_FILTERED,
			Tool_Policy::REASON_AWAITING_ENABLE,
			Tool_Policy::REASON_OWNER_EXCLUDED,
			Tool_Policy::REASON_POLICY_OFF,
		);

		$labels = array();

		foreach ( $reasons as $reason ) {
			$labels[ $reason ] = Ability_Handler::get_surface_reason_label( $reason );
		}

		return $labels;
	}

	/**
	 * Outputs the root DOM node the React application mounts into.
	 *
	 * The admin-ui `Page` component provides the screen's single `h1`, so no
	 * heading is printed here.
	 *
	 * @since 0.2.0
	 * @since x.x.x Renders only the React root.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap ability-explorer-wrap"><div id="ai-abilities-explorer-root"></div></div>';
	}

	/**
	 * AJAX handler for invoking abilities.
	 *
	 * @since 0.2.0
	 */
	public function ajax_invoke_ability(): void {
		// Verify nonce.
		check_ajax_referer( 'ai_ability_explorer_invoke', 'nonce' );

		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions.', 'ai' ),
				)
			);
		}

		// Get parameters.
		$ability_slug = isset( $_POST['ability'] ) ? sanitize_text_field( wp_unslash( $_POST['ability'] ) ) : '';
		$input        = isset( $_POST['input'] ) ? json_decode( wp_unslash( $_POST['input'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $ability_slug ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Ability slug is required.', 'ai' ),
				)
			);
		}

		// Get ability to validate.
		$ability = Ability_Handler::get_ability( $ability_slug );

		if ( ! $ability ) {
			wp_send_json_error(
				array(
					'message' => __( 'Ability not found.', 'ai' ),
				)
			);
		}

		// Validate input.
		if ( ! empty( $ability['input_schema'] ) ) {
			$validation = Ability_Handler::validate_input( $ability['input_schema'], $input );

			if ( ! $validation['valid'] ) {
				wp_send_json_error(
					array(
						'message' => __( 'Input validation failed.', 'ai' ),
						'errors'  => $validation['errors'],
					)
				);
			}
		}

		// Invoke the ability.
		$result = Ability_Handler::invoke_ability( $ability_slug, $input );

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message' => __( 'Ability invoked successfully.', 'ai' ),
					'data'    => $result['data'] ?? null,
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => $result['error'] ?? __( 'Unknown error occurred.', 'ai' ),
					'trace'   => $result['trace'] ?? null,
				)
			);
		}
	}

	/**
	 * AJAX handler for changing the AI Workspace's tool surface.
	 *
	 * Guards on the nonce **and** on `manage_options`, the same pairing
	 * {@see self::ajax_invoke_ability()} uses. Either alone is insufficient
	 * here: without the capability check a CSRF riding a logged-in
	 * administrator's session could quietly reshape what the assistant is
	 * allowed to call, and without the nonce a cross-site request could do the
	 * same with no forgery at all.
	 *
	 * @since x.x.x
	 */
	public function ajax_set_surface_membership(): void {
		check_ajax_referer( self::SURFACE_NONCE_ACTION, '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions.', 'ai' ),
				)
			);
		}

		$surface      = isset( $_REQUEST['surface'] ) ? sanitize_key( wp_unslash( $_REQUEST['surface'] ) ) : '';
		$ability_slug = isset( $_REQUEST['ability'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ability'] ) ) : '';

		$policy = new Tool_Policy();

		switch ( $surface ) {
			case 'disable_policy':
			case 'enable_policy':
				$policy->set_policy_disabled( 'disable_policy' === $surface );
				break;

			case 'remove':
			case 'restore':
				// Stored names are validated against the registry on read, so
				// only a name that resolves to an ability is ever written.
				if ( '' === $ability_slug || ! wp_has_ability( $ability_slug ) ) {
					wp_send_json_error(
						array(
							'message' => __( 'Ability not found.', 'ai' ),
						)
					);
				}

				if ( 'remove' === $surface ) {
					$policy->exclude_from_surface( $ability_slug );
				} else {
					$policy->restore_to_surface( $ability_slug );
				}
				break;

			default:
				wp_send_json_error(
					array(
						'message' => __( 'Unknown surface change requested.', 'ai' ),
					)
				);
		}

		$this->redirect_after_surface_change( $surface );
	}

	/**
	 * Returns the owner to the Explorer after a surface change.
	 *
	 * The control that reaches this handler is a plain nonced link, in keeping
	 * with the Explorer's own idiom, so the response has to be a navigation
	 * rather than JSON. `exit` is conditional on the redirect actually being
	 * sent, which is what lets a test drive the handler without terminating the
	 * process.
	 *
	 * @since x.x.x
	 *
	 * @param string $surface The mutation that was applied.
	 */
	private function redirect_after_surface_change( string $surface ): void {
		$referer = wp_get_referer();
		$target  = is_string( $referer ) && '' !== $referer
			? $referer
			: admin_url( 'tools.php?page=ai-abilities-explorer' );

		if ( wp_safe_redirect( add_query_arg( 'wpai_surface_updated', $surface, $target ) ) ) {
			exit;
		}
	}

	/**
	 * Add contextual help tabs to the screen.
	 *
	 * @since 0.4.0
	 */
	public function add_help_tabs(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-overview',
				'title'   => __( 'Overview', 'ai' ),
				'content' =>
					'<p>' . esc_html__( 'Abilities are a standardized way for WordPress core, plugins, and themes to expose discrete units of functionality. Each ability has a name, optional input/output schemas, and can be invoked programmatically.', 'ai' ) . '</p>' .
					'<p>' . esc_html__( 'The Abilities Explorer lets you browse every registered ability, inspect its schemas, and test it with custom input right from the admin.', 'ai' ) . '</p>',
			)
		);

		$provider_tags = array( 'strong' => array() );

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-providers',
				'title'   => esc_html__( 'Providers', 'ai' ),
				'content' =>
					'<p>' . esc_html__( 'Every ability is associated with a provider that indicates where it comes from:', 'ai' ) . '</p>' .
					'<ul>' .
						'<li>' . wp_kses( __( '<strong>Core</strong>: Built into WordPress itself.', 'ai' ), $provider_tags ) . '</li>' .
						'<li>' . wp_kses( __( '<strong>Plugin</strong>: Registered by an active plugin.', 'ai' ), $provider_tags ) . '</li>' .
						'<li>' . wp_kses( __( '<strong>Theme</strong>: Registered by the active theme.', 'ai' ), $provider_tags ) . '</li>' .
					'</ul>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-testing',
				'title'   => esc_html__( 'Testing', 'ai' ),
				'content' =>
					'<p>' . esc_html__( 'You can test any ability directly from this screen:', 'ai' ) . '</p>' .
					'<ol>' .
						'<li>' . __( 'Click "Test" next to an ability in the list.', 'ai' ) . '</li>' .
						'<li>' . __( 'Edit the pre-filled Input Data if the ability accepts JSON parameters.', 'ai' ) . '</li>' .
						'<li>' . __( 'Use "Validate Input" to check your JSON against the schema.', 'ai' ) . '</li>' .
						'<li>' . __( 'Click "Invoke Ability" to execute it and see the result.', 'ai' ) . '</li>' .
					'</ol>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information:', 'ai' ) . '</strong></p>' .
			'<p><a href="https://developer.wordpress.org/apis/abilities/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abilities API Documentation', 'ai' ) . '</a></p>'
		);
	}
}
