<?php
/**
 * Service Account experiment implementation.
 *
 * Provides a service account type for automated tools (e.g., Claude Code, automation bots).
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Service_Account;

use WordPress\AI\Abstracts\Abstract_Experiment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service Account experiment for service account functionality.
 *
 * This experiment introduces a new user classification for service accounts
 * that are more limited than regular users and intended for automated tools,
 * AI assistants, and other programmatic access to WordPress.
 *
 * The implementation is structured for future inclusion in WordPress Core:
 * - Service_Account_Manager: Core logic (portable to Core)
 * - REST_Service_Accounts_Controller: REST API (follows Core patterns)
 * - Admin_UI: Admin interface (portable to Core)
 *
 * @since 0.3.0
 */
class Service_Account extends Abstract_Experiment {
	/**
	 * The service account role slug.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	public const ROLE = Service_Account_Manager::ROLE;

	/**
	 * The service account meta key identifier.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	public const META_KEY = Service_Account_Manager::META_KEY;

	/**
	 * The manager instance.
	 *
	 * @since 0.3.0
	 * @var Service_Account_Manager
	 */
	protected Service_Account_Manager $manager;

	/**
	 * The admin UI instance.
	 *
	 * @since 0.3.0
	 * @var Admin_UI
	 */
	protected Admin_UI $admin_ui;

	/**
	 * The REST controller instance.
	 *
	 * @since 0.3.0
	 * @var REST_Service_Accounts_Controller
	 */
	protected REST_Service_Accounts_Controller $rest_controller;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'service-account',
			'label'       => __( 'Service Accounts', 'ai' ),
			'description' => __( 'Adds service accounts for automated tools like Claude Code and other automation bots.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
	 */
	public function register(): void {
		// Initialize the manager (core functionality).
		$this->manager = Service_Account_Manager::get_instance();
		$this->manager->init();

		// Initialize admin UI.
		if ( is_admin() ) {
			$this->admin_ui = new Admin_UI();
			$this->admin_ui->init();
		}

		// Register REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		/**
		 * Fires after the Service Account experiment is registered.
		 *
		 * @since 0.3.0
		 *
		 * @param self $experiment The experiment instance.
		 */
		do_action( 'service_account_experiment_registered', $this );
	}

	/**
	 * Registers REST API routes.
	 *
	 * @since 0.3.0
	 */
	public function register_rest_routes(): void {
		$this->rest_controller = new REST_Service_Accounts_Controller();
		$this->rest_controller->register_routes();
	}

	/**
	 * Gets the manager instance.
	 *
	 * @since 0.3.0
	 *
	 * @return Service_Account_Manager The manager instance.
	 */
	public function get_manager(): Service_Account_Manager {
		return $this->manager;
	}

	/**
	 * Checks if a user is a service account.
	 *
	 * Convenience method that delegates to the manager.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_User|int $user User object or ID.
	 * @return bool True if the user is a service account.
	 */
	public function is_service_account( $user ): bool {
		return $this->manager->is_service_account( $user );
	}

	/**
	 * Creates a new service account.
	 *
	 * Convenience method that delegates to the manager.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $args User data arguments.
	 * @return \WP_User|\WP_Error The created user object or error.
	 */
	public function create_service_account( array $args ) {
		return $this->manager->create_service_account( $args );
	}

	/**
	 * Deletes a service account.
	 *
	 * Convenience method that delegates to the manager.
	 *
	 * @since 0.3.0
	 *
	 * @param int      $user_id  The user ID to delete.
	 * @param int|null $reassign Optional. User ID to reassign content to.
	 * @return bool|\WP_Error True on success, error on failure.
	 */
	public function delete_service_account( int $user_id, ?int $reassign = null ) {
		return $this->manager->delete_service_account( $user_id, $reassign );
	}

	/**
	 * Gets all service accounts.
	 *
	 * Convenience method that delegates to the manager.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $args Optional. Query arguments.
	 * @return array<\WP_User> Array of service account objects.
	 */
	public function get_service_accounts( array $args = array() ): array {
		return $this->manager->get_service_accounts( $args );
	}
}
