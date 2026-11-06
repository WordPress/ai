<?php
/**
 * The `ai/propose-site-identity` WordPress Ability.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the `ai/propose-site-identity` ability, which proposes a new site title or tagline without writing it.
 *
 * It is {@see Propose_Post_Changes} for the two site identity settings, and it
 * holds to the same rules: it records the exact values in {@see Proposal_Store}
 * and writes nothing, it is hidden from the REST and MCP surfaces, it refuses
 * outside a workspace turn, and the proposal is bound to the conversation read
 * from {@see Turn_Context}. {@see Site_Identity_Writer} validates each item and
 * writes it once a person approves it.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Propose_Site_Identity {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/propose-site-identity';

	/**
	 * The proposal store.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Proposal_Store
	 */
	private Proposal_Store $store;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Proposal_Store|null $store The proposal store.
	 */
	public function __construct( ?Proposal_Store $store = null ) {
		$this->store = null === $store ? new Proposal_Store() : $store;
	}

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
				'label'               => __( 'Propose Site Title or Tagline', 'ai' ),
				'description'         => sprintf(
					/* translators: 1: the maximum site title length, 2: the maximum tagline length. */
					__( 'Records a proposed new site title or tagline, for the person to review. This writes nothing: each item names one setting and its complete new value, the person sees the current value next to yours, may edit yours, and must approve before anything changes. Values are plain text, at most %1$d characters for the title and %2$d for the tagline. Name each setting at most once, and never claim it changed until you are told the outcome.', 'ai' ),
					Site_Identity_Writer::TITLE_MAX_LENGTH,
					Site_Identity_Writer::TAGLINE_MAX_LENGTH
				),
				'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_propose_site_identity' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => false,
					),
					/*
					 * Withheld from the REST and MCP surfaces on purpose; MCP
					 * exposure reads `mcp.public`, not `show_in_rest`. A
					 * proposal is only meaningful inside a workspace turn, which
					 * is where the person who has to approve it is.
					 */
					'show_in_rest' => false,
					'mcp'          => array( 'public' => false ),
				),
			)
		);
	}

	/**
	 * Permission callback for the ability.
	 *
	 * {@see Site_Identity_Writer::normalize_item()} checks the same capability
	 * again, and the writer once more when the person approves.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True when the request may proceed.
	 */
	public function check_permission( $input = array() ): bool {
		unset( $input );

		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The stored proposal summary, or an error.
	 */
	public function execute_propose_site_identity( $input = array() ) {
		if ( ! Turn_Context::is_active() ) {
			return new WP_Error(
				'workspace_context_required',
				__( 'A site title or tagline can only be proposed inside an AI Workspace conversation, where a person can approve it.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$input = rest_sanitize_object( $input );
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array();

		/*
		 * Two values for one setting would leave the person to approve both and
		 * see only the second survive, so each setting may be named once. Edits
		 * cannot change which setting an item names, so checking here suffices.
		 */
		$options = array();

		foreach ( $items as $item ) {
			$option = is_array( $item ) && isset( $item['option'] ) && is_string( $item['option'] ) ? $item['option'] : '';

			if ( isset( $options[ $option ] ) ) {
				return new WP_Error(
					'workspace_site_identity_duplicate_option',
					__( 'Each setting can be proposed only once. Propose a single new value for it.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$options[ $option ] = true;
		}

		$proposal = $this->store->create(
			get_current_user_id(),
			Turn_Context::get_conversation_id(),
			$items,
			Site_Identity_Writer::KIND
		);

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		$summary = array();

		foreach ( $proposal['items'] as $item ) {
			$summary[] = array(
				'key'    => $item['key'],
				'option' => $item['option'],
				'value'  => $item['value'],
			);
		}

		return array(
			'proposal_id'           => $proposal['id'],
			'item_count'            => count( $summary ),
			'max_items'             => count( Site_Identity_Writer::OPTIONS ),
			'expires'               => $proposal['expires'],
			'confirmation_required' => true,
			'items'                 => $summary,
			'note'                  => __( 'Nothing has been changed. The person now sees each current value next to the proposed one, may edit it, and chooses which changes to approve. Do not claim the site title or tagline changed, and do not propose the same change again unless they ask.', 'ai' ),
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
				'items' => array(
					'type'        => 'array',
					'description' => __( 'The changes to propose, one setting each, each setting at most once.', 'ai' ),
					'minItems'    => 1,
					'maxItems'    => count( Site_Identity_Writer::OPTIONS ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'option' => array(
								'type'        => 'string',
								'enum'        => Site_Identity_Writer::OPTIONS,
								'description' => __( 'Which setting to change: blogname is the site title, blogdescription the tagline.', 'ai' ),
							),
							'value'  => array(
								'type'        => 'string',
								'description' => __( 'The complete new value, as plain text with no HTML.', 'ai' ),
							),
						),
						'required'   => array( 'option', 'value' ),
					),
				),
			),
			'required'   => array( 'items' ),
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
				'proposal_id'           => array( 'type' => 'string' ),
				'item_count'            => array( 'type' => 'integer' ),
				'max_items'             => array( 'type' => 'integer' ),
				'expires'               => array( 'type' => 'integer' ),
				'confirmation_required' => array( 'type' => 'boolean' ),
				'items'                 => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'    => array( 'type' => 'string' ),
							'option' => array( 'type' => 'string' ),
							'value'  => array( 'type' => 'string' ),
						),
					),
				),
				'note'                  => array( 'type' => 'string' ),
			),
		);
	}
}
