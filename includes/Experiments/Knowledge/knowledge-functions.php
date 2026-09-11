<?php
/**
 * Knowledge public API.
 *
 * This file declares the shared, unprefixed `wp_*` knowledge functions. The same
 * contract is shipped by the Gutenberg plugin in
 * `lib/experimental/knowledge/knowledge.php`. Every function is wrapped in a
 * `function_exists()` guard so whichever plugin loads first owns the
 * implementation and the other one stands down. Keep the behaviour in sync with
 * Gutenberg so it does not matter which copy is in play.
 *
 * The functions intentionally use the `wp_` prefix rather than the plugin's
 * `wpai_` prefix. They are a shared contract, not plugin-private code.
 *
 * @package WordPress\AI\Experiments\Knowledge
 *
 * @since x.x.x
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Shared contract with Gutenberg, see the file docblock.

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_knowledge_types' ) ) {
	/**
	 * Returns the registered knowledge types keyed by slug.
	 *
	 * Plugins can register their own types via the `wp_knowledge_types` filter.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{title: string}> Slug-keyed map of knowledge types.
	 */
	function wp_knowledge_types(): array {
		/**
		 * Filters the knowledge types available on this site.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, array{title: string}> $types Slug-keyed map of knowledge types.
		 */
		return apply_filters(
			'wp_knowledge_types',
			array(
				'guideline' => array(
					'title' => _x( 'Guideline', 'knowledge type', 'ai' ),
				),
				'memory'    => array(
					'title' => _x( 'Memory', 'knowledge type', 'ai' ),
				),
				'note'      => array(
					'title' => _x( 'Note', 'knowledge type', 'ai' ),
				),
			)
		);
	}
}

if ( ! function_exists( 'wp_guideline_scopes' ) ) {
	/**
	 * Returns the registered guideline scopes keyed by slug.
	 *
	 * Scopes are the sections shown on the Settings → Guidelines page. Each
	 * scope is backed by at most one `guideline`-typed `wp_knowledge` row whose
	 * slug is `guideline-{scope}`. Plugins can register their own scopes via the
	 * `wp_guideline_scopes` filter and the Settings page grows a section
	 * automatically. The registry carries identity and presentation only. Rows
	 * are created on first save.
	 *
	 * The `blocks` scope is the one exception: it has no single `guideline-blocks`
	 * row. Its section lists per-block guidelines stored as `guideline-block-*`
	 * rows. Removing it from this registry (via the filter) hides that section on
	 * the Settings page.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{title: string, description: string, order: int}> Slug-keyed map of guideline scopes.
	 */
	function wp_guideline_scopes(): array {
		/**
		 * Filters the guideline scopes available on this site.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, array{title: string, description: string, order: int}> $scopes Slug-keyed map of guideline scopes.
		 */
		return apply_filters(
			'wp_guideline_scopes',
			array(
				'site'       => array(
					'title'       => __( 'Site', 'ai' ),
					'description' => __( "Describe your site's purpose, goals, and primary audience.", 'ai' ),
					'order'       => 10,
				),
				'copy'       => array(
					'title'       => __( 'Copy', 'ai' ),
					'description' => __( 'Set your writing standards for tone, voice, style, and formatting.', 'ai' ),
					'order'       => 20,
				),
				'images'     => array(
					'title'       => __( 'Images', 'ai' ),
					'description' => __( 'Outline your style, dimensions, formats, mood and aesthetic preferences.', 'ai' ),
					'order'       => 30,
				),
				'blocks'     => array(
					'title'       => __( 'Blocks', 'ai' ),
					'description' => __( 'Create tailored guidelines for specific block types.', 'ai' ),
					'order'       => 40,
				),
				'additional' => array(
					'title'       => __( 'Additional', 'ai' ),
					'description' => __( 'Add additional guidelines.', 'ai' ),
					'order'       => 50,
				),
			)
		);
	}
}

if ( ! function_exists( 'wp_guideline_max_length' ) ) {
	/**
	 * Returns the maximum length, in characters, of a guideline row's content.
	 *
	 * @since x.x.x
	 *
	 * @return int Maximum number of characters allowed in guideline content.
	 */
	function wp_guideline_max_length(): int {
		/**
		 * Filters the maximum length, in characters, of a guideline row's content.
		 *
		 * @since x.x.x
		 *
		 * @param int $max_length Maximum number of characters. Default 5000.
		 */
		return (int) apply_filters( 'wp_guideline_max_length', 5000 );
	}
}

