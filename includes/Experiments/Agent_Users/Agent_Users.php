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
 * Gives external AI agents their own auditable identity as agent accounts.
 *
 * Today an external agent (an MCP client, a coding agent, a scheduled job)
 * can only borrow a human user's credentials. This experiment adds a way to
 * provision dedicated agent accounts instead: regular users marked as
 * agents, with the role system as their capability ceiling, no interactive
 * login, and a few capabilities blocked because their defaults are written
 * for humans.
 *
 * Scope is deliberately identity only. Assistants working inside a
 * logged-in user's session are out of scope: they run as that user, exactly
 * as today, so every execution has one principal. Audit trails and
 * attribution surfaces build on this in a follow-up.
 *
 * See https://github.com/WordPress/ai/issues/923 for the proposal and
 * discussion this experiment validates.
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
			'description' => __( 'Provision dedicated accounts for external AI agents, such as MCP clients and scheduled jobs. Agent accounts make an agent’s work attributable and revocable without touching a human account: they use existing roles as their capability ceiling, cannot log in interactively, and authenticate with Application Passwords. Note this is an experimental proof of concept exploring agent identity for WordPress. Feedback welcome and desired to help shape the feature.', 'ai' ),
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
