<?php
/**
 * Knowledge post type registration.
 *
 * @package WordPress\AI\Experiments\Knowledge
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Knowledge;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the Knowledge custom post type.
 *
 * The Gutenberg plugin ships the same post type from
 * `lib/experimental/knowledge/class-gutenberg-knowledge-post-type.php`. Only one
 * of the two may register it, so `register()` stands down when the post type is
 * already there and reports back who won.
 *
 * @since x.x.x
 */
class Knowledge_Post_Type {

	/**
	 * The post type name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const POST_TYPE = 'wp_knowledge';

	/**
	 * The taxonomy name for knowledge types.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TAXONOMY = 'wp_knowledge_type';

	/**
	 * Taxonomy term slug for the `guideline` knowledge type.
	 *
	 * Guidelines are loaded by default when applicable. Every row managed by the
	 * Settings → Guidelines page carries this term. Scope rows are further
	 * identified by the `guideline-` slug prefix (see the reservation guard in
	 * knowledge-functions.php).
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TERM_GUIDELINE = 'guideline';

	/**
	 * Registers the custom post type and its taxonomy.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when this plugin registered the post type, false when
	 *              another plugin (typically Gutenberg) already had.
	 */
	public static function register(): bool {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return false;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral -- The slug is the shared `wp_knowledge` constant.
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'                => array(
					'name'                     => _x( 'Guidelines', 'post type general name', 'ai' ),
					'singular_name'            => _x( 'Guideline', 'post type singular name', 'ai' ),
					'add_new'                  => __( 'Add Guideline', 'ai' ),
					'add_new_item'             => __( 'Add Guideline', 'ai' ),
					'all_items'                => __( 'All Guidelines', 'ai' ),
					'edit_item'                => __( 'Edit Guideline', 'ai' ),
					'filter_items_list'        => __( 'Filter guidelines list', 'ai' ),
					'item_published'           => __( 'Guideline published.', 'ai' ),
					'item_published_privately' => __( 'Guideline published privately.', 'ai' ),
					'item_reverted_to_draft'   => __( 'Guideline reverted to draft.', 'ai' ),
					'item_scheduled'           => __( 'Guideline scheduled.', 'ai' ),
					'item_updated'             => __( 'Guideline updated.', 'ai' ),
					'items_list'               => __( 'Guidelines list', 'ai' ),
					'items_list_navigation'    => __( 'Guidelines list navigation', 'ai' ),
					'new_item'                 => __( 'New Guideline', 'ai' ),
					'not_found'                => __( 'No guidelines found.', 'ai' ),
					'not_found_in_trash'       => __( 'No guidelines found in Trash.', 'ai' ),
					'search_items'             => __( 'Search Guidelines', 'ai' ),
					'view_item'                => __( 'View Guideline', 'ai' ),
					'view_items'               => __( 'View Guidelines', 'ai' ),
				),
				'public'                => false,
				/*
				 * Knowledge rows have no native post-type screens. Management
				 * flows through the Settings → Guidelines page (see Admin_Page)
				 * and the REST API.
				 */
				'show_ui'               => false,
				'show_in_rest'          => true,
				'rest_base'             => 'knowledge',
				'rest_controller_class' => Knowledge_REST_Controller::class,
				/*
				 * The primitive capabilities follow the standard plural form
				 * (`edit_knowledge_items`) while the per-post meta capabilities
				 * keep the singular form (`edit_knowledge_item`) — the same
				 * primitive/meta split WordPress uses for posts (`edit_posts` vs
				 * `edit_post`). The `*_knowledge_item` forms are never granted.
				 * `map_meta_cap()` resolves them onto the primitives.
				 */
				'capability_type'       => array( 'knowledge_item', 'knowledge_items' ),
				'map_meta_cap'          => true,
				/*
				 * `read` is remapped so Subscribers (who hold the base `read`
				 * cap) are blocked at the post-type door. Every other primitive
				 * defaults to a knowledge_items-suffixed cap synthesized by
				 * `wp_maybe_grant_knowledge_caps()`.
				 */
				'capabilities'          => array(
					'read' => 'read_knowledge_items',
				),
				'supports'              => array( 'title', 'editor', 'excerpt', 'author', 'revisions' ),
				'hierarchical'          => false,
				'has_archive'           => false,
				'rewrite'               => false,
				'query_var'             => false,
				'can_export'            => true,
			)
		);

		/*
		 * Disable autosave endpoints for knowledge. 'editor' support implies
		 * 'autosave', but knowledge is headless storage with no editor session,
		 * so the autosave REST routes have no consumer. Revision history is
		 * retained.
		 */
		remove_post_type_support( self::POST_TYPE, 'autosave' );

		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'public'             => false,
				'publicly_queryable' => false,
				'hierarchical'       => true,
				'labels'             => array(
					'name'                  => _x( 'Guideline Types', 'taxonomy general name', 'ai' ),
					'singular_name'         => _x( 'Guideline Type', 'taxonomy singular name', 'ai' ),
					'add_new_item'          => __( 'Add Guideline Type', 'ai' ),
					'add_or_remove_items'   => __( 'Add or remove guideline types', 'ai' ),
					'back_to_items'         => __( '&larr; Go to Guideline Types', 'ai' ),
					'edit_item'             => __( 'Edit Guideline Type', 'ai' ),
					'item_link'             => __( 'Guideline Type Link', 'ai' ),
					'item_link_description' => __( 'A link to a guideline type.', 'ai' ),
					'items_list'            => __( 'Guideline Types list', 'ai' ),
					'items_list_navigation' => __( 'Guideline Types list navigation', 'ai' ),
					'new_item_name'         => __( 'New Guideline Type Name', 'ai' ),
					'no_terms'              => __( 'No guideline types', 'ai' ),
					'not_found'             => __( 'No guideline types found.', 'ai' ),
					'search_items'          => __( 'Search Guideline Types', 'ai' ),
					'update_item'           => __( 'Update Guideline Type', 'ai' ),
					'view_item'             => __( 'View Guideline Type', 'ai' ),
				),
				/*
				 * Editing and assigning terms reuse the `wp_knowledge` primitive
				 * `edit_knowledge_items` so that anyone who can edit a knowledge
				 * row can also lazily create and assign its type. Managing or
				 * deleting the type vocabulary itself stays an administrator task.
				 */
				'capabilities'       => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'edit_knowledge_items',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_knowledge_items',
				),
				'query_var'          => false,
				'rewrite'            => false,
				/*
				 * Headless, like the post type: knowledge type terms are managed
				 * through the REST API, not a wp-admin taxonomy screen.
				 */
				'show_ui'            => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
			)
		);

		add_filter( 'user_has_cap', 'wp_maybe_grant_knowledge_caps', 1, 4 );
		add_action( 'save_post_' . self::POST_TYPE, 'wp_knowledge_ensure_default_type_term' );
		add_filter( 'wp_insert_term_data', 'wp_knowledge_maybe_map_term_label', 10, 2 );

		/*
		 * Sanitize guideline content and re-stamp registry scope titles on the
		 * REST insert path. Slug uniqueness is left to WordPress: the published
		 * row keeps its exact slug and duplicates are suffixed (see
		 * knowledge-functions.php).
		 */
		add_filter( 'rest_pre_insert_' . self::POST_TYPE, 'wp_knowledge_guard_guideline_row' );

		return true;
	}
}
