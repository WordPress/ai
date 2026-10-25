<?php
/**
 * The main plugin file.
 *
 * @package WordPress\AI
 *
 * @since 0.8.0
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI\Abilities\Meta_Description\SEO_Integration;
use WordPress\AI\Admin\Activation;
use WordPress\AI\Admin\Dashboard\Dashboard_Widgets;
use WordPress\AI\Admin\Deactivation;
use WordPress\AI\Admin\Site_Health;
use WordPress\AI\Admin\Upgrades;
use WordPress\AI\CLI\Embeddings_Command;
use WordPress\AI\Connectors\Featured_Connectors;
use WordPress\AI\Experiments\Agent_Users\Agent_Account;
use WordPress\AI\Experiments\Agent_Users\Agent_Authentication;
use WordPress\AI\Experiments\Agent_Users\Agent_Capabilities;
use WordPress\AI\Experiments\Agent_Users\Agent_Credentials;
use WordPress\AI\Experiments\Agent_Users\Agent_Lifecycle;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WordPress\AI\Settings\Settings_Page;
use WordPress\AI\Settings\Settings_Registration;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Main
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 0.8.0
 */
final class Main {
	/**
	 * Instance of the class.
	 * @since 0.8.0
	 *
	 *
	 * @var ?static
	 */
	private static $instance;

	/**
	 * Agent capability rules, once registered with the other agent safeguards.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Agent_Users\Agent_Capabilities|null
	 */
	private ?Agent_Capabilities $agent_capabilities = null;

	/**
	 * Agent account lifecycle rules, once registered.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Agent_Users\Agent_Lifecycle|null
	 */
	private ?Agent_Lifecycle $agent_lifecycle = null;

	/**
	 * Gets the (singleton) instance of the Main class.
	 *
	 * @since 0.8.0
	 */
	public static function get_instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Setup the plugin.
	 */
	private function setup(): void {
		// Load the plugin classes.
		add_action( 'plugins_loaded', array( $this, 'load' ) );

		// Register activation and deactivation hooks.
		register_activation_hook( WPAI_PLUGIN_FILE, array( Activation::class, 'activation_callback' ) );
		register_deactivation_hook( WPAI_PLUGIN_FILE, array( Deactivation::class, 'deactivation_callback' ) );
	}

