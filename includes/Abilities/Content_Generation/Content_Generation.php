<?php
/**
 * Post and page body content generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Generation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Content_Generation\Content_Generation as Content_Generation_Experiment;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post and page body content generation WordPress Ability.
 *
 * @since 1.0.0
 */
class Content_Generation extends Abstract_Ability {

	/**
	 * The default tone of the generated content.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected const TONE_DEFAULT = 'professional';

	/**
	 * The default target length, in words.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	protected const TARGET_LENGTH_DEFAULT = 900;

	/**
	 * The default post type to generate content for.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected const POST_TYPE_DEFAULT = 'post';

	/**
	 * The maximum number of continuation generations allowed.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	protected const MAX_CONTINUATIONS = 2;

	/**
	 * The minimum word gap that justifies a continuation generation.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	protected const CONTINUATION_THRESHOLD = 100;

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'copy' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'title'         => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The title of the post or page to generate content for.', 'ai' ),
				),
				'prompt'        => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'description'       => esc_html__( 'A brief describing the content that should be generated.', 'ai' ),
				),
				'keywords'      => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => esc_html__( 'Focus keywords to weave into the generated content.', 'ai' ),
				),
				'tone'          => array(
					'type'        => 'string',
					'enum'        => array( 'professional', 'casual', 'friendly', 'authoritative', 'technical' ),
					'default'     => self::TONE_DEFAULT,
					'description' => esc_html__( 'The tone of voice to use for the generated content.', 'ai' ),
				),
				'target_length' => array(
					'type'        => 'integer',
					'default'     => self::TARGET_LENGTH_DEFAULT,
					'description' => esc_html__( 'The approximate target length of the generated content, in words.', 'ai' ),
				),
				'post_type'     => array(
					'type'        => 'string',
					'enum'        => array( 'post', 'page' ),
					'default'     => self::POST_TYPE_DEFAULT,
					'description' => esc_html__( 'The post type to generate content for.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected function output_schema(): array {
		return array(
			'type'        => 'string',
			'description' => esc_html__( 'The generated post/page body content as HTML.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected function execute_callback( $input ) {
		// Default arguments.
		$args = wp_parse_args(
			$input,
			array(
				'title'         => '',
				'prompt'        => '',
				'keywords'      => array(),
				'tone'          => self::TONE_DEFAULT,
				'target_length' => self::TARGET_LENGTH_DEFAULT,
				'post_type'     => self::POST_TYPE_DEFAULT,
			)
		);

		$title         = is_string( $args['title'] ) ? trim( $args['title'] ) : '';
		$prompt        = is_string( $args['prompt'] ) ? trim( $args['prompt'] ) : '';
		$tone          = $this->sanitize_tone( (string) $args['tone'] );
		$target_length = max( 0, (int) $args['target_length'] );
		$post_type     = 'page' === $args['post_type'] ? 'page' : 'post';
		$keywords      = $this->sanitize_keywords( $args['keywords'] );

		// At least a title or a brief is required to generate content.
		if ( '' === $title && '' === $prompt ) {
			return new WP_Error(
				'input_required',
				esc_html__( 'A title or a brief is required to generate content.', 'ai' )
			);
		}

		$data = array(
			'title'         => $title,
			'prompt'        => $prompt,
			'keywords'      => $keywords,
			'tone'          => $tone,
			'target_length' => $target_length,
			'post_type'     => $post_type,
		);

		// Build the user-facing prompt and generate the initial draft.
		$prompt_text    = $this->build_prompt( $data );
		$prompt_builder = $this->get_prompt_builder( $prompt_text, $data );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$result = $prompt_builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$content = is_string( $result ) ? trim( $result ) : '';

		if ( '' === $content ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No content was generated.', 'ai' )
			);
		}

		// Extend the draft toward the target length if needed.
		$content = $this->maybe_continue( $content, $data );

		// Sanitize the assembled HTML before returning it.
		$content = wp_kses_post( $content );

		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No content was generated.', 'ai' )
			);
		}

		return $content;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected function permission_callback( $args ) {
		$is_page = isset( $args['post_type'] ) && 'page' === $args['post_type'];

		$has_permission = $is_page ? current_user_can( 'edit_pages' ) : current_user_can( 'edit_posts' );

		if ( ! $has_permission ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate content.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Sanitizes the requested tone against the supported enum.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tone The requested tone.
	 * @return string A supported tone, defaulting to the professional tone.
	 */
	private function sanitize_tone( string $tone ): string {
		$allowed = array( 'professional', 'casual', 'friendly', 'authoritative', 'technical' );
		$tone    = sanitize_text_field( $tone );

		return in_array( $tone, $allowed, true ) ? $tone : self::TONE_DEFAULT;
	}

