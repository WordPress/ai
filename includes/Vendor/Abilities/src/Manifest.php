<?php
/**
 * Canonical WordPress abilities: static ability inventory.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities;


/**
 * Static inventory of every ability shipped in this package.
 *
 * The manifest is the single source of truth for what exists and what tier
 * it is. No logic lives beyond validation-friendly accessors; consumers own
 * when the abilities are on, via the Registrar.
 */
final class Manifest {

	/** Package version; must stay in sync with composer.json. */
	public const VERSION = '0.1.0';

	/** Experimental tier: `wp/` prefix, no promises, iterating. */
	public const TIER_EXPERIMENTAL = 'experimental';

	/** Stable tier: `core/` or `wordpress/` prefix, proposed to core, schema-frozen. */
	public const TIER_STABLE = 'stable';

	/** Core tier: landed in actual WordPress; package copy is a backport. */
	public const TIER_CORE = 'core';

	/**
	 * Static-only class.
	 */
	private function __construct() {}

	/**
	 * Returns the full manifest.
	 *
	 * @return array<string, array<string, mixed>> Ability name => entry.
	 */
	public static function all(): array {
		return array(
			'core/content-query'  => array(
				'name'           => 'core/content-query',
				/*
				 * `::class` resolves to the FQCN without loading the class,
				 * so the manifest may be loaded before the ability classes
				 * exist in a given build of the package.
				 */
				'class'          => Content\Content_Query::class,
				'category'       => 'content',
				'tier'           => self::TIER_CORE,
				'min_wp_version' => '6.9',
				'core_since'     => '7.2',
				'requires'       => array( 'show_in_abilities' ),
				'renamed_from'   => null,
				// WP2-flagged for human confirmation: proposal URL to verify against
				// the landed core work (wordpress-develop PR 12195) before release.
				'proposal'       => 'https://github.com/WordPress/wordpress-develop/pull/12195',
			),
			'core/settings-get'   => array(
				'name'           => 'core/settings-get',
				'class'          => Settings\Settings_Get::class,
				'category'       => 'site',
				'tier'           => self::TIER_CORE,
				'min_wp_version' => '6.9',
				'core_since'     => '7.2',
				'requires'       => array( 'setting_show_in_abilities' ),
				'renamed_from'   => null,
				// Landed in trunk as b6512b1e8c (Trac #64605), developed in this PR.
				'proposal'       => 'https://github.com/WordPress/wordpress-develop/pull/12141',
			),
			'core/users-query'    => array(
				'name'           => 'core/users-query',
				'class'          => Users\Users_Query::class,
				'category'       => 'user',
				'tier'           => self::TIER_CORE,
				'min_wp_version' => '6.9',
				'core_since'     => '7.2',
				'requires'       => array(),
				'renamed_from'   => null,
				// Landed in trunk as 39981fd0fe (Trac #64657), developed in this PR.
				'proposal'       => 'https://github.com/WordPress/wordpress-develop/pull/10775',
			),
			/*
			 * The write trio below is a faithful port of WordPress/ai PR #1025
			 * (author @jorgefilipecosta), on the package's dotted field paths.
			 *
			 * Tier: the PR is a filed core proposal, not landed core, so the
			 * trio is stable (§7.2: a `core/` name is adopted at proposal
			 * filing). The entries flip to core with `core_since` set once
			 * the abilities land in an actual WordPress release.
			 */
			'core/content-create' => array(
				'name'           => 'core/content-create',
				'class'          => Content\Content_Create::class,
				'category'       => 'content',
				'tier'           => self::TIER_STABLE,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array( 'show_in_abilities' ),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1025',
			),
			'core/content-update' => array(
				'name'           => 'core/content-update',
				'class'          => Content\Content_Update::class,
				'category'       => 'content',
				'tier'           => self::TIER_STABLE,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array( 'show_in_abilities' ),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1025',
			),
			'core/content-delete' => array(
				'name'           => 'core/content-delete',
				'class'          => Content\Content_Delete::class,
				'category'       => 'content',
				'tier'           => self::TIER_STABLE,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array( 'show_in_abilities' ),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1025',
			),
			/*
			 * The media query below is a faithful port of WordPress/ai PR #1098
			 * (author @jorgefilipecosta).
			 *
			 * Tier: experimental, incubating under the `wp/` prefix — the proposal
			 * below is the PR's paper trail, but the ability has NOT landed in
			 * core (`core_since` stays null and flips when core lands it), so its
			 * name and schema carry no promises yet (NAME-LIFECYCLE.md). It uses
			 * the package-authored `media` ability category (core trunk has no
			 * media category; see Categories).
			 */
			'wp/media-query'      => array(
				'name'           => 'wp/media-query',
				'class'          => Media\Media_Query::class,
				'category'       => 'media',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array(),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1098',
			),
			/*
			 * The media writes below are a faithful port of WordPress/ai PR #1104
			 * (author @jorgefilipecosta) on top of the `wp/media-query` field
			 * projection: the abilities write attachments and answer with them
			 * through the query's contract, in the edit context.
			 *
			 * Tier: experimental, incubating under the `wp/` prefix — the proposal
			 * below is the PR's paper trail, but the abilities have NOT landed in
			 * core (`core_since` stays null and flips when core lands them), so
			 * their names and schemas carry no promises yet (NAME-LIFECYCLE.md).
			 * They use the package-authored `media` ability category (see
			 * Categories).
			 */
			'wp/media-upload'     => array(
				'name'           => 'wp/media-upload',
				'class'          => Media\Media_Upload::class,
				'category'       => 'media',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array(),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1104',
			),
			'wp/media-update'     => array(
				'name'           => 'wp/media-update',
				'class'          => Media\Media_Update::class,
				'category'       => 'media',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array(),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1104',
			),
			'wp/media-delete'     => array(
				'name'           => 'wp/media-delete',
				'class'          => Media\Media_Delete::class,
				'category'       => 'media',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array(),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1104',
			),
			/*
			 * The settings update below is a faithful port of WordPress/ai PR #764
			 * (author @jorgefilipecosta) on this package's `core/settings-get`
			 * backport (PR #2): the write ability extends `Settings_Get` and
			 * reuses the exposed-settings snapshot and the read-back helper. Its
			 * PR-side `core/settings-get` companion changes (endpoint setting
			 * names, per-setting stored-value sanitizing/omission) are already
			 * in the PR #2 backport; nothing was ported for them.
			 *
			 * Tier: experimental, incubating under the `wp/` prefix — the proposal
			 * below is the PR's paper trail, but the ability has NOT landed in
			 * core (`core_since` stays null and flips when core lands it), so its
			 * name and schema carry no promises yet (NAME-LIFECYCLE.md).
			 */
			'wp/settings-update'  => array(
				'name'           => 'wp/settings-update',
				'class'          => Settings\Settings_Update::class,
				'category'       => 'site',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array(),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/764',
			),
			/*
			 * The user writes below are a faithful port of WordPress/ai PR #1105
			 * (author @jorgefilipecosta) on the package's `core/users-query`
			 * field projection (PR #3): written users are answered through the
			 * same contract, in the edit context. The write helpers share that
			 * projection by verbatim copy (the coreify round-trip gate keeps the
			 * Users_Query backport byte-exact), with `format_user()` gaining the
			 * write-side `$edit_context` parameter the PR's write path adds.
			 *
			 * Tier: experimental, incubating under the `wp/` prefix — the proposal
			 * below is the PR's paper trail, but the abilities have NOT landed in
			 * core (`core_since` stays null and flips when core lands them), so
			 * their names and schemas carry no promises yet (NAME-LIFECYCLE.md).
			 */
			'wp/user-create'      => array(
				'name'           => 'wp/user-create',
				'class'          => Users\User_Create::class,
				'category'       => 'user',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array(),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1105',
			),
			'wp/user-update'      => array(
				'name'           => 'wp/user-update',
				'class'          => Users\User_Update::class,
				'category'       => 'user',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array(),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1105',
			),
			'wp/user-delete'      => array(
				'name'           => 'wp/user-delete',
				'class'          => Users\User_Delete::class,
				'category'       => 'user',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array(),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1105',
			),
			/*
			 * The terms query below is a faithful port of WordPress/ai PR #1099
			 * (author @jorgefilipecosta).
			 *
			 * Tier: experimental, incubating under the `wp/` prefix — the proposal
			 * below is the PR's paper trail, but the ability has NOT landed in
			 * core (`core_since` stays null and flips when core lands it), so its
			 * name and schema carry no promises yet (NAME-LIFECYCLE.md). Its
			 * `content` category is core-registered (the package declares it on
			 * pre-7.2 hosts), and the taxonomy opt-in `show_in_abilities` is
			 * native on 7.2+ and polyfilled on 6.9-7.1.
			 */
			'wp/terms-query'      => array(
				'name'           => 'wp/terms-query',
				'class'          => Terms\Terms_Query::class,
				'category'       => 'content',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array( 'taxonomy_show_in_abilities' ),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1099',
			),
			/*
			 * The term writes below are a faithful port of WordPress/ai PR #1103
			 * (author @jorgefilipecosta) on top of the `wp/terms-query` field
			 * projection (PR #10): the abilities write terms of the same exposed
			 * taxonomies and answer with them through the same contract, limited
			 * by `fields`; a delete returns the deleted term under `previous`,
			 * and a delete only runs with `force` because terms cannot be trashed.
			 * The `taxonomy` input is an optional guard on update and delete, and
			 * the default category is not protected by any code of its own: core's
			 * capability mapping denies `delete_term` against the term named by
			 * the `default_{$taxonomy}` option, and `wp_delete_term()` refuses it.
			 * The write helpers share the projection by verbatim copy (the
			 * media-write sharing pattern, as Terms_Query is a hand-authored wp/*
			 * class), with `private` relaxed to `protected` at the copy site.
			 *
			 * Tier: experimental, incubating under the `wp/` prefix — the proposal
			 * below is the PR's paper trail, but the abilities have NOT landed in
			 * core (`core_since` stays null and flips when core lands them), so
			 * their names and schemas carry no promises yet (NAME-LIFECYCLE.md).
			 * Their `content` category is core-registered (the package declares
			 * it on pre-7.2 hosts), and the taxonomy opt-in they ride is the
			 * `taxonomy_show_in_abilities` support feature, as for the query.
			 */
			'wp/term-create'      => array(
				'name'           => 'wp/term-create',
				'class'          => Terms\Term_Create::class,
				'category'       => 'content',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array( 'taxonomy_show_in_abilities' ),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1103',
			),
			'wp/term-update'      => array(
				'name'           => 'wp/term-update',
				'class'          => Terms\Term_Update::class,
				'category'       => 'content',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array( 'taxonomy_show_in_abilities' ),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1103',
			),
			'wp/term-delete'      => array(
				'name'           => 'wp/term-delete',
				'class'          => Terms\Term_Delete::class,
				'category'       => 'content',
				'tier'           => self::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array( 'taxonomy_show_in_abilities' ),
				'renamed_from'   => null,
				'proposal'       => 'https://github.com/WordPress/ai/pull/1103',
			),
		);
	}

	/**
	 * Returns a single manifest entry by ability name.
	 *
	 * @param string $name The ability name, including its tier prefix.
	 * @return array<string, mixed>|null The entry, or null when the name is not in the manifest.
	 */
	public static function get( string $name ): ?array {
		$all = self::all();

		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * Returns the manifest ability names, in manifest order.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		return array_keys( self::all() );
	}
}
