<?php
/**
 * Internal Links WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Internal_Links;

use WP_Error;
use WP_Query;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Internal_Links\Internal_Links as Internal_Links_Experiment;

use function WordPress\AI\normalize_content;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal Links WordPress Ability.
 *
 * Receives the current post content and returns up to `max_suggestions`
 * internal link suggestions, each using an exact phrase from the content
 * as anchor text.
 *
 * @since x.x.x
 */
class Internal_Links extends Abstract_Ability {

	/**
	 * Maximum number of posts to include in the site index sent to the AI.
	 *
	 * Kept low to stay token-efficient.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const SITE_INDEX_LIMIT = 200;

	/**
	 * Absolute cap on the number of suggestions that can be requested.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const MAX_SUGGESTIONS_CAP = 10;

	/**
	 * Default maximum number of suggestions to return.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_SUGGESTIONS = 5;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_content'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
					'description'       => esc_html__( 'The HTML content of the post being edited.', 'ai' ),
				),
				'post_id'          => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'ID of the post being edited.', 'ai' ),
				),
				'max_suggestions'  => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'Maximum number of link suggestions to return (1–10).', 'ai' ),
					'default'           => self::DEFAULT_MAX_SUGGESTIONS,
				),
				'excluded_anchors' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => esc_html__( 'Anchor texts already hyperlinked in the post that should not be suggested again.', 'ai' ),
					'default'     => array(),
				),
			),
			'required'   => array( 'post_content', 'post_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'        => 'object',
			'description' => esc_html__( 'Internal link suggestions for the post.', 'ai' ),
			'properties'  => array(
				'suggestions' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'anchor_text' => array(
								'type'        => 'string',
								'description' => esc_html__( 'Exact phrase from the post content to use as anchor text.', 'ai' ),
							),
							'url'         => array(
								'type'        => 'string',
								'description' => esc_html__( 'Permalink of the target post or page.', 'ai' ),
							),
							'title'       => array(
								'type'        => 'string',
								'description' => esc_html__( 'Title of the target post or page.', 'ai' ),
							),
							'context'     => array(
								'type'        => 'string',
								'description' => esc_html__( 'The sentence or clause from the post that contains the anchor text.', 'ai' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return array{suggestions: list<array{anchor_text: string, url: string, title: string, context: string}>}|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'post_content'     => '',
				'post_id'          => 0,
				'max_suggestions'  => self::DEFAULT_MAX_SUGGESTIONS,
				'excluded_anchors' => array(),
			)
		);

		$post_content     = wp_kses_post( (string) $args['post_content'] );
		$post_id          = absint( $args['post_id'] );
		$max_suggestions  = min( absint( $args['max_suggestions'] ), self::MAX_SUGGESTIONS_CAP );
		$excluded_anchors = is_array( $args['excluded_anchors'] )
			? array_filter( array_map( 'sanitize_text_field', $args['excluded_anchors'] ) )
			: array();

		if ( empty( $post_content ) ) {
			return new WP_Error(
				'post_content_required',
				esc_html__( 'Post content is required to suggest internal links.', 'ai' )
			);
		}

		if ( $max_suggestions < 1 ) {
			$max_suggestions = self::DEFAULT_MAX_SUGGESTIONS;
		}

		// Convert HTML to plain text for anchor text matching.
		$plain_text = normalize_content( wp_strip_all_tags( $post_content ) );

		if ( empty( trim( $plain_text ) ) ) {
			return array( 'suggestions' => array() );
		}

		// Build the list of linkable posts/pages from this site.
		$site_index = $this->build_site_index( $post_id );

		if ( empty( $site_index ) ) {
			return array( 'suggestions' => array() );
		}

		$prompt         = $this->create_prompt( $plain_text, $site_index, $max_suggestions, $excluded_anchors );
		$prompt_builder = $this->get_prompt_builder( $prompt );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$raw = $prompt_builder->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( empty( $raw ) ) {
			return array( 'suggestions' => array() );
		}

		$suggestions = $this->parse_and_validate_response( (string) $raw, $plain_text, $site_index, $max_suggestions );

		return array( 'suggestions' => $suggestions );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	protected function permission_callback( $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( ! $post_id ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to use AI internal link suggestions.', 'ai' )
				);
			}

			return true;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				/* translators: %d: Post ID. */
				sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to run AI internal link suggestions on this post.', 'ai' )
			);
		}

		$post_type     = get_post_type( $post_id );
		$post_type_obj = $post_type ? get_post_type_object( $post_type ) : null;

		return $post_type_obj && ! empty( $post_type_obj->show_in_rest );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Returns the JSON schema used for structured output generation.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> JSON schema for an array of suggestions.
	 */
	private function suggestions_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'suggestions' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'anchor_text' => array( 'type' => 'string' ),
							'url'         => array( 'type' => 'string' ),
							'title'       => array( 'type' => 'string' ),
							'context'     => array( 'type' => 'string' ),
						),
						'required'             => array( 'anchor_text', 'url', 'title', 'context' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'suggestions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds a compact site index of published posts and pages for the AI prompt.
	 *
	 * Excludes the current post being edited.
	 *
	 * @since x.x.x
	 *
	 * @param int $exclude_post_id The ID of the post currently being edited.
	 * @return list<array{url: string, title: string}> List of linkable posts.
	 */
	private function build_site_index( int $exclude_post_id ): array {
		$query = new WP_Query(
			array(
				'post_type'              => array( 'post', 'page' ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::SITE_INDEX_LIMIT,
				'post__not_in'           => $exclude_post_id ? array( $exclude_post_id ) : array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_post__not_in, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
			)
		);

		$index = array();

		foreach ( $query->posts as $id ) {
			$title = get_the_title( $id );
			$url   = get_permalink( $id );

			if ( ! $title || ! $url ) {
				continue;
			}

			$index[] = array(
				'url'   => esc_url_raw( $url ),
				'title' => sanitize_text_field( $title ),
			);
		}

		return $index;
	}

	/**
	 * Builds the prompt string to send to the AI.
	 *
	 * @since x.x.x
	 *
	 * @param string                                   $plain_text        Plain-text post content.
	 * @param list<array{url: string, title: string}>  $site_index        List of linkable posts.
	 * @param int                                      $max_suggestions   Maximum number of suggestions.
	 * @param list<string>                             $excluded_anchors  Anchor texts already hyperlinked in the post.
	 * @return string The assembled prompt.
	 */
	private function create_prompt( string $plain_text, array $site_index, int $max_suggestions, array $excluded_anchors = array() ): string {
		$index_lines = array();
		foreach ( $site_index as $entry ) {
			$index_lines[] = sprintf( '- %s <%s>', $entry['title'], $entry['url'] );
		}

		$parts   = array();
		$parts[] = '<post-content>' . $plain_text . '</post-content>';
		$parts[] = '<site-index>' . implode( "\n", $index_lines ) . '</site-index>';
		$parts[] = '<max-suggestions>' . $max_suggestions . '</max-suggestions>';

		if ( ! empty( $excluded_anchors ) ) {
			$anchor_lines = array();
			foreach ( $excluded_anchors as $anchor ) {
				$anchor_lines[] = '- ' . $anchor;
			}
			$parts[] = '<already-linked>' . implode( "\n", $anchor_lines ) . '</already-linked>';
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Gets a configured prompt builder for the internal links suggestion.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The assembled prompt.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->as_json_response( $this->suggestions_schema() );

		$prompt_builder = $this->set_provider_model_preference( $prompt_builder, Internal_Links_Experiment::class );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Internal link suggestions could not be generated. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}

	/**
	 * Parses the raw AI JSON response and validates each suggestion.
	 *
	 * Validation rules:
	 * - anchor_text must exist verbatim in the plain-text content.
	 * - url must be present in the site index.
	 * - No duplicate anchor texts or URLs.
	 * - Capped at max_suggestions.
	 *
	 * @since x.x.x
	 *
	 * @param string                             $raw             Raw JSON string from the AI.
	 * @param string                             $plain_text      Plain-text post content.
	 * @param list<array{url: string, title: string}> $site_index List of linkable posts.
	 * @param int                                $max_suggestions Maximum number of suggestions.
	 * @return list<array{anchor_text: string, url: string, title: string, context: string}>
	 */
	private function parse_and_validate_response( string $raw, string $plain_text, array $site_index, int $max_suggestions ): array {
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['suggestions'] ) || ! is_array( $decoded['suggestions'] ) ) {
			return array();
		}

		// Build a fast URL lookup set from the site index.
		$valid_urls = array();
		foreach ( $site_index as $entry ) {
			$valid_urls[ $entry['url'] ] = true;
		}

		$suggestions  = array();
		$seen_anchors = array();
		$seen_urls    = array();

		foreach ( $decoded['suggestions'] as $item ) {
			if ( count( $suggestions ) >= $max_suggestions ) {
				break;
			}

			if (
				! is_array( $item ) ||
				empty( $item['anchor_text'] ) ||
				empty( $item['url'] ) ||
				empty( $item['title'] ) ||
				! is_string( $item['anchor_text'] ) ||
				! is_string( $item['url'] ) ||
				! is_string( $item['title'] )
			) {
				continue;
			}

			$anchor_text = sanitize_text_field( $item['anchor_text'] );
			$url         = esc_url_raw( $item['url'] );
			$title       = sanitize_text_field( $item['title'] );
			$context     = sanitize_text_field( $item['context'] ?? '' );

			// Anchor text must exist verbatim in the post content.
			if ( ! str_contains( $plain_text, $anchor_text ) ) {
				continue;
			}

			// URL must come from the site index.
			if ( ! isset( $valid_urls[ $url ] ) ) {
				continue;
			}

			// No duplicate anchor texts.
			if ( isset( $seen_anchors[ $anchor_text ] ) ) {
				continue;
			}

			// No duplicate URLs.
			if ( isset( $seen_urls[ $url ] ) ) {
				continue;
			}

			$seen_anchors[ $anchor_text ] = true;
			$seen_urls[ $url ]            = true;

			$suggestions[] = array(
				'anchor_text' => $anchor_text,
				'url'         => $url,
				'title'       => $title,
				'context'     => $context,
			);
		}

		return $suggestions;
	}
}
