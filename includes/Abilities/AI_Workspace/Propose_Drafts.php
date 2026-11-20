<?php
/**
 * The `ai/propose-drafts` WordPress Ability.
 *
 * @package WordPress\AI\Abilities\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\AI_Workspace;

use WP_Error;
use WP_Post_Type;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Store;
use WordPress\AI\Experiments\AI_Workspace\Turn_Context;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the `ai/propose-drafts` ability, which proposes drafts without writing any.
 *
 * The conversation comes from {@see Turn_Context}, never the model's arguments, which are attacker-influenceable.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Propose_Drafts {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/propose-drafts';

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
	 * Hooks the ability's category into the Abilities API.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ), 11 );
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
	 * Returns the ability's registration args.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed>|null The registration args, or null when no post type is exposed.
	 */
	public function get_ability_args(): ?array {
		$post_types = array_keys( $this->get_exposed_post_types() );

		if ( array() === $post_types ) {
			return null;
		}

		return array(
			'label'               => __( 'Propose Drafts', 'ai' ),
			'description'         => sprintf(
				/* translators: %d: the maximum number of items a proposal may contain. */
				__( 'Records a set of proposed drafts for the person to review. This writes nothing: it stores the exact field values you propose and returns a proposal identifier, and the person must approve the stored values before anything is created. Propose at most %d items at a time, and never claim a draft exists until the person confirms.', 'ai' ),
				Proposal_Store::MAX_ITEMS
			),
			'category'            => self::CATEGORY,
			'input_schema'        => $this->get_input_schema( $post_types ),
			'output_schema'       => $this->get_output_schema(),
			'execute_callback'    => array( $this, 'execute_propose_drafts' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
					'open_world'  => false,
				),
				// Only meaningful inside a workspace turn; MCP reads `mcp.public`, not `show_in_rest`.
				'show_in_rest' => false,
				'mcp'          => array( 'public' => false ),
			),
		);
	}

	/**
	 * Permission callback for the ability; per-item checks run in {@see Proposal_Store::create()} and at write time.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True when the request may proceed.
	 */
	public function check_permission( $input = array() ): bool {
		unset( $input );

		foreach ( $this->get_exposed_post_types() as $post_type_object ) {
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			if ( current_user_can( $this->post_type_cap( $post_type_object, 'create_posts' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Executes the ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The stored proposal summary, or an error.
	 */
	public function execute_propose_drafts( $input = array() ) {
		if ( ! Turn_Context::is_active() ) {
			return new WP_Error(
				'workspace_context_required',
				__( 'Drafts can only be proposed inside an AI Workspace conversation, where a person can approve them.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$input = rest_sanitize_object( $input );
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array();

		$proposal = $this->store->create(
			get_current_user_id(),
			Turn_Context::get_conversation_id(),
			$items
		);

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		$summary = array();

		foreach ( $proposal['items'] as $item ) {
			$summary[] = array(
				'key'       => $item['key'],
				'post_type' => $item['post_type'],
				'status'    => $item['status'],
				'title'     => $item['title'],
			);
		}

		return array(
			'proposal_id'           => $proposal['id'],
			'item_count'            => count( $summary ),
			'max_items'             => Proposal_Store::MAX_ITEMS,
			'expires'               => $proposal['expires'],
			'confirmation_required' => true,
			'items'                 => $summary,
			'note'                  => __( 'Nothing has been written. The person now sees the exact stored values and chooses which items to approve. Do not claim anything was created, and do not propose the same items again unless they ask.', 'ai' ),
		);
	}

	/**
	 * Returns the ability's input schema.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $post_types The post types exposed to abilities.
	 * @return array<string, mixed> The input schema.
	 */
	private function get_input_schema( array $post_types ): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'        => 'array',
					'description' => __( 'The drafts to propose, in the order the person should see them.', 'ai' ),
					'minItems'    => 1,
					'maxItems'    => Proposal_Store::MAX_ITEMS,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'    => 'string',
								'enum'    => $post_types,
								'default' => 'post',
							),
							'status'    => array(
								'type'        => 'string',
								'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
								'default'     => 'draft',
								'description' => __( 'A status the person cannot write to fails the whole proposal rather than being quietly downgraded.', 'ai' ),
							),
							'title'     => array( 'type' => 'string' ),
							'content'   => array( 'type' => 'string' ),
							'excerpt'   => array( 'type' => 'string' ),
						),
						'required'   => array( 'title' ),
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
							'key'       => array( 'type' => 'string' ),
							'post_type' => array( 'type' => 'string' ),
							'status'    => array( 'type' => 'string' ),
							'title'     => array( 'type' => 'string' ),
						),
					),
				),
				'note'                  => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Returns the post types exposed through the Abilities API, keyed by name.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, \WP_Post_Type> Exposed post type objects keyed by name.
	 */
	private function get_exposed_post_types(): array {
		$exposed = array();

		foreach ( get_post_types( array( 'show_in_abilities' => true ), 'objects' ) as $post_type_object ) {
			$exposed[ $post_type_object->name ] = $post_type_object;
		}

		return $exposed;
	}

	/**
	 * Resolves a capability name from a post type's capability map.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @param string        $capability       The capability key.
	 * @return string The resolved capability, or 'do_not_allow' when unresolved.
	 */
	private function post_type_cap( WP_Post_Type $post_type_object, string $capability ): string {
		$cap = $post_type_object->cap->$capability ?? null;

		return is_string( $cap ) && '' !== $cap ? $cap : 'do_not_allow';
	}
}