if ( ! function_exists( 'wp_knowledge_get_or_create_type_term' ) ) {
	/**
	 * Resolve a `wp_knowledge_type` term by slug, creating it lazily.
	 *
	 * Created term names are written once in the site locale (via
	 * `wp_knowledge_maybe_map_term_label`) so they don't vary with whoever
	 * triggered creation.
	 *
	 * @access private
	 *
	 * @since x.x.x
	 *
	 * @param string $slug Term slug.
	 * @return int|null Term ID, or null on failure.
	 */
	function wp_knowledge_get_or_create_type_term( string $slug ): ?int {
		$term = term_exists( $slug, 'wp_knowledge_type' );
		if ( is_array( $term ) ) {
			return (int) $term['term_id'];
		}

		$switched = switch_to_locale( get_locale() );
		$term     = wp_insert_term( $slug, 'wp_knowledge_type' );
		if ( $switched ) {
			restore_previous_locale();
		}

		if ( is_wp_error( $term ) ) {
			return null;
		}

		return (int) $term['term_id'];
	}
}

if ( ! function_exists( 'wp_knowledge_ensure_default_type_term' ) ) {
	/**
	 * Hook callback for the `save_post_wp_knowledge` action that assigns the
	 * knowledge type term.
	 *
	 * Rows whose slug begins with `guideline-` are forced onto the `guideline`
	 * type (the reservation rule: the prefix is reserved for guideline-typed
	 * rows). Any other row without a type term falls back to `note`.
	 *
	 * @access private
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id Saved post ID.
	 * @return void
	 */
	function wp_knowledge_ensure_default_type_term( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( str_starts_with( $post->post_name, 'guideline-' ) ) {
			$term_id = wp_knowledge_get_or_create_type_term( 'guideline' );
			if ( null !== $term_id ) {
				wp_set_object_terms( $post_id, $term_id, 'wp_knowledge_type' );
			}
			return;
		}

		$terms = get_the_terms( $post_id, 'wp_knowledge_type' );
		if ( is_wp_error( $terms ) || ! empty( $terms ) ) {
			return;
		}

		$term_id = wp_knowledge_get_or_create_type_term( 'note' );
		if ( null === $term_id ) {
			return;
		}

		wp_set_object_terms( $post_id, $term_id, 'wp_knowledge_type' );
	}
}

