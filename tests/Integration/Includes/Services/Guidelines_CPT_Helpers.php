<?php
/**
 * Shared helpers for tests that exercise the Guidelines CPT.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Services
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Services;

use WordPress\AI\Services\Guidelines;

/**
 * Provides registration and factory helpers for the guidelines CPT.
 *
 * Consumed by test classes that need to populate guidelines posts and meta
 * without duplicating boilerplate across each file.
 *
 * @since 0.8.0
 */
trait Guidelines_CPT_Helpers {

	/**
	 * Meta key mapping for guideline categories.
	 *
	 * @since 0.8.0
	 *
	 * @var array<string, string>
	 */
	private static array $guideline_category_meta_keys = array(
		'copy'       => '_guideline_copy',
		'images'     => '_guideline_images',
		'site'       => '_guideline_site',
		'additional' => '_guideline_additional',
	);

	/**
	 * Registers the guidelines CPT for testing.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	private function register_guidelines_cpt(): void {
		if ( post_type_exists( Guidelines::POST_TYPE ) ) {
			return;
		}

		// phpcs:disable WordPress.NamingConventions.ValidPostTypeSlug.ReservedPrefix, WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral
		register_post_type(
			Guidelines::POST_TYPE,
			array( 'public' => false )
		);
		// phpcs:enable WordPress.NamingConventions.ValidPostTypeSlug.ReservedPrefix, WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral
	}

	/**
	 * Creates a guidelines post with the given category meta values.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, string> $categories  Keyed array of category => guideline text.
	 * @param string                $post_status Optional. The post status. Defaults to 'publish'.
	 * @return int The created post ID.
	 */
	private function create_guidelines_post( array $categories, string $post_status = 'publish' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Guidelines::POST_TYPE,
				'post_status' => $post_status,
				'post_title'  => 'Content Guidelines',
			)
		);

		foreach ( $categories as $category => $value ) {
			if ( ! isset( self::$guideline_category_meta_keys[ $category ] ) ) {
				continue;
			}

			update_post_meta( $post_id, self::$guideline_category_meta_keys[ $category ], $value );
		}

		// Reset cache so the service picks up the new post.
		Guidelines::reset_cache();

		return $post_id;
	}
}
