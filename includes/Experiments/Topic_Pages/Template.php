<?php
/**
 * Preset category archive template.
 *
 * @package WordPress\AI
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Topic_Pages;

/**
 * Reuses the theme's archive layout and WordPress's template registry.
 *
 * @since x.x.x
 */
final class Template {

	public const SLUG = 'topic-category';

	/**
	 * Adds the Topic blocks to a copy of the current theme's archive template.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		if ( ! wp_is_block_theme() ) {
			return;
		}

		$base = null;

		foreach ( array( 'category', 'archive', 'index' ) as $slug ) {
			$base = get_block_template( get_stylesheet() . '//' . $slug );

			if ( $base ) {
				break;
			}
		}

		if ( ! $base ) {
			return;
		}

		$content = $base->content;
		$guide   = '';

		foreach ( Store::ALL_SECTIONS as $section ) {
			if ( has_block( 'ai/topic-' . $section, $content ) ) {
				continue;
			}

			$guide .= '<!-- wp:ai/topic-' . $section . ' /-->';
		}

		if ( '' !== $guide ) {
			$count   = 0;
			$content = (string) preg_replace_callback(
				'/<!-- wp:(?:query-title|term-description)\b[^>]*\/-->/',
				static fn( $matches ) => $matches[0] . $guide,
				$content,
				1,
				$count
			);

			if ( ! $count ) {
				$content = (string) preg_replace_callback(
					'/<!-- wp:(?:query(?:\s|>)|pattern\s[^>]*query)[^>]*>/',
					static fn( $matches ) => $guide . $matches[0],
					$content,
					1,
					$count
				);
			}

			if ( ! $count ) {
				return;
			}
		}

		register_block_template(
			'ai//' . self::SLUG,
			array(
				'title'       => __( 'Topic category', 'ai' ),
				'description' => __( 'A category guide with a summary, overview, timeline, and the theme’s post list.', 'ai' ),
				'content'     => $content,
			)
		);
	}

	/**
	 * Applies the preset only to qualifying categories; preserves specific category templates.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $templates Template hierarchy.
	 * @return list<string> Filtered hierarchy.
	 */
	public function hierarchy( array $templates ): array {
		$term = get_queried_object();

		if ( ! wp_is_block_theme() || ! $term instanceof \WP_Term || ! ( new Sources() )->eligible( $term->term_id ) ) {
			return $templates;
		}

		foreach ( array( 'category-' . $term->slug, 'category-' . $term->term_id ) as $slug ) {
			if ( get_block_template( get_stylesheet() . '//' . $slug ) ) {
				return $templates;
			}
		}

		array_unshift( $templates, self::SLUG . '.php' );

		return $templates;
	}
}