	/**
	 * Load the plugin classes.
	 *
	 * @since 0.8.0
	 *
	 * @internal Used in the plugins_loaded action.
	 */
	public function load(): void {
		/*
		 * Agent accounts outlive the experiment toggle, so their identity and
		 * authentication safeguards must remain active for as long as the plugin is
		 * active. Register them before optional plugin requirements are checked;
		 * the experiment itself only controls provisioning and visibility.
		 */
		$this->register_agent_account_safeguards();

		// Check plugin requirements before continuing.
		if ( ! ( new Requirements() )->are_requirements_met() ) {
			return;
		}

		// Include globals
		require_once WPAI_PLUGIN_DIR . 'includes/helpers.php';

		// Handle any pending upgrades.
		( new Upgrades() )->init();

		// Handle deprecated code.
		( new Deprecated() )->init();

		// Keep the detected SEO plugin cache fresh regardless of experiment state.
		SEO_Integration::register_cache_invalidation();

		// List featured Connector plugins on the Connectors screen.
		( new Featured_Connectors() )->init();

		// Add plugin action links to plugins screen.
		add_filter( 'plugin_action_links_' . plugin_basename( WPAI_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );

		// Defer feature initialization to the 'init' action.
		add_action( 'init', array( $this, 'initialize_features' ), 15 );

		// Register provider data globally so it is available to any plugin script.
		add_action( 'init', array( $this, 'register_provider_data' ), 20 );

		// Register the default ability category.
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_ability_category' ) );
	}

	/**
	 * Registers safeguards for every account marked as an agent.
	 *
	 * This intentionally runs outside feature initialization. Agent accounts
	 * remain in the database when the experiment is disabled, so their login,
	 * password-reset, and content-filtering constraints must remain active as
	 * well. The safeguards only register once an agent may exist, and marking
	 * the first agent registers them right away, so they are in force for the
	 * rest of that request too. Until then, any agent that authenticates or
	 * asks for a password reset registers them before it can act. The rules
	 * for removing and deleting accounts always run, since they only run when
	 * an account is removed or deleted.
	 *
	 * @since x.x.x
	 *
	 * @internal Public for integration testing.
	 */
	public function register_agent_account_safeguards(): void {
		Agent_Capabilities::register_parent_capability();
		$this->register_agent_lifecycle_once();
		add_action( 'added_user_meta', array( $this, 'register_safeguards_for_marked_agent' ), 10, 3 );
		add_action( 'updated_user_meta', array( $this, 'register_safeguards_for_marked_agent' ), 10, 3 );

		if ( ! Agent_Account::agents_may_exist() ) {
			/*
			 * An account can still be an agent: marked behind the plugin's back,
			 * such as with WP-CLI's `--skip-plugins`, or by another network of a
			 * multi-network install. Whoever authenticates is checked, which is
			 * a cached meta read, and the safeguards register before the
			 * plugin's own checks run on the same hook.
			 */
			add_action( 'set_current_user', array( $this, 'register_safeguards_for_current_agent' ), 0 );
			add_filter( 'wp_authenticate_user', array( $this, 'register_safeguards_for_authenticating_agent' ), 0 );
			add_action( 'wp_authenticate_application_password_errors', array( $this, 'register_safeguards_for_credential_agent' ), 0, 2 );
			add_filter( 'allow_password_reset', array( $this, 'register_safeguards_for_resetting_agent' ), 0, 2 );

			// Code that ran before the plugin loaded may already have resolved the current user.
			if ( did_action( 'set_current_user' ) ) {
				$this->register_safeguards_for_current_agent();
			}
			return;
		}

		$this->register_agent_safeguards_once();
	}

	/**
	 * Registers the agent safeguards when the current user turns out to be an agent.
	 *
	 * @since x.x.x
	 *
	 * @internal Hooked to `set_current_user` while no agent is known.
	 */
	public function register_safeguards_for_current_agent(): void {
		$this->register_safeguards_if_agent( get_current_user_id() );
	}

	/**
	 * Registers the agent safeguards before a password login by an agent.
	 *
	 * @since x.x.x
	 *
	 * @internal Hooked to `wp_authenticate_user` while no agent is known.
	 *
	 * @param mixed $user The user logging in, or an error, passed through.
	 * @return mixed The user or error.
	 */
	public function register_safeguards_for_authenticating_agent( $user ) {
		if ( $user instanceof \WP_User ) {
			$this->register_safeguards_if_agent( $user->ID );
		}

		return $user;
	}

	/**
	 * Registers the agent safeguards before an agent's Application Password is accepted.
	 *
	 * @since x.x.x
	 *
	 * @internal Hooked to `wp_authenticate_application_password_errors` while no agent is known.
	 *
	 * @param \WP_Error $error Authentication errors, unchanged.
	 * @param \WP_User  $user  The user authenticating.
	 */
	public function register_safeguards_for_credential_agent( $error, $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$this->register_safeguards_if_agent( $user->ID );
	}

	/**
	 * Registers the agent safeguards before an agent's password reset.
	 *
	 * @since x.x.x
	 *
	 * @internal Hooked to `allow_password_reset` while no agent is known.
	 *
	 * @param bool $allow   Whether the reset is allowed, passed through.
	 * @param int  $user_id The user requesting a reset.
	 * @return bool Whether the reset is allowed.
	 */
	public function register_safeguards_for_resetting_agent( $allow, $user_id ) {
		$this->register_safeguards_if_agent( (int) $user_id );

		return $allow;
	}

	/**
	 * Records that an agent exists and registers the safeguards when the user is one.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id User ID.
	 */
	private function register_safeguards_if_agent( int $user_id ): void {
		if ( ! Agent_Account::is_agent( $user_id ) ) {
			return;
		}

		update_site_option( Agent_Account::AGENTS_EXIST_OPTION, '1' );
		$this->register_agent_safeguards_once();
	}

	/**
	 * Registers the agent safeguards as soon as an account is marked as an agent.
	 *
	 * @since x.x.x
	 *
	 * @internal Hooked to `added_user_meta` and `updated_user_meta`.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 */
	public function register_safeguards_for_marked_agent( $meta_id, $user_id, $meta_key ): void {
		if ( Agent_Account::META_KEY !== $meta_key ) {
			return;
		}

		$this->register_safeguards_if_agent( (int) $user_id );
	}

	/**
	 * Registers the agent account lifecycle rules unless they are already in place.
	 *
	 * @since x.x.x
	 */
	private function register_agent_lifecycle_once(): void {
		if ( null !== $this->agent_lifecycle && false !== has_action( 'delete_user', array( $this->agent_lifecycle, 'delete_agents_of_deleted_user' ) ) ) {
			return;
		}

		$this->agent_lifecycle = new Agent_Lifecycle();
		$this->agent_lifecycle->register();
	}

	/**
	 * Registers the agent safeguards unless they are already in place.
	 *
	 * @since x.x.x
	 */
	private function register_agent_safeguards_once(): void {
		if ( null !== $this->agent_capabilities && false !== has_filter( 'map_meta_cap', array( $this->agent_capabilities, 'limit_agents_to_parent' ) ) ) {
			return;
		}

		( new Agent_Authentication() )->register();
		$this->agent_capabilities = new Agent_Capabilities();
		$this->agent_capabilities->register();
		( new Agent_Credentials() )->register();
	}

	/**
	 * Initializes plugin features.
	 *
	 * @since 0.8.0
	 */
	public function initialize_features(): void {
		try {
			// Experiments are hooked into our Loader, so we need to register them first.
			( new Experiments() )->init();

			// The one true registry of all features.
			$registry = new Registry();

			// Initializes all the features.
			( new Loader( $registry ) )->init();

			// Initialize settings registration.
			( new Settings_Registration( $registry ) )->init();

			// Register admin settings page menu item and dashboard widgets.
			if ( is_admin() ) {
				Settings_Page::init( $registry );

				( new Dashboard_Widgets( $registry ) )->init();
			}

			// Register Site Health integration. The `debug_information` and
			// `site_status_tests` filters are only ever consumed from admin
			// screens, the Site Health REST endpoints (which power the async
			// status checks in wp-admin/site-health.php), and the weekly
			// WP-Cron health-check email — never on the public front end.
			if ( is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				( new Site_Health() )->init();
			}

			// Register any needed global WP-CLI commands.
			if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
				return;
			}

			\WP_CLI::add_command( 'ai embeddings', Embeddings_Command::class );
		} catch ( \Throwable $e ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
				/* translators: %s: Error message. */
					esc_html__( 'AI Plugin initialization failed: %s', 'ai' ),
					esc_html( $e->getMessage() )
				),
				'0.8.0'
			);
		}
	}

	/**
	 * Adds action links to the plugin list table.
	 *
	 * This adds "Settings" and "Connectors" links to
	 * the plugin's action links on the Plugins page.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string> Modified action links.
	 */
	public function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			admin_url( 'options-general.php?page=ai-wp-admin' ),
			esc_html__( 'Settings', 'ai' )
		);

		$connectors_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			admin_url( 'options-connectors.php' ),
			esc_html__( 'Connectors', 'ai' )
		);

		array_unshift( $links, $connectors_link, $settings_link );

		return $links;
	}

	/**
	 * Registers provider availability data for frontend scripts.
	 *
	 * @since 1.0.0
	 */
	public function register_provider_data(): void {
		Asset_Loader::add_global_data( 'ProviderData', get_provider_availability_data() );
	}

	/**
	 * Register a generic catch-all category that all Abilities we register can use.
	 *
	 * This can be re-evaluated in the future if we need/want more specific categories.
	 *
	 * @internal Used in the wp_abilities_api_categories_init action.
	 *
	 * @since 0.8.0
	 */
	public function register_ability_category(): void {
		wp_register_ability_category(
			WPAI_DEFAULT_ABILITY_CATEGORY,
			array(
				'label'       => __( 'AI', 'ai' ),
				'description' => __( 'Various AI features and experiments.', 'ai' ),
			),
		);
	}

	/**
	 * Prevent the class from being cloned.
	 *
	 * @since 0.8.0
	 */
	public function __clone() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				// translators: %s: Class name.
				esc_html__( 'The %s class should not be cloned.', 'ai' ),
				esc_html( self::class ),
			),
			'0.8.0'
		);
	}

	/**
	 * Prevent the class from being deserialized.
	 *
	 * @since 0.8.0
	 */
	public function __wakeup() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				// translators: %s: Class name.
				esc_html__( 'De-serializing instances of %s is not allowed.', 'ai' ),
				esc_html( self::class ),
			),
			'0.8.0'
		);
	}
}