if ( ! function_exists( 'wp_maybe_grant_knowledge_caps' ) ) {
	/**
	 * Filters the user capabilities to grant the `wp_knowledge` post type capabilities as necessary.
	 *
	 * The `wp_knowledge` post type uses a `knowledge`-prefixed capability set that
	 * is granted dynamically rather than stored on roles. Administrators (users
	 * with `manage_options`) receive every knowledge capability. Contributors,
	 * authors, and editors (users with `edit_posts`) may list and create knowledge
	 * rows and fully manage their own private rows. Publishing knowledge and acting
	 * on other users' rows is reserved for administrators. Subscribers receive
	 * nothing and are stopped at the post-type door by the `read_knowledge_items` mapping.
	 *
	 * @access private
	 *
	 * @since x.x.x
	 *
	 * @param array<string, bool> $allcaps An array of all the user's capabilities.
	 * @param array<int, string>  $caps    Required primitive capabilities for the requested capability.
	 * @param array<int, mixed>   $args    Arguments that accompany the requested capability check.
	 * @param \WP_User             $user    The user object.
	 * @return array<string, bool> Filtered array of the user's capabilities.
	 */
	function wp_maybe_grant_knowledge_caps( $allcaps, $caps, $args, $user ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $caps is part of the `user_has_cap` signature.
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps['read_knowledge_items']             = true;
			$allcaps['edit_knowledge_items']             = true;
			$allcaps['edit_others_knowledge_items']      = true;
			$allcaps['edit_published_knowledge_items']   = true;
			$allcaps['edit_private_knowledge_items']     = true;
			$allcaps['publish_knowledge_items']          = true;
			$allcaps['delete_knowledge_items']           = true;
			$allcaps['delete_others_knowledge_items']    = true;
			$allcaps['delete_published_knowledge_items'] = true;
			$allcaps['delete_private_knowledge_items']   = true;
			$allcaps['read_private_knowledge_items']     = true;
			return $allcaps;
		}

		if ( empty( $allcaps['edit_posts'] ) ) {
			return $allcaps;
		}

		/*
		 * Ambient floor for Contributor+: `read_knowledge_items` clears the
		 * post-type read check; `edit_knowledge_items` clears the create and
		 * ownership checks that don't pass a post ID. Per-post primitives
		 * are granted only in the per-post branch below.
		 */
		$allcaps['read_knowledge_items'] = true;
		$allcaps['edit_knowledge_items'] = true;

		if ( ! isset( $args[0], $args[2] ) ) {
			return $allcaps;
		}

		if ( ! in_array( $args[0], array( 'edit_post', 'delete_post', 'read_post' ), true ) ) {
			return $allcaps;
		}

		$post = get_post( $args[2] );
		if (
			! $post instanceof WP_Post ||
			'wp_knowledge' !== $post->post_type ||
			(int) $post->post_author !== (int) $user->ID
		) {
			return $allcaps;
		}

		/*
		 * A trashed row keeps its pre-trash status in `_wp_trash_meta_status`.
		 * Resolve that effective status so the author keeps the ability to
		 * restore or permanently delete their own row once it is in the trash. A
		 * row trashed from a non-private status (only reachable for
		 * administrators) still falls outside the grant.
		 */
		$status = $post->post_status;
		if ( 'trash' === $status ) {
			$status = (string) get_post_meta( $post->ID, '_wp_trash_meta_status', true );
		}

		if ( 'private' !== $status ) {
			return $allcaps;
		}

		$allcaps['edit_private_knowledge_items']   = true;
		$allcaps['delete_knowledge_items']         = true;
		$allcaps['delete_private_knowledge_items'] = true;
		$allcaps['read_private_knowledge_items']   = true;

		return $allcaps;
	}
}

if ( ! function_exists( 'wp_knowledge_maybe_map_term_label' ) ) {
	/**
	 * Hook callback for the `wp_insert_term_data` filter that swaps a
	 * raw knowledge-type slug for its human-readable label when WordPress
	 * is about to lazily create the term.
	 *
	 * When `wp_set_object_terms()` is called with a slug that doesn't yet
	 * exist, `wp_insert_term()` fires and the filter runs after WP has
	 * computed both `name` and `slug`. A `name` equal to `slug` indicates
	 * the term was created from a raw slug (e.g. by `wp_set_object_terms()`)
	 * rather than from a user-provided label, so the label is replaced with
	 * the title from `wp_knowledge_types()`.
	 *
	 * @access private
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data     Term data to be inserted (keyed by column name).
	 * @param string               $taxonomy Taxonomy slug.
	 * @return array<string, mixed> Possibly modified term data.
	 */
	function wp_knowledge_maybe_map_term_label( array $data, string $taxonomy ): array {
		if ( 'wp_knowledge_type' !== $taxonomy ) {
			return $data;
		}

		if ( $data['name'] !== $data['slug'] ) {
			return $data;
		}

		$types = wp_knowledge_types();
		if ( isset( $types[ $data['slug'] ] ) ) {
			$data['name'] = $types[ $data['slug'] ]['title'];
		}

		return $data;
	}
}

