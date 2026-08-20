<?php
/**
 * REST API field exposing the agent flag on user responses.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adds a read-only `wpai_is_agent` field to REST user responses.
 *
 * Clients such as editors, collaboration features, and admin UIs can use the
 * field to render an agent badge or to exclude agents from their own picker
 * UIs. The field is deliberately read-only: accounts become agents only
 * through deliberate provisioning, not through a REST write.
 *
 * @since x.x.x
 */
final class REST_Field {
	/**
	 * Field name on the user resource.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const FIELD_NAME = 'wpai_is_agent';

	/**
	 * Registers the field registration hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_field' ) );
	}

	/**
	 * Registers the field on the user resource.
	 *
	 * @since x.x.x
	 */
	public function register_field(): void {
		register_rest_field(
			'user',
			self::FIELD_NAME,
			array(
				'get_callback' => static function ( array $user_data ): bool {
					return Agent_Account::is_agent( (int) ( $user_data['id'] ?? 0 ) );
				},
				'schema'       => array(
					'description' => __( 'Whether this account is an AI agent.', 'ai' ),
					'type'        => 'boolean',
					'context'     => array( 'embed', 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}
}
