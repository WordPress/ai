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
		// Account safeguards register globally in Main so toggles cannot remove them.
		$account = new Agent_Account();

		( new REST_Field() )->register();

		if ( ! is_admin() ) {
			return;
		}

		( new New_User_Screen( $account ) )->register();
		( new Profile_Screen() )->register();
		( new Users_Screen() )->register();
	}
}
