<?php
/**
 * Polyfills the core `show_in_abilities` flag onto curated core objects.
 *
 * @package WordPress\AI
 *
 * @since 1.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Show_In_Abilities
 *
 * WordPress core does not yet ship the `show_in_abilities` flag consumed by the
 * `core/content` ability (and, in the future, settings and meta abilities). This
 * component polyfills that flag onto a curated set of core objects so the abilities
 * return data on a stock site, before/without the equivalent core change.
 *
 * It is intentionally object-type-agnostic: today it marks post types; settings and
 * meta can be marked here the same way as those abilities land.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.1.0
 */
class Show_In_Abilities {

	/**
	 * Registers the hooks that mark core objects as exposed to abilities.
	 *
	 * @since 1.1.0
	 */
	public static function register(): void {
		add_filter( 'register_post_type_args', array( self::class, 'mark_post_type' ), 10, 2 );

		/*
		 * Core post types (post, page) are registered very early — during bootstrap and on
		 * `init` priority 0 — which is typically before this component runs, so the filter
		 * above would miss them. Mark any already-registered curated post types directly.
		 */
		self::mark_registered_post_types();
	}

	/**
	 * Adds the `show_in_abilities` flag to curated core post types as they are registered.
	 *
	 * Respects an explicit `show_in_abilities` value already present on the post type (for
	 * example once core ships it natively), only filling it in when absent.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $args      The post type registration arguments.
	 * @param string               $post_type The post type key.
	 * @return array<string, mixed> The (possibly amended) registration arguments.
	 */
	public static function mark_post_type( array $args, string $post_type ): array {
		$post_types = self::post_types_map();

		if ( isset( $post_types[ $post_type ] ) && empty( $args['show_in_abilities'] ) ) {
			$args['show_in_abilities'] = $post_types[ $post_type ];
		}

		return $args;
	}

	/**
	 * Marks already-registered curated post types as exposed to abilities.
	 *
	 * The `register_post_type_args` filter only affects post types registered after it is
	 * added, but core post types are registered during bootstrap. This patches the existing
	 * post type objects directly so the polyfill works regardless of when it runs.
	 * {@see WP_Post_Type} allows dynamic properties, so this is safe on stock WordPress.
	 *
	 * @since 1.1.0
	 */
	public static function mark_registered_post_types(): void {
		foreach ( self::post_types_map() as $post_type => $show ) {
			$object = get_post_type_object( $post_type );
			if ( ! ( $object instanceof \WP_Post_Type ) || ! empty( $object->show_in_abilities ) ) {
				continue;
			}

			$object->show_in_abilities = $show;
		}
	}

	/**
	 * Returns the curated core post types to expose, keyed by post type key.
	 *
	 * The value is whatever `show_in_abilities` should contain: `true`, or an array
	 * reserved for enabling specific operations in the future. This matches the set
	 * marked natively by the core `core/content` implementation (`post` and `page`).
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, bool|array<string, mixed>> Post types map keyed by post type key.
	 */
	public static function post_types_map(): array {
		return array(
			'post' => true,
			'page' => true,
		);
	}
}
