<?php
/**
 * Guidelines service.
 *
 * Fetches and caches Guidelines from Gutenberg's wp_guideline CPT.
 *
 * @package WordPress\AI\Services
 */

declare( strict_types=1 );

namespace WordPress\AI\Services;

use WP_Query;

/**
 * Guidelines service class.
 *
 * Provides a centralized interface for fetching and formatting Guidelines
 * from the wp_guideline custom post type. Requires Gutenberg 23.0+.
 *
 * @since 0.8.0
 */
class Guidelines {

	/**
	 * Post type slug.
	 *
	 * @since 0.8.0
	 *
	 * @var string
	 */
	public const POST_TYPE = 'wp_guideline';

	/**
	 * Singleton instance.
	 *
	 * @since 0.8.0
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Cached guidelines data.
	 *
	 * @since 0.8.0
	 *
	 * @var array<string, string>|false|null False means not yet fetched.
	 */
	private static $cached_guidelines = false;

	/**
	 * Cached guidelines post ID.
	 *
	 * @since 0.8.0
	 *
	 * @var int|null
	 */
	private static ?int $cached_post_id = null;

	/**
	 * Post meta keys for each guideline category.
	 *
	 * @since 0.8.0
	 *
	 * @var array<string, string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition
	private const CATEGORY_META_KEYS = array(
		'copy'       => '_guideline_copy',
		'images'     => '_guideline_images',
		'site'       => '_guideline_site',
		'additional' => '_guideline_additional',
	);

	/**
	 * XML tag names for each guideline category.
	 *
	 * @since 0.8.0
	 *
	 * @var array<string, string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition
	private const CATEGORY_TAG_NAMES = array(
		'site'       => 'site-context',
		'copy'       => 'copy-guidelines',
		'images'     => 'image-guidelines',
		'additional' => 'additional-guidelines',
	);

	/**
	 * Default maximum character length per guideline category.
	 *
	 * @since 0.8.0
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_GUIDELINE_LENGTH = 5000;

	/**
	 * Gets the singleton instance.
	 *
	 * @since 0.8.0
	 *
	 * @return self The singleton instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton pattern.
	 *
	 * @since 0.8.0
	 */
	private function __construct() {}

	/**
	 * Checks if the Guidelines feature is available.
	 *
	 * @since 0.8.0
	 *
	 * @return bool True if the guidelines CPT is registered.
	 */
	public function is_available(): bool {
		return post_type_exists( self::POST_TYPE );
	}

	/**
	 * Retrieves guidelines, optionally filtered by category.
	 *
	 * @since 0.8.0
	 *
	 * @param string|null $category Optional. Guideline category to retrieve ('site', 'copy', 'images', 'additional').
	 * @return array<string, string>|null Keyed array of guidelines, or null when unavailable.
	 */
	public function get_guidelines( ?string $category = null ): ?array {
		if ( ! $this->should_use_guidelines() ) {
			return null;
		}

		$guidelines = $this->fetch_guidelines();

		if ( null === $guidelines ) {
			return null;
		}

		if ( null !== $category ) {
			if ( ! isset( $guidelines[ $category ] ) ) {
				return null;
			}
			return array( $category => $guidelines[ $category ] );
		}

		return $guidelines;
	}

