<?php
/**
 * The `ai/propose-template` WordPress Ability.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the `ai/propose-template` ability, which proposes a block template without writing it.
 *
 * It is {@see Propose_Drafts} for templates, and it holds to the same rules: it
 * records the exact values in {@see Proposal_Store} and writes nothing, it is
 * hidden from the REST and MCP surfaces, it refuses outside a workspace turn, and
 * the proposal is bound to the conversation read from {@see Turn_Context}.
 * {@see Template_Writer} creates the template once a person approves it.
 *
 * On a classic theme it returns an error that says why, written for the model to
 * pass on, so the person hears that templates need a block theme rather than
 * seeing a tool fail.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Propose_Template {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/propose-template';

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
				'label'               => __( 'Propose Template', 'ai' ),
				'description'         => __( 'Records one proposed block template for the active block theme, for the person to review. This writes nothing: it stores the exact slug, title and block markup you propose and returns a proposal identifier, and the person must approve the stored values before the template is created. The markup must be block markup only, with no HTML outside block comments. Existing edited templates are never overwritten. Fails on a classic theme; tell the person why.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_propose_template' ),
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
	 * Deliberately says nothing about the theme. On a classic theme the call has
	 * to run, so its error can tell the person why there is no template.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True when the request may proceed.
	 */
	public function check_permission( $input = array() ): bool {
		unset( $input );

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The capability is the literal edit_theme_options, held in a constant.
		return is_user_logged_in() && current_user_can( Template_Writer::CAPABILITY );
	}

	/**
	 * Executes the ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The stored proposal summary, or an error.
	 */
	public function execute_propose_template( $input = array() ) {
		if ( ! Turn_Context::is_active() ) {
			return new WP_Error(
				'workspace_context_required',
				__( 'Templates can only be proposed inside an AI Workspace conversation, where a person can approve them.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$proposal = $this->store->create(
			get_current_user_id(),
			Turn_Context::get_conversation_id(),
			array( rest_sanitize_object( $input ) ),
			Template_Writer::KIND
		);

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		$summary = array();

		foreach ( $proposal['items'] as $item ) {
			$summary[] = array(
				'key'   => $item['key'],
				'slug'  => $item['slug'],
				'title' => $item['title'],
			);
		}

		return array(
			'proposal_id'           => $proposal['id'],
			'item_count'            => count( $summary ),
			'expires'               => $proposal['expires'],
			'confirmation_required' => true,
			'items'                 => $summary,
			'note'                  => __( 'Nothing has been written. The person now sees the exact stored template and chooses whether to create it. Do not claim the template exists, and do not propose it again unless they ask.', 'ai' ),
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
				'slug'    => array(
					'type'        => 'string',
					'description' => sprintf(
						/* translators: %s: comma-separated list of template slugs. */
						__( 'Which template this is: one of %s, or page- followed by a page slug for one page.', 'ai' ),
						implode( ', ', Template_Writer::standard_slugs() )
					),
				),
				'title'   => array(
					'type'        => 'string',
					'description' => __( 'The template\'s name, as the Site Editor lists it.', 'ai' ),
				),
				'content' => array(
					'type'        => 'string',
					'description' => __( 'The template\'s block markup, as the Site Editor saves it.', 'ai' ),
				),
			),
			'required'   => array( 'slug', 'title', 'content' ),
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
				'expires'               => array( 'type' => 'integer' ),
				'confirmation_required' => array( 'type' => 'boolean' ),
				'items'                 => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'   => array( 'type' => 'string' ),
							'slug'  => array( 'type' => 'string' ),
							'title' => array( 'type' => 'string' ),
						),
					),
				),
				'note'                  => array( 'type' => 'string' ),
			),
		);
	}
}
