<?php
/**
 * Shared helpers for tests that exercise the guidelines storage.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Services
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Services;

use WordPress\AI\Services\Guidelines;

/**
 * Provides registration and factory helpers for the `wp_knowledge` post type.
 *
 * Consumed by test classes that need to populate guideline rows without
 * duplicating boilerplate across each file.
 *
 * @since 0.8.0
 */
trait Guidelines_CPT_Helpers {

	/**
	 * Registers a minimal `wp_knowledge` post type for testing.
	 *
	 * The real registration lives in the Knowledge experiment (or in the
	 * Gutenberg plugin). These tests only read rows, so a bare post type with
	 * REST turned off is enough.
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
	 * Registers a minimal `wp_knowledge_type` taxonomy for testing.
	 *
	 * The real registration lives in the Knowledge experiment (or in the
	 * Gutenberg plugin).
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	private function register_guidelines_taxonomy(): void {
		if ( taxonomy_exists( Guidelines::TAXONOMY ) ) {
			return;
		}

		register_taxonomy(
			Guidelines::TAXONOMY,
			Guidelines::POST_TYPE,
			array( 'public' => false )
		);
	}

	/**
	 * Creates one guideline row per scope.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, string> $categories  Keyed array of scope => guideline text.
	 * @param string                $post_status Optional. The post status. Defaults to 'publish'.
	 * @return array<string, int> Created post IDs keyed by scope.
	 */
	private function create_guidelines_post( array $categories, string $post_status = 'publish' ): array {
		$ids = array();

		foreach ( $categories as $scope => $value ) {
			$ids[ $scope ] = $this->create_guideline_row(
				Guidelines::scope_slug( $scope ),
				$value,
				$post_status
			);
		}

		return $ids;
	}

	/**
	 * Creates a guideline row for a single block.
	 *
	 * @since x.x.x
	 *
	 * @param string $block_name Block name (e.g. 'core/paragraph').
	 * @param string $content    Guideline text.
	 * @return int The created post ID.
	 */
	private function create_block_guideline( string $block_name, string $content ): int {
		return $this->create_guideline_row(
			Guidelines::block_slug( $block_name ),
			$content
		);
	}

	/**
	 * Creates a single knowledge row with the given slug and content.
	 *
	 * When the knowledge type taxonomy is registered, the row gets the
	 * `guideline` term, mirroring what the canonical writer does on save.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug        Exact row slug.
	 * @param string $content     Row content.
	 * @param string $post_status Optional. The post status. Defaults to 'publish'.
	 * @return int The created post ID.
	 */
	private function create_guideline_row( string $slug, string $content, string $post_status = 'publish' ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'    => Guidelines::POST_TYPE,
				'post_status'  => $post_status,
				'post_name'    => $slug,
				'post_title'   => $slug,
				'post_content' => $content,
			)
		);

		if ( taxonomy_exists( Guidelines::TAXONOMY ) ) {
			wp_set_object_terms( $post_id, Guidelines::TERM_GUIDELINE, Guidelines::TAXONOMY );
		}

		Guidelines::reset_cache();

		return $post_id;
	}
}
