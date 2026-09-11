<?php
/**
 * Shared helpers for calling a post type's REST route.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content;

use WP_Post;
use WP_Post_Type;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Trait - Post_Type_Route
 *
 * The REST-backed content implementations all address a post type's own posts endpoint,
 * so they all meet the same two problems: a post type exposed to abilities without being
 * exposed to REST has no route to call, and the posts controller leaves the global post
 * set to the last item it rendered.
 *
 * @internal This trait should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
trait Post_Type_Route {

	/**
	 * Returns the REST route for a post type's posts endpoint.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return string The route, for example `/wp/v2/posts`.
	 */
	protected function route( WP_Post_Type $post_type_object ): string {
		$namespace = ! empty( $post_type_object->rest_namespace ) && is_string( $post_type_object->rest_namespace )
			? $post_type_object->rest_namespace
			: 'wp/v2';
		$base      = ! empty( $post_type_object->rest_base ) && is_string( $post_type_object->rest_base )
			? $post_type_object->rest_base
			: $post_type_object->name;

		return '/' . $namespace . '/' . $base;
	}

	/**
	 * Makes sure a post type can be read through the REST API.
	 *
	 * A post type can be exposed to abilities with `show_in_abilities` without being exposed
	 * to REST, in which case it has no route and the posts controller refuses to serve it.
	 * Turn the flag on for the length of the request, and drop the built REST server so the
	 * next request builds a fresh one. Rebuilding runs `rest_api_init`, where WordPress
	 * registers a route for every post type exposed to REST, including this one.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return callable(): void A callback that restores the flag and the previous server.
	 */
	protected function prepare_post_type( WP_Post_Type $post_type_object ): callable {
		if ( ! empty( $post_type_object->show_in_rest ) ) {
			return static function (): void {};
		}

		$previous_flag   = $post_type_object->show_in_rest;
		$previous_server = $GLOBALS['wp_rest_server'] ?? null;

		$post_type_object->show_in_rest = true;
		unset( $GLOBALS['wp_rest_server'] );

		return static function () use ( $post_type_object, $previous_flag, $previous_server ): void {
			$post_type_object->show_in_rest = $previous_flag;

			/*
			 * The server used for the request was built while the post type was exposed, so
			 * its routes include one the restored post type must not have. Drop it either
			 * way: when there was a previous server, put it back, and when there was none,
			 * leave the global unset so the next caller builds a fresh one.
			 */
			if ( null === $previous_server ) {
				unset( $GLOBALS['wp_rest_server'] );
				return;
			}

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restores the WordPress REST server that was replaced above.
			$GLOBALS['wp_rest_server'] = $previous_server;
		};
	}

	/**
	 * Remembers the global post context so it can be put back after the request.
	 *
	 * The posts endpoint sets the global post while it renders each item and leaves it
	 * there. The ability restores whatever context it found, so filters that run after it
	 * still see the post they were looking at.
	 *
	 * @since x.x.x
	 *
	 * @return callable(): void A callback that restores the previous global post context.
	 */
	protected function capture_post_context(): callable {
		$previous_post = $GLOBALS['post'] ?? null;

		return static function () use ( $previous_post ): void {
			if ( $previous_post instanceof WP_Post ) {
				$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post context.
				setup_postdata( $previous_post );

				return;
			}

			unset( $GLOBALS['post'] );
			wp_reset_postdata();
		};
	}
}
