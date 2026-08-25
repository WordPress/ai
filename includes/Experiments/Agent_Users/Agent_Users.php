<?php
/**
 * Agent Users experiment.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adds dedicated WordPress user identities for external agents.
 *
 * A dedicated account makes agent activity attributable and independently
 * revocable while continuing to use core roles, content ownership, and user
 * management surfaces. This experiment covers identity; richer audit and
 * provenance features remain separate concerns.
 *
 * @since x.x.x
 */
class Agent_Users extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'agent-users';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Agent Users', 'ai' ),
			'description' => __( 'Give external agents dedicated, independently revocable WordPress accounts. Agents use existing roles, authenticate with Application Passwords instead of interactive login, and are restricted to one site on multisite.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new REST_Field() )->register();

		if ( ! is_admin() ) {
			return;
		}

		( new Profile_Screen() )->register();
		( new Users_Screen() )->register();

		if ( ! Agent_Account::can_enforce_site_binding() ) {
			add_action( 'admin_notices', array( $this, 'render_network_activation_notice' ) );
			return;
		}

		( new New_User_Screen( new Agent_Account() ) )->register();
	}

	/**
	 * Explains why agent provisioning is unavailable on a per-site activation.
	 *
	 * Existing-agent management remains available so administrators can inspect
	 * accounts and revoke their credentials while network activation is arranged.
	 *
	 * @since x.x.x
	 */
	public function render_network_activation_notice(): void {
		if ( Agent_Account::can_enforce_site_binding() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_admin_notice(
			esc_html__( 'Agent Users cannot create accounts on multisite until the AI plugin is network-activated. Ask a network administrator to network-activate the plugin so every site enforces the agent authentication boundary.', 'ai' ),
			array(
				'type'        => 'warning',
				'dismissible' => false,
			)
		);
	}
}
