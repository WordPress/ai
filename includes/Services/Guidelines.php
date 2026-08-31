<?php
/**
 * Guidelines service.
 *
 * Fetches and caches guidelines from the shared `wp_knowledge` post type.
 *
 * @package WordPress\AI\Services
 */

declare( strict_types=1 );

namespace WordPress\AI\Services;

use WP_Post;
use WP_Query;

/**
 * Guidelines service class.
 *
 * Provides a centralized interface for fetching and formatting guidelines from
 * the `wp_knowledge` post type. Each guideline lives in its own published row:
 * `guideline-{scope}` for a registry scope and `guideline-block-{block_name}`
 * for a per-block guideline, with the text in `post_content`.
 *
 * The post type is provided by whichever compatible plugin declared it. This
 * service only reads, so it works regardless of which plugin owns the storage.
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
	public const POST_TYPE = 'wp_knowledge';

	/**
	 * Taxonomy slug that splits knowledge rows by purpose.
	 *
	 * @since 1.0.1
	 *
	 * @var string
	 */
	public const TAXONOMY = 'wp_knowledge_type';

	/**
	 * Term slug for guideline-typed knowledge rows.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TERM_GUIDELINE = 'guideline';

	/**
	 * Slug prefix for a registry scope row.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const SCOPE_PREFIX = 'guideline-';

	/**
	 * Slug prefix for a per-block guideline row.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const BLOCK_PREFIX = 'guideline-block-';

	/**
	 * The registry scope that holds per-block guidelines.
	 *
	 * It has no single row of its own, so it is skipped when reading scopes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const BLOCKS_SCOPE = 'blocks';

	/**
	 * Singleton instance.
	 *
	 * @since 0.8.0
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Cached scope guidelines, keyed by scope slug.
	 *
	 * @since 0.8.0
	 *
	 * @var array<string, string>|false|null False means not yet fetched.
	 */
	private static $cached_guidelines = false;

	/**
	 * Cached per-block guidelines, keyed by block name.
	 *
	 * A null value means the block has been looked up and has no guideline.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string|null>
	 */
	private static array $cached_block_guidelines = array();

	/**
	 * Scope slugs used when the guideline scope registry is unavailable.
	 *
	 * @since x.x.x
	 *
	 * @var array<int, string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition
	private const FALLBACK_SCOPES = array( 'site', 'copy', 'images', 'additional' );

	/**
	 * XML tag names for each guideline scope.
	 *
	 * Scopes without an entry fall back to their own slug.
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
	 * Default maximum character length per guideline scope.
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
	 * @return bool True if the knowledge post type is registered.
	 */
	public function is_available(): bool {
		return post_type_exists( self::POST_TYPE );
	}

	/**
	 * Retrieves guidelines, optionally filtered by scope.
	 *
	 * @since 0.8.0
	 *
	 * @param string|null $category Optional. Guideline scope to retrieve ('site', 'copy', 'images', 'additional').
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

		if ( array_key_exists( $block_name, self::$cached_block_guidelines ) ) {
			return self::$cached_block_guidelines[ $block_name ];
		}

		$rows  = $this->fetch_rows( array( self::block_slug( $block_name ) ) );
		$value = reset( $rows );

		self::$cached_block_guidelines[ $block_name ] = false === $value || '' === $value ? null : $value;

		return self::$cached_block_guidelines[ $block_name ];
	}

	/**
	 * Formats guidelines as an XML-tagged string suitable for prompt injection.
	 *
	 * @since 0.8.0
	 *
	 * @param list<string> $categories Guideline scope slugs to include.
	 * @param string|null  $block_name Optional block name for block-specific guidelines.
	 * @return string Formatted guidelines XML string, or empty string if nothing to include.
	 */
	public function format_for_prompt( array $categories, ?string $block_name = null ): string {
		if ( ! $this->should_use_guidelines() ) {
			return '';
		}

		$guidelines = $this->fetch_guidelines();

		if ( null === $guidelines ) {
			$guidelines = array();
		}

		$max_length = $this->get_max_length();

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
		self::$cached_guidelines       = false;
		self::$cached_block_guidelines = array();
	}

	/**
	 * Builds the row slug for a registry scope.
	 *
	 * @since x.x.x
	 *
	 * @param string $scope Scope slug (e.g. 'copy').
	 * @return string Row slug (e.g. 'guideline-copy').
	 */
	private static function scope_slug( string $scope ): string {
		return self::SCOPE_PREFIX . $scope;
	}

	/**
	 * Builds the row slug for a per-block guideline.
	 *
	 * The namespace separator becomes `_` rather than `-`. Block names are
	 * `<namespace>/<name>` where both parts match `[a-z0-9-]+` and never contain
	 * `_`, so `_` keeps the mapping unique. Using `-` would collapse distinct
	 * names such as `foo/bar-baz` and `foo-bar/baz` onto the same slug. This
	 * matches the client (see routes/guidelines/data.ts).
	 *
	 * @since x.x.x
	 *
	 * @param string $block_name Exact block name (e.g. 'core/paragraph').
	 * @return string Row slug (e.g. 'guideline-block-core_paragraph').
	 */
	private static function block_slug( string $block_name ): string {
		return self::BLOCK_PREFIX . str_replace( '/', '_', $block_name );
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
		 */
		return (bool) apply_filters( 'wpai_use_guidelines', true );
	}

	/**
	 * Gets the maximum character length allowed per guideline.
	 *
	 * @since x.x.x
	 *
	 * @return int Maximum number of characters.
	 */
	private function get_max_length(): int {
		$max_length = function_exists( 'wp_guideline_max_length' )
			? wp_guideline_max_length()
			: self::DEFAULT_MAX_GUIDELINE_LENGTH;

		/**
		 * Filters the maximum character length per guideline scope.
		 *
		 * @since 0.8.0
		 *
		 * @param int $max_length The maximum character length per scope.
		 */
		return (int) apply_filters( 'wpai_max_guideline_length', $max_length );
	}

	/**
	 * Returns the guideline scope slugs to read.
	 *
	 * Uses the shared scope registry when it is available so scopes added by
	 * other plugins are picked up too. The `blocks` scope is skipped: it has no
	 * single row of its own.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, string> Scope slugs.
	 */
	private function get_scopes(): array {
		if ( ! function_exists( 'wp_guideline_scopes' ) ) {
			return self::FALLBACK_SCOPES;
		}

		$scopes = array_keys( wp_guideline_scopes() );

		return array_values(
			array_filter(
				$scopes,
				static function ( $scope ): bool {
					return is_string( $scope ) && self::BLOCKS_SCOPE !== $scope;
				}
			)
		);
	}

	/**
	 * Fetches the scope guidelines from the database, using cache when available.
	 *
	 * @since 0.8.0
	 *
	 * @return array<string, string>|null Keyed array of guidelines, or null when there are none.
	 */
	private function fetch_guidelines(): ?array {
		// Return cached result if available.
		if ( false !== self::$cached_guidelines ) {
			return self::$cached_guidelines;
		}

		$scopes = $this->get_scopes();

		$slug_to_scope = array();
		foreach ( $scopes as $scope ) {
			$slug_to_scope[ self::scope_slug( $scope ) ] = $scope;
		}

		$rows = $this->fetch_rows( array_keys( $slug_to_scope ) );

		$guidelines = array();
		foreach ( $rows as $slug => $content ) {
			if ( '' === $content || ! isset( $slug_to_scope[ $slug ] ) ) {
				continue;
			}

			$guidelines[ $slug_to_scope[ $slug ] ] = $content;
		}

		if ( empty( $guidelines ) ) {
			self::$cached_guidelines = null;
			return null;
		}

		self::$cached_guidelines = $guidelines;
		return $guidelines;
	}

	/**
	 * Reads published knowledge rows by exact slug.
	 *
	 * Only published rows are read. That is what the Settings → Guidelines page
	 * treats as canonical, and it keeps other users' private rows out of prompts.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $slugs Exact row slugs to look up.
	 * @return array<string, string> Row content keyed by slug. Missing rows are absent.
	 */
	private function fetch_rows( array $slugs ): array {
		if ( empty( $slugs ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'post_name__in'          => $slugs,
				'posts_per_page'         => count( $slugs ),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$rows = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$rows[ $post->post_name ] = $post->post_content;
		}

		return $rows;
	}
}
