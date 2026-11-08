<?php
/**
 * Polyfill for the `show_in_abilities` taxonomy registration argument.
 *
 * For WordPress 6.9-7.1, where the argument does not exist yet (added in 7.2,
 * together with the post type argument the `show_in_abilities` polyfill
 * mirrors) but abilities exposing taxonomy-gated logic, such as
 * `core/terms-query`, query `get_taxonomies( array( 'show_in_abilities' => true ) )`.
 *
 * Semantics mirrored from core trunk, as the WordPress/ai PR #1099
 * Show_In_Abilities companion marks them:
 *
 * - `show_in_abilities` defaults to false for every taxonomy, and only `true`
 *   exposes one. See WP_Taxonomy on trunk.
 * - Core (trunk) opts in exactly the built-in `category` and `post_tag`
 *   taxonomies when registering them. This polyfill injects that same default
 *   through the `register_taxonomy_args` filter, mirroring
 *   wp-includes/taxonomy.php, and catches up the taxonomies registered before
 *   it loaded. The `post_format` taxonomy stays out, as on trunk: the flag's
 *   curations follow the taxonomies of the exposed post types, which the
 *   REST API serves.
 * - Custom taxonomies opt in by passing the argument to register_taxonomy()
 *   themselves. On 6.9-7.1, register_taxonomy() assigns all registration
 *   arguments as object properties, so the value behaves like core's declared
 *   argument for both readers the ability uses: the loose object-list match of
 *   get_taxonomies() and the ability's own truthiness check. An explicit
 *   consumer value is never widened or overridden. Trunk's strict bool cast in
 *   WP_Taxonomy::set_props() is not mirrored for such values: every value the
 *   ability reads the same way on 7.2 keeps reading the same way here.
 *
 * Loading: through require_once, only from the Registrar (via
 * Support\Features) when an ability requires the feature and core lacks it.
 * Never autoloaded.
 *
 * Removal cue: delete this file and its Features::$polyfills map entry when
 * the package's minimum supported WordPress version rises to 7.2 — every
 * consuming site then has core's declared WP_Taxonomy property
 * (`wp_has_abilities` hosts ≥ 7.2), the polyfill can never load again, and
 * Features::is_available() reports the native property.
 *
 * @package WordPress\Abilities
 */

/**
 * Injects the core default `show_in_abilities` values into taxonomy args.
 *
 * Only fills the default for the built-in taxonomies core's own registration
 * exposes on trunk; any explicit value already present in the args is kept
 * as-is, including an explicit `false` opt-out.
 *
 * @param array<mixed> $args     Registration arguments for the taxonomy.
 * @param string       $taxonomy The taxonomy key.
 * @return array<mixed> The filtered registration arguments.
 */
$wordpress_abilities_taxonomy_show_in_abilities_polyfill = static function ( $args, $taxonomy ) {
	if ( ! is_array( $args ) || array_key_exists( 'show_in_abilities', $args ) ) {
		return $args;
	}

	if ( 'category' === $taxonomy || 'post_tag' === $taxonomy ) {
		$args['show_in_abilities'] = true;
	}

	return $args;
};

add_filter( 'register_taxonomy_args', $wordpress_abilities_taxonomy_show_in_abilities_polyfill, 10, 2 );
unset( $wordpress_abilities_taxonomy_show_in_abilities_polyfill );

/*
 * Catch-up for the built-in taxonomies, which core registers during bootstrap
 * and on `init` priority 0 — typically before this polyfill loads. An explicit
 * consumer value on the object is never overridden.
 */
foreach ( array( 'category', 'post_tag' ) as $polyfill_taxonomy ) {
	$polyfill_object = get_taxonomy( $polyfill_taxonomy );
	if ( $polyfill_object instanceof WP_Taxonomy && ! property_exists( $polyfill_object, 'show_in_abilities' ) ) {
		$polyfill_object->show_in_abilities = true; // @phpstan-ignore property.notFound (the property is declared on trunk hosts, mirrored here pre-7.2)
	}
}
unset( $polyfill_taxonomy, $polyfill_object );
