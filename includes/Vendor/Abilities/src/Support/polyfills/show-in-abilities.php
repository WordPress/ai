<?php
/**
 * Polyfill for the `show_in_abilities` post type registration argument.
 *
 * For WordPress 6.9-7.1, where the argument does not exist yet (added in 7.2)
 * but abilities exposing post-type-gated logic, such as `core/content-query`,
 * query `get_post_types( array( 'show_in_abilities' => true ) )`.
 *
 * Semantics mirrored from core trunk:
 *
 * - `show_in_abilities` defaults to false for every post type, and is cast
 *   to bool. See WP_Post_Type on trunk.
 * - Core opts in exactly the built-in `post` and `page` post types when
 *   registering them. This polyfill injects that same default through the
 *   `register_post_type_args` filter, mirroring wp-includes/post.php.
 * - Custom post types opt in by passing the argument to register_post_type()
 *   themselves. On 6.9-7.1, WP_Post_Type::set_props() assigns all
 *   registration arguments as object properties, so the value behaves like
 *   core's declared argument, and get_post_types() object filtering matches
 *   it. An explicit consumer value is never widened or overridden.
 *
 * Loading: through require_once, only from the Registrar (via
 * Support\Features) when an ability requires the feature and core lacks it.
 * Never autoloaded.
 *
 * Removal cue: delete this file and its Features::$polyfills map entry when
 * the package's minimum supported WordPress version rises to 7.2 — every
 * consuming site then has core's declared WP_Post_Type property
 * (`wp_has_abilities` hosts ≥ 7.2), the polyfill can never load again, and
 * Features::is_available() reports the native property.
 *
 * @package WordPress\Abilities
 */

/**
 * Injects the core default `show_in_abilities` values into registration args.
 *
 * Only fills the default for the built-in post types core itself exposes on
 * trunk; any explicit value already present in the args is kept as-is.
 *
 * @param array<mixed> $args      Registration arguments for the post type.
 * @param string       $post_type The post type key.
 * @return array<mixed> The filtered registration arguments.
 */
$wordpress_abilities_show_in_abilities_polyfill = static function ( $args, $post_type ) {
	if ( ! is_array( $args ) || array_key_exists( 'show_in_abilities', $args ) ) {
		return $args;
	}

	if ( 'post' === $post_type || 'page' === $post_type ) {
		$args['show_in_abilities'] = true;
	}

	return $args;
};

add_filter( 'register_post_type_args', $wordpress_abilities_show_in_abilities_polyfill, 10, 2 );
unset( $wordpress_abilities_show_in_abilities_polyfill );

/*
 * Catch-up for built-in post types registered before this polyfill loaded.
 * This mirrors trunk's explicit opt-in; custom post types are already
 * handled by their own registration arguments. An explicit consumer value
 * on the object is never overridden.
 */
foreach ( array( 'post', 'page' ) as $polyfill_post_type ) {
	$polyfill_object = get_post_type_object( $polyfill_post_type );
	if ( $polyfill_object && ! isset( $polyfill_object->show_in_abilities ) ) {
		// The property exists on supported hosts (declared or polyfilled); this is the polyfill itself.
		$polyfill_object->show_in_abilities = true; // @phpstan-ignore property.notFound (the property is declared on trunk hosts, mirrored here pre-7.2)
	}
}
unset( $polyfill_post_type, $polyfill_object );