	/**
	 * Sanitizes the keywords input into a clean list of strings.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $keywords The raw keywords input.
	 * @return array<int, string> The sanitized keywords.
	 */
	private function sanitize_keywords( $keywords ): array {
		if ( is_string( $keywords ) ) {
			$keywords = explode( ',', $keywords );
		}

		if ( ! is_array( $keywords ) ) {
			return array();
		}

		$clean = array();
		foreach ( $keywords as $keyword ) {
			$keyword = sanitize_text_field( (string) $keyword );
			if ( '' === $keyword ) {
				continue;
			}

			$clean[] = $keyword;
		}

		return $clean;
	}

	/**
	 * Builds the user-facing prompt text for the initial generation.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data The normalized generation data.
	 * @return string The prompt text.
	 */
	private function build_prompt( array $data ): string {
		$lines = array();

		if ( '' !== $data['title'] ) {
			/* translators: %s: The post type to generate (post or page). */
			$lines[] = sprintf( __( 'Write a %s with the title below.', 'ai' ), $data['post_type'] );
			$lines[] = 'Title: ' . $data['title'];
		} else {
			/* translators: %s: The post type to generate (post or page). */
			$lines[] = sprintf( __( 'Write a %s based on the brief below.', 'ai' ), $data['post_type'] );
		}

		if ( '' !== $data['prompt'] ) {
			$lines[] = 'Brief: ' . $data['prompt'];
		}

		if ( ! empty( $data['keywords'] ) ) {
			$lines[] = 'Focus keywords: ' . implode( ', ', $data['keywords'] ) . '.';
		}

		$lines[] = 'Tone: ' . $data['tone'] . '.';

		if ( $data['target_length'] > 0 ) {
			$lines[] = 'Target length: about ' . $data['target_length'] . ' words.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Counts the number of words in the given text, ignoring HTML markup.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text The text to count words in.
	 * @return int The number of words.
	 */
	private function count_words( string $text ): int {
		$plain = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );

		if ( '' === $plain ) {
			return 0;
		}

		$parts = preg_split( '/\s+/', $plain );

		return is_array( $parts ) ? count( $parts ) : 0;
	}

	/**
	 * Optionally issues continuation generations to extend the draft toward the target length.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $content The initial generated content.
	 * @param array<string, mixed> $data    The normalized generation data.
	 * @return string The (possibly extended) content.
	 */
	private function maybe_continue( string $content, array $data ): string {
		$target_length = (int) $data['target_length'];

		if ( $target_length <= 0 ) {
			return $content;
		}

		$continuations = 0;

		while ( $continuations < self::MAX_CONTINUATIONS ) {
			$current_words = $this->count_words( $content );
			$missing_words = $target_length - $current_words;

			if ( $missing_words <= self::CONTINUATION_THRESHOLD ) {
				break;
			}

			$continuation_prompt = $this->build_continuation_prompt( $content, $data, $current_words, $missing_words );
			$prompt_builder      = $this->get_prompt_builder( $continuation_prompt, $data );

			if ( is_wp_error( $prompt_builder ) ) {
				break;
			}

			$result = $prompt_builder->generate_text();

			if ( is_wp_error( $result ) ) {
				break;
			}

			$addition = is_string( $result ) ? trim( $result ) : '';

			if ( '' === $addition ) {
				break;
			}

			$content .= "\n" . $addition;
			++$continuations;
		}

		return $content;
	}

	/**
	 * Builds the prompt text for a continuation generation.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $content       The current draft content.
	 * @param array<string, mixed> $data          The normalized generation data.
	 * @param int                  $current_words The current draft word count.
	 * @param int                  $missing_words The number of words still needed.
	 * @return string The continuation prompt text.
	 */
	private function build_continuation_prompt( string $content, array $data, int $current_words, int $missing_words ): string {
		$tail  = mb_substr( $content, -8000 );
		$title = '' !== $data['title'] ? $data['title'] : '(continue with the established title)';

		return implode(
			"\n",
			array(
				sprintf( 'Continue the same WordPress %s seamlessly in English.', $data['post_type'] ),
				'',
				'Title: ' . $title,
				'Tone: ' . $data['tone'],
				'Current draft length: about ' . $current_words . ' words.',
				'Target length: about ' . $data['target_length'] . ' words total.',
				'Missing length to add: about ' . $missing_words . ' words.',
				'',
				'Continuation requirements:',
				'- Continue exactly where the current draft stops.',
				'- Do not restart the article, repeat earlier sections, or add a second introduction.',
				'- Preserve the same structure, voice, and HTML style.',
				'- Add the most valuable missing sections, depth, examples, and details needed to reach the target length.',
				'- Finish with a strong conclusion only if the current draft has not already concluded.',
				'- Return only the next HTML/content that should be appended after the current draft.',
				'',
				'Current draft tail (raw HTML/content to continue from):',
				'---',
				$tail,
				'---',
			)
		);
	}

	/**
	 * Gets a prompt builder for generating content.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $prompt The prompt to generate content from.
	 * @param array<string, mixed> $data   The normalized generation data exposed to the system instruction.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt, array $data ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction( 'system-instruction.php', $data ) )
			->using_temperature( 0.7 );

		$prompt_builder = $this->set_provider_model_preference( $prompt_builder, Content_Generation_Experiment::class );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Content generation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}
}