	/**
	 * Retrieves guidelines for a specific block type.
	 *
	 * @since 0.8.0
	 *
	 * @param string $block_name The block name (e.g., 'core/paragraph').
	 * @return string|null The block-specific guidelines, or null if unavailable.
	 */
	public function get_block_guidelines( string $block_name ): ?string {
		if ( ! $this->should_use_guidelines() ) {
			return null;
		}

		$this->fetch_guidelines();

		if ( null === self::$cached_post_id ) {
			return null;
		}

		$sanitized_name = str_replace( '/', '_', $block_name );
		$meta_key       = '_guideline_block_' . $sanitized_name;
		$value          = get_post_meta( self::$cached_post_id, $meta_key, true );

		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Formats guidelines as an XML-tagged string suitable for prompt injection.
	 *
	 * @since 0.8.0
	 *
	 * @param list<string> $categories  Guideline category slugs to include.
	 * @param string|null  $block_name  Optional block name for block-specific guidelines.
	 * @return string Formatted guidelines XML string, or empty string if nothing to include.
	 */
	public function format_for_prompt( array $categories, ?string $block_name = null ): string {
		if ( ! $this->should_use_guidelines() ) {
			return '';
		}

		$guidelines = $this->fetch_guidelines();

		if ( null === $guidelines ) {
			return '';
		}

		/**
		 * Filters the maximum character length per guideline category.
		 *
		 * @since 0.8.0
		 *
		 * @param int $max_length The maximum character length per category. Default 2000.
		 * @return int The filtered maximum length.
		 */
		$max_length = (int) apply_filters( 'wpai_max_guideline_length', self::DEFAULT_MAX_GUIDELINE_LENGTH );

		$parts = array();

		foreach ( $categories as $category ) {
			if ( ! isset( $guidelines[ $category ] ) || '' === $guidelines[ $category ] ) {
				continue;
			}

			$tag_name = self::CATEGORY_TAG_NAMES[ $category ] ?? $category;
			$content  = wp_strip_all_tags( $guidelines[ $category ] );

			if ( mb_strlen( $content, 'UTF-8' ) > $max_length ) {
				$content = mb_substr( $content, 0, $max_length, 'UTF-8' );
			}

			$parts[] = '<' . $tag_name . '>' . $content . '</' . $tag_name . '>';
		}

		// Add block-specific guidelines if requested.
		if ( null !== $block_name ) {
			$block_guidelines = $this->get_block_guidelines( $block_name );
			if ( null !== $block_guidelines ) {
				$block_content = wp_strip_all_tags( $block_guidelines );
				if ( mb_strlen( $block_content, 'UTF-8' ) > $max_length ) {
					$block_content = mb_substr( $block_content, 0, $max_length, 'UTF-8' );
				}
				$parts[] = '<block-guidelines>' . $block_content . '</block-guidelines>';
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return '<guidelines>' . "\n" . implode( "\n", $parts ) . "\n" . '</guidelines>';
	}

	/**
	 * Resets the internal cache. Intended for use in tests.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cached_guidelines = false;
		self::$cached_post_id    = null;
	}

	/**
	 * Checks whether guidelines should be used.
	 *
	 * @since 0.8.0
	 *
	 * @return bool True if guidelines should be used.
	 */
	private function should_use_guidelines(): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		/**
		 * Filters whether guidelines integration is enabled.
		 *
		 * @since 0.8.0
		 *
		 * @param bool $use_guidelines Whether to use guidelines. Default true.
		 * @return bool Whether to use guidelines.
		 */
		return (bool) apply_filters( 'wpai_use_guidelines', true );
	}

	/**
	 * Fetches guidelines from the database, using cache when available.
	 *
	 * @since 0.8.0
	 *
	 * @return array<string, string>|null Keyed array of guidelines, or null when unavailable.
	 */
	private function fetch_guidelines(): ?array {
		// Return cached result if available.
		if ( false !== self::$cached_guidelines ) {
			return self::$cached_guidelines;
		}

		// Gutenberg saves guidelines as 'draft' by default; both statuses are valid.
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft' ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$post = $query->posts[0] ?? null;

		if ( ! $post instanceof \WP_Post ) {
			self::$cached_guidelines = null;
			return null;
		}

		self::$cached_post_id = $post->ID;

		$guidelines = array();

		foreach ( self::CATEGORY_META_KEYS as $category => $meta_key ) {
			$value = get_post_meta( $post->ID, $meta_key, true );
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			$guidelines[ $category ] = $value;
		}

		if ( empty( $guidelines ) ) {
			self::$cached_guidelines = null;
			return null;
		}

		self::$cached_guidelines = $guidelines;
		return $guidelines;
	}
}