if ( ! function_exists( 'wp_guideline_scope_from_slug' ) ) {
	/**
	 * Resolve the scope that owns a guideline row slug.
	 *
	 * Returns the scope key for a `guideline-{scope}` slug that matches a
	 * registered scope. A registered scope key always wins, so a scope keyed
	 * like `block-foo` resolves to itself rather than the blocks scope. Any
	 * other `guideline-block-*` slug is a per-block row that belongs to the
	 * `blocks` scope while it is registered. Returns null for the bare
	 * `guideline-block-` slug and for any unknown scope.
	 *
	 * @access private
	 *
	 * @since x.x.x
	 *
	 * @param string $slug Post slug.
	 * @return string|null Scope key, or null if the slug is not a registered scope.
	 */
	function wp_guideline_scope_from_slug( string $slug ): ?string {
		if ( ! str_starts_with( $slug, 'guideline-' ) ) {
			return null;
		}

		$scopes = wp_guideline_scopes();
		$scope  = substr( $slug, strlen( 'guideline-' ) );

		/*
		 * A slug that matches a registered scope key is that scope. Checking this
		 * first lets a scope keyed like `block-foo` win over the per-block
		 * namespace below, instead of being swallowed by the blocks scope.
		 */
		if ( isset( $scopes[ $scope ] ) ) {
			return $scope;
		}

		/*
		 * Otherwise a `guideline-block-<name>` row is a per-block row that belongs
		 * to the blocks scope while it is registered. A real block name never
		 * equals a registered scope key, so the check above stays safe.
		 */
		if ( str_starts_with( $slug, 'guideline-block-' ) && strlen( $slug ) > strlen( 'guideline-block-' ) ) {
			return isset( $scopes['blocks'] ) ? 'blocks' : null;
		}

		return null;
	}
}

if ( ! function_exists( 'wp_knowledge_guard_guideline_row' ) ) {
	/**
	 * Hook callback for `rest_pre_insert_wp_knowledge` that sanitizes and
	 * normalizes guideline rows on the REST insert path.
	 *
	 * Only rows whose slug maps to a registered guideline scope are shaped (see
	 * `wp_guideline_scope_from_slug()`). Any other row is left untouched. For a
	 * recognized guideline row this callback:
	 * - Sanitizes `post_content` to plain text capped at the guideline length.
	 * - Re-stamps the title of single-row registry scopes from
	 *   `wp_guideline_scopes()` in the site locale. Per-block rows (the multi-row
	 *   `blocks` scope) keep the client-provided canonical block name.
	 *
	 * Slug uniqueness is intentionally left to WordPress: the published row keeps
	 * its exact slug because the first save has no conflict and later saves reuse
	 * that row by ID, while any other row with the same desired slug is suffixed
	 * (`guideline-copy-2`) by `wp_unique_post_slug()`. The Settings page reads
	 * only the published row by its exact slug, so suffixed rows are ignored. The
	 * client save flow reclaims an existing same-slug row instead of creating a
	 * duplicate (see routes/guidelines/data.ts).
	 *
	 * @access private
	 *
	 * @since x.x.x
	 *
	 * @param \stdClass $prepared_post Prepared post object.
	 * @return \stdClass Prepared post.
	 */
	function wp_knowledge_guard_guideline_row( $prepared_post ) {
		$slug = '';
		if ( ! empty( $prepared_post->post_name ) ) {
			$slug = $prepared_post->post_name;
		} elseif ( ! empty( $prepared_post->ID ) ) {
			$existing = get_post( $prepared_post->ID );
			if ( $existing instanceof WP_Post ) {
				$slug = $existing->post_name;
			}
		}

		// Only shape rows whose slug maps to a registered guideline scope.
		$scope = wp_guideline_scope_from_slug( (string) $slug );
		if ( null === $scope ) {
			return $prepared_post;
		}

		// Sanitize content: plain text, capped at the guideline length.
		if ( isset( $prepared_post->post_content ) ) {
			$content = sanitize_textarea_field( $prepared_post->post_content );
			$max     = wp_guideline_max_length();
			if ( mb_strlen( $content, 'UTF-8' ) > $max ) {
				$content = mb_substr( $content, 0, $max, 'UTF-8' );
			}
			$prepared_post->post_content = $content;
		}

		/*
		 * Re-stamp single-row registry scope titles in the site locale. The
		 * blocks scope is multi-row, so its per-block rows keep the
		 * client-provided canonical block name.
		 */
		if ( 'blocks' !== $scope ) {
			$switched = switch_to_locale( get_locale() );
			$scopes   = wp_guideline_scopes();
			if ( $switched ) {
				restore_previous_locale();
			}
			if ( isset( $scopes[ $scope ]['title'] ) ) {
				$prepared_post->post_title = $scopes[ $scope ]['title'];
			}
		}

		return $prepared_post;
	}
}
