<?php
/**
 * The `ai/list-terms` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content;

use WP_Error;
use WP_Taxonomy;
use WP_Term;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - List_Terms
 *
 * Registers the read-only `ai/list-terms` ability, which lists the categories, tags
 * and other public terms a site uses, with how many published posts each holds. It
 * is how the AI Workspace sees what a site already covers, and it is registered for
 * every ability consumer, like {@see Search_Content}.
 *
 * Only publicly viewable taxonomies attached to a post type exposed to abilities are
 * listed. Their terms, and the published-post counts WordPress keeps for them, are
 * already shown to any visitor, so the ability adds no permission check beyond
 * requiring an authenticated user.
 *
 * Kept until `core/terms-query` lands in this plugin (#1099, #1084); the workspace
 * can list that one instead once it does.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class List_Terms {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const ABILITY = 'ai/list-terms';

	/**
	 * The ability category used for content abilities.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'content';

	/**
	 * Default number of terms returned.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 50;

	/**
	 * Maximum number of terms returned.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Taxonomies listed when the caller names none, if they are listable.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private const DEFAULT_TAXONOMIES = array( 'category', 'post_tag' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

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
	 * Registers the read-only `ai/list-terms` ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		$taxonomies = $this->get_listable_taxonomies();

		if ( array() === $taxonomies ) {
			return;
		}

		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'List Terms', 'ai' ),
				'description'         => sprintf(
					/* translators: %d: the maximum number of terms returned. */
					__( 'Lists the categories, tags and other public terms the site uses, with each term\'s name, slug and how many published posts it holds. Order by count to see what the site covers most. Terms holding no posts are left out unless asked for. Returns at most %d terms. Requires an authenticated user.', 'ai' ),
					self::MAX_PER_PAGE
				),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_input_schema( $taxonomies ),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_list_terms' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						// This ability only reads the local database.
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission callback for the `ai/list-terms` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed.
	 */
	public function check_permission( $input = array() ): bool {
		unset( $input );

		return is_user_logged_in();
	}

	/**
	 * Executes the `ai/list-terms` ability.
	 *
	 * A taxonomy that is not listable is refused here as well as in the schema, so a
	 * caller that skips schema validation still cannot list a private taxonomy.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array{terms: list<array{taxonomy: string, name: string, slug: string, count: int}>, total: int}|\WP_Error The terms, or an error.
	 */
	public function execute_list_terms( $input = array() ) {
		$input     = rest_sanitize_object( $input );
		$listable  = $this->get_listable_taxonomies();
		$requested = isset( $input['taxonomy'] ) && is_array( $input['taxonomy'] )
			? array_values( array_unique( array_map( 'sanitize_key', array_filter( $input['taxonomy'], 'is_string' ) ) ) )
			: array();

		if ( array() === $requested ) {
			$requested = array_values( array_intersect( self::DEFAULT_TAXONOMIES, $listable ) );
		}

		foreach ( $requested as $taxonomy ) {
			if ( ! in_array( $taxonomy, $listable, true ) ) {
				return new WP_Error(
					'ai_list_terms_invalid_taxonomy',
					__( 'That taxonomy cannot be listed.', 'ai' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( array() === $requested ) {
			return array(
				'terms' => array(),
				'total' => 0,
			);
		}

		$by_count   = isset( $input['orderby'] ) && 'count' === $input['orderby'];
		$hide_empty = ! isset( $input['hide_empty'] ) || false !== $input['hide_empty'];
		$per_page   = isset( $input['per_page'] ) && is_scalar( $input['per_page'] ) ? absint( $input['per_page'] ) : self::DEFAULT_PER_PAGE;
		$per_page   = max( 1, min( self::MAX_PER_PAGE, $per_page ) );

		$args = array(
			'taxonomy'   => $requested,
			'hide_empty' => $hide_empty,
			'orderby'    => $by_count ? 'count' : 'name',
			'order'      => $by_count ? 'DESC' : 'ASC',
		);

		$total = wp_count_terms( $args );
		$terms = get_terms( $args + array( 'number' => $per_page ) );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$rows = array();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$rows[] = array(
				'taxonomy' => $term->taxonomy,
				'name'     => $term->name,
				'slug'     => $term->slug,
				'count'    => (int) $term->count,
			);
		}

		return array(
			'terms' => $rows,
			'total' => is_wp_error( $total ) ? count( $rows ) : (int) $total,
		);
	}

	/**
	 * Returns the taxonomies this ability lists.
	 *
	 * Those that are publicly viewable and attached to a post type exposed to
	 * abilities, matching the taxonomies {@see Search_Content} filters by.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The taxonomy names.
	 */
	private function get_listable_taxonomies(): array {
		$taxonomies = array();

		foreach ( get_post_types( array( 'show_in_abilities' => true ) ) as $post_type ) {
			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
				if ( ! ( $taxonomy instanceof WP_Taxonomy ) || ! is_taxonomy_viewable( $taxonomy ) ) {
					continue;
				}

				$taxonomies[ $taxonomy->name ] = true;
			}
		}

		return array_keys( $taxonomies );
	}

	/**
	 * Builds the input schema for the `ai/list-terms` ability.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $taxonomies The listable taxonomies.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_input_schema( array $taxonomies ): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'taxonomy'   => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => $taxonomies,
					),
					'description' => __( 'The taxonomies to list. Defaults to categories and tags.', 'ai' ),
				),
				'orderby'    => array(
					'type'        => 'string',
					'enum'        => array( 'name', 'count' ),
					'description' => __( 'Order by name (A to Z), or by count (most posts first). Defaults to name.', 'ai' ),
				),
				'hide_empty' => array(
					'type'        => 'boolean',
					'description' => __( 'Leave out terms that hold no published posts. Defaults to true.', 'ai' ),
				),
				'per_page'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_PER_PAGE,
					'description' => __( 'Maximum number of terms to return.', 'ai' ),
				),
			),
		);
	}

	/**
	 * Builds the output schema for the `ai/list-terms` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'terms', 'total' ),
			'properties'           => array(
				'terms' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'taxonomy', 'name', 'slug', 'count' ),
						'properties'           => array(
							'taxonomy' => array( 'type' => 'string' ),
							'name'     => array( 'type' => 'string' ),
							'slug'     => array( 'type' => 'string' ),
							'count'    => array(
								'type'        => 'integer',
								'description' => __( 'How many published posts hold this term.', 'ai' ),
							),
						),
					),
				),
				'total' => array(
					'type'        => 'integer',
					'description' => __( 'How many terms match in all, which may exceed the number returned.', 'ai' ),
				),
			),
		);
	}
}
