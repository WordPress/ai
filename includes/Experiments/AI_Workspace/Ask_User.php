<?php
/**
 * The `ai/ask-user` WordPress Ability.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the `ai/ask-user` ability, which asks the person a question with choices.
 *
 * The ability does nothing on the server. It hands back the question and the
 * choices it was given, and calling it ends the turn ({@see Turn_Runner}), so
 * the model waits for the answer instead of guessing one. The transcript shows
 * the choices as buttons, and a click sends the chosen text as the person's next
 * message, through the same path as typing it. A typed reply works just as well.
 *
 * Like {@see Propose_Drafts}, it is hidden from the REST and MCP surfaces and
 * refuses outside a workspace turn: a question is only meaningful where the
 * person who answers it is.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Ask_User {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/ask-user';

	/**
	 * Longest question, in characters.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_QUESTION_LENGTH = 200;

	/**
	 * Fewest choices a question may offer.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MIN_CHOICES = 2;

	/**
	 * Most choices a question may offer.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_CHOICES = 5;

	/**
	 * Longest choice, in characters.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_CHOICE_LENGTH = 60;

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Ask the Person', 'ai' ),
				'description'         => sprintf(
					/* translators: 1: the fewest choices, 2: the most choices. */
					__( 'Asks the person a question and offers %1$d to %2$d short answers to choose from. Use it when you need a decision before you can go on, instead of ending with instructions for them to follow. Asking ends your turn: their answer arrives as their next message, and they may type a different answer instead of choosing one.', 'ai' ),
					self::MIN_CHOICES,
					self::MAX_CHOICES
				),
				'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_ask_user' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						'open_world'  => false,
					),
					/*
					 * Withheld from the REST and MCP surfaces on purpose. A
					 * question is only meaningful inside a workspace turn, which
					 * is where the person who has to answer it is.
					 */
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * Permission callback for the ability.
	 *
	 * The same capability the workspace's turn endpoint requires, so the ability
	 * asks for nothing more than being in the workspace already does.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True when the request may proceed.
	 */
	public function check_permission( $input = array() ): bool {
		unset( $input );

		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability.
	 *
	 * The input has already been validated against the schema, so the question
	 * and choices are returned as given.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The question and choices, or an error.
	 */
	public function execute_ask_user( $input = array() ) {
		if ( ! Turn_Context::is_active() ) {
			return new WP_Error(
				'workspace_context_required',
				__( 'A question can only be asked inside an AI Workspace conversation, where a person can answer it.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$input   = rest_sanitize_object( $input );
		$choices = isset( $input['choices'] ) && is_array( $input['choices'] ) ? $input['choices'] : array();

		return array(
			'question' => isset( $input['question'] ) ? (string) $input['question'] : '',
			'choices'  => array_values( array_map( 'strval', $choices ) ),
			'note'     => __( 'Your turn has ended. The person sees the question with the choices as buttons, and their answer arrives as their next message.', 'ai' ),
		);
	}

	/**
	 * Returns the ability's input schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input schema.
	 */
	private function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'question' => array(
					'type'        => 'string',
					'description' => __( 'The question, in one short sentence.', 'ai' ),
					'minLength'   => 1,
					'maxLength'   => self::MAX_QUESTION_LENGTH,
				),
				'choices'  => array(
					'type'        => 'array',
					'description' => __( 'The answers to offer, each a few words the person could have typed themselves.', 'ai' ),
					'minItems'    => self::MIN_CHOICES,
					'maxItems'    => self::MAX_CHOICES,
					'uniqueItems' => true,
					'items'       => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => self::MAX_CHOICE_LENGTH,
					),
				),
			),
			'required'   => array( 'question', 'choices' ),
		);
	}

	/**
	 * Returns the ability's output schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output schema.
	 */
	private function get_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'question' => array( 'type' => 'string' ),
				'choices'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'note'     => array( 'type' => 'string' ),
			),
		);
	}
}
