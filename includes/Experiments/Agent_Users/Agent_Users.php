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
			'description' => __( 'Give external agents dedicated, independently revocable WordPress accounts. Each agent acts on behalf of a parent user and can never exceed their permissions. Agents use existing roles, authenticate with Application Passwords instead of interactive login, and follow core user membership rules on multisite.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new REST_Field() )->register();
		add_filter( 'pre_get_avatar_data', array( $this, 'use_agent_avatar' ), 10, 2 );

		if ( ! is_admin() ) {
			return;
		}

		( new Profile_Screen() )->register();
		( new Users_Screen() )->register();

		if ( ! Agent_Account::can_enforce_network_safeguards() ) {
			add_action( 'admin_notices', array( $this, 'render_network_activation_notice' ) );
			return;
		}

		( new New_User_Screen( new Agent_Account() ) )->register();
	}

	/**
	 * Makes the plugin's flask the default avatar for agents.
	 *
	 * Without a Gravatar, an agent would get the site's default avatar, so it
	 * would look like any other person next to its agent label. The flask is
	 * Gravatar's fallback instead, so an agent whose email has a Gravatar keeps
	 * it. Gravatar only falls back to public raster images, so the flask is a
	 * PNG and needs a publicly reachable site; elsewhere Gravatar shows its own
	 * default. An avatar URL set by an earlier filter is kept.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args        Avatar data arguments.
	 * @param mixed                $id_or_email User ID, email, or a user, post, or comment object.
	 * @return array<string, mixed> Avatar data arguments.
	 */
	public function use_agent_avatar( array $args, $id_or_email ): array {
		if ( isset( $args['url'] ) || ! Agent_Account::is_agent( self::avatar_user_id( $id_or_email ) ) ) {
			return $args;
		}

		$args['default'] = plugins_url( 'assets/images/agent-avatar.png', WPAI_PLUGIN_FILE );

		return $args;
	}

	/**
	 * Resolves the user behind an avatar request, the way `get_avatar_data()` does.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $id_or_email User ID, email, or a user, post, or comment object.
	 * @return int User ID, or 0 when the request is not for a registered user.
	 */
	private static function avatar_user_id( $id_or_email ): int {
		if ( is_numeric( $id_or_email ) ) {
			return absint( $id_or_email );
		}

		if ( $id_or_email instanceof \WP_User ) {
			return $id_or_email->ID;
		}

		if ( $id_or_email instanceof \WP_Post ) {
			return (int) $id_or_email->post_author;
		}

		// Core accepts any comment-like object, and gives pingbacks and trackbacks no avatar.
		if ( is_object( $id_or_email ) && isset( $id_or_email->comment_ID ) ) {
			$comment = get_comment( (int) $id_or_email->comment_ID );
			if ( ! $comment instanceof \WP_Comment || ! is_avatar_comment_type( get_comment_type( $comment ) ) ) {
				return 0;
			}

			return (int) $comment->user_id;
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user instanceof \WP_User ? $user->ID : 0;
		}

		return 0;
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
		if ( Agent_Account::can_enforce_network_safeguards() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_admin_notice(
			esc_html__( 'Agent Users cannot create accounts on multisite until the AI plugin is network-activated, so every site enforces the agent login and password-reset safeguards. Ask a network administrator to activate it. WordPress limits user management on multisite to network administrators, and creating agents follows the same rule.', 'ai' ),
			array(
				'type'        => 'warning',
				'dismissible' => false,
			)
		);
	}
}
