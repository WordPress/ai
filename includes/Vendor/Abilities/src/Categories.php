<?php
/**
 * Canonical WordPress abilities: manifest-declared ability categories.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities;

/**
 * Category definitions for the categories the manifest declares.
 *
 * The Registrar derives the categories to declare from the manifest (the
 * `category` field of every entry) and registers them with the Abilities
 * API, so this table is the package's single source of truth (shipped by
 * composer autoload) for what the declared categories look like.
 *
 * Definitions mirror core's default ability category registration
 * (wordpress-develop trunk, wp_register_core_ability_categories()) verbatim,
 * including being passed through the `default` text domain at declaration
 * time, so the package's declarations are indistinguishable from core's own
 * on 7.2+ hosts — with one exception: the `media` category is package-authored
 * (core trunk registers no media category; nothing to copy verbatim from
 * trunk). This is not a general category registry: keep an entry in
 * this table only for as long as the manifest declares a category that
 * needs it, and keep the strings in sync when a trunk refresh changes them.
 *
 * When the manifest gains a `category` whose slug is absent here, its
 * declaration is impossible: the declaration loop skips it and every ability
 * in it is then rejected loudly at the Registrar's Gate 8 instead. A missing
 * entry is therefore also caught at manifest-rule level by
 * ManifestTest::test_manifest_categories_are_single_sourced().
 */
final class Categories {

	/**
	 * Category definitions, slug => definition.
	 *
	 * @var array<string, array{label: string, description: string}>
	 */
	private const DEFINITIONS = array(
		'site'    => array(
			'label'       => 'Site',
			'description' => 'Abilities that retrieve or modify site information and settings.',
		),
		'user'    => array(
			'label'       => 'User',
			'description' => 'Abilities that retrieve or modify user information and settings.',
		),
		'content' => array(
			'label'       => 'Content',
			'description' => 'Abilities that retrieve or manage posts and other content.',
		),
		/*
		 * Package-authored, for the `wp/media-query` port: core trunk registers no
		 * `media` ability category (wp_register_core_ability_categories() covers
		 * `site`, `user`, `content`, and `uncategorized`), so there is nothing to
		 * copy verbatim. Styled like trunk's own registrations; keep it in sync
		 * with the same trunk refresh cadence as the entries above. If core lands
		 * a media category, replace this definition with core's verbatim strings.
		 */
		'media'   => array(
			'label'       => 'Media',
			'description' => 'Abilities that retrieve or manage media items and attachments.',
		),
	);

	/**
	 * Static-only class.
	 */
	private function __construct() {}

	/**
	 * The category slugs the manifest requires, in first-appearance order.
	 *
	 * Derived from the manifest's `category` field — this is the set the
	 * Registrar declares — so manifest changes drive the declarations and
	 * this class never has to be updated to match. Duplicated slugs collapse.
	 *
	 * @return list<string>
	 */
	public static function required_slugs(): array {
		$slugs = array();
		foreach ( Manifest::all() as $entry ) {
			if ( is_string( $entry['category'] ) && '' !== $entry['category'] ) {
				$slugs[ $entry['category'] ] = true;
			}
		}

		return array_keys( $slugs );
	}

	/**
	 * Returns the single-sourced definition for a category slug.
	 *
	 * Null is returned for a slug the package does not ship a definition for.
	 *
	 * @param string $slug The category slug.
	 * @return array{label: string, description: string}|null The definition, or null when the package does not declare the slug.
	 */
	public static function definition( string $slug ): ?array {
		return isset( self::DEFINITIONS[ $slug ] ) ? self::DEFINITIONS[ $slug ] : null;
	}
}
