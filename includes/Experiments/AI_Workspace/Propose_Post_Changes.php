<?php
/**
 * The `ai/propose-post-changes` WordPress Ability.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the `ai/propose-post-changes` ability, which proposes new post titles, excerpts or content without writing them.
 *
 * It is {@see Propose_Drafts} for changes to existing posts, and it holds to the
 * same rules: it records the exact values in {@see Proposal_Store} and writes
 * nothing, it is hidden from the REST and MCP surfaces, it refuses outside a
 * workspace turn, and the proposal is bound to the conversation read from
 * {@see Turn_Context}. {@see Post_Field_Writer} validates each item and writes
 * it once a person approves it.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Propose_Post_Changes {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/propose-post-changes';

	/**
	 * The ability category used for content abilities.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CATEGORY = 'content';

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
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ), 11 );
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the `content` ability category if it is not already registered.
	 *
	 * @since x.x.x
	 */
	public function register_category(): void {
		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Content', 'ai' ),
				'description' => __( 'Abilities that retrieve or manage posts and other content.', 'ai' ),
			)
		);
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
				'label'               => __( 'Propose Post Changes', 'ai' ),
				'description'         => sprintf(
					/* translators: %d: the maximum number of items a proposal may contain. */
					__( 'Records proposed changes to the title, excerpt or content of existing posts, for the person to review. This writes nothing: each item names one field of one post and its complete new value, the person sees the current value next to yours, may edit yours, and must approve before anything changes. A title is plain text with no HTML. To change content, first read the post with ai/read-content-bodies and "raw": true, then propose its complete block markup built from "content_raw", changing only what was asked and keeping every other block as it is; the plain-text body has lost its blocks and must not be used. Content that holds embeds or iframes cannot be rewritten. Propose at most %d items at a time, and never claim a post changed until you are told the outcome.', 'ai' ),
					Proposal_Store::MAX_ITEMS
				),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_propose_post_changes' ),
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
	 * Coarse by necessity: `edit_post` for each item's post needs the input, and
	 * it runs in {@see Post_Field_Writer::normalize_item()} and again at write time.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True when the request may proceed.
	 */
	public function check_permission( $input = array() ): bool {
		unset( $input );

		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The stored proposal summary, or an error.
	 */
	public function execute_propose_post_changes( $input = array() ) {
		if ( ! Turn_Context::is_active() ) {
			return new WP_Error(
				'workspace_context_required',
				__( 'Post changes can only be proposed inside an AI Workspace conversation, where a person can approve them.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$input = rest_sanitize_object( $input );
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array();

		/*
		 * Two values for one field of one post would leave the person to approve
		 * both, see the second reported stale once the first is written, and only
		 * the first survive. So each field of each post may be named once. Edits
		 * cannot change which post or field an item names, so checking here
		 * suffices. Keyed as the writer normalizes them, so `"12"` and `12`, or
		 * `Title` and `title`, are one field.
		 */
		$seen = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$post_id = isset( $item['post_id'] ) && is_numeric( $item['post_id'] ) ? absint( $item['post_id'] ) : 0;
			$field   = isset( $item['field'] ) && is_string( $item['field'] ) ? sanitize_key( $item['field'] ) : '';
			$target  = $post_id . ':' . $field;

			if ( isset( $seen[ $target ] ) ) {
				return new WP_Error(
					'workspace_post_change_duplicate_field',
					__( 'Each field of a post can be proposed only once. Propose a single new value for it.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$seen[ $target ] = true;
		}

		$proposal = $this->store->create(
			get_current_user_id(),
			Turn_Context::get_conversation_id(),
			$items,
			Post_Field_Writer::KIND
		);

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		$summary = array();

		foreach ( $proposal['items'] as $item ) {
			$summary[] = array(
				'key'     => $item['key'],
				'post_id' => (int) $item['post_id'],
				'field'   => $item['field'],
				'title'   => get_the_title( (int) $item['post_id'] ),
			);
		}

		return array(
			'proposal_id'           => $proposal['id'],
			'item_count'            => count( $summary ),
			'max_items'             => Proposal_Store::MAX_ITEMS,
			'expires'               => $proposal['expires'],
			'confirmation_required' => true,
			'items'                 => $summary,
			'note'                  => __( 'Nothing has been changed. The person now sees each current value next to the proposed one, may edit it, and chooses which changes to approve. Do not claim any post changed, and do not propose the same changes again unless they ask.', 'ai' ),
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
					'description' => __( 'The changes to propose, one field of one post each, in the order the person should see them.', 'ai' ),
					'minItems'    => 1,
					'maxItems'    => Proposal_Store::MAX_ITEMS,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'post_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => __( 'The ID of the existing post to change.', 'ai' ),
							),
							'field'   => array(
								'type'        => 'string',
								'enum'        => array_keys( Post_Field_Writer::FIELDS ),
								'description' => __( 'Which field to change.', 'ai' ),
							),
							'value'   => array(
								'type'        => 'string',
								'description' => sprintf(
									/* translators: %d: the maximum title length in characters. */
									__( 'The complete new value. A title is plain text of at most %d characters; content is the complete block markup, built from the post\'s "content_raw".', 'ai' ),
									Post_Field_Writer::TITLE_MAX_LENGTH
								),
							),
						),
						'required'   => array( 'post_id', 'field', 'value' ),
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
							'key'     => array( 'type' => 'string' ),
							'post_id' => array( 'type' => 'integer' ),
							'field'   => array( 'type' => 'string' ),
							'title'   => array( 'type' => 'string' ),
						),
					),
				),
				'note'                  => array( 'type' => 'string' ),
			),
		);
	}
}
