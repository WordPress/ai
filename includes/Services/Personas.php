<?php
/**
 * Personas service.
 *
 * Resolves and formats the persona (role, audience, and brand voice) applied to
 * AI-generated content.
 *
 * @package WordPress\AI\Services
 */

declare( strict_types=1 );

namespace WordPress\AI\Services;

use WP_Post;
use WP_Query;

/**
 * Personas service class.
 *
 * Provides a centralized registry for personas and formats the active persona
 * for prompt injection. Personas come from three sources, merged in this order:
 *
 * 1. Built-in personas shipped with the plugin.
 * 2. User-defined personas stored in the `wpai_persona` post type.
 * 3. The `wpai_personas` filter, so plugins and themes can add, adjust, or
 *    remove any of the above.
 *
 * @since x.x.x
 */
class Personas {

	/**
	 * Post type slug used to store user-defined personas.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const POST_TYPE = 'wpai_persona';

	/**
	 * Post meta key holding the per-post persona override.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const META_KEY = 'wpai_persona';

	/**
	 * Reserved persona ID meaning "apply no persona".
	 *
	 * Stored as a per-post override when an author wants a post to opt out of
	 * the site-wide default rather than inherit it.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const NONE = 'none';

	/**
	 * Option name holding the site-wide default persona ID.
	 *
	 * Mirrors the option name the Personas experiment registers through
	 * `Abstract_Feature::get_field_option_name()`. Kept as a literal so the
	 * service stays usable without loading the experiment class.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const DEFAULT_OPTION = 'wpai_feature_personas_field_default_persona';

	/**
	 * Default maximum character length per persona field.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_PERSONA_LENGTH = 2000;

	/**
	 * XML tag names for each persona field, in prompt order.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition
	private const FIELD_TAG_NAMES = array(
		'label'    => 'name',
		'role'     => 'role',
		'voice'    => 'voice',
		'audience' => 'audience',
	);

	/**
	 * Singleton instance.
	 *
	 * @since x.x.x
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Cached persona registry.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, array<string, string>>|null Null means not yet built.
	 */
	private static ?array $cached_personas = null;

	/**
	 * Post IDs for the abilities currently executing, innermost last.
	 *
	 * Abilities receive the post they act on as part of their input rather than
	 * as a system instruction argument, so the executing post ID is captured
	 * centrally and read back when the persona is resolved. A stack keeps
	 * nested ability executions from clobbering each other's context.
	 *
	 * @since x.x.x
	 *
	 * @var list<int|null>
	 */
	private static array $context_stack = array();

	/**
	 * Gets the singleton instance.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	private function __construct() {}

	/**
	 * Checks if the Personas feature is available.
	 *
	 * The post type is only registered while the Personas experiment is
	 * enabled, so this doubles as the experiment's enablement check.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the personas post type is registered.
	 */
	public function is_available(): bool {
		return post_type_exists( self::POST_TYPE );
	}

	/**
	 * Retrieves all registered personas, keyed by persona ID.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array<string, string>> Registered personas.
	 */
	public function get_personas(): array {
		if ( null !== self::$cached_personas ) {
			return self::$cached_personas;
		}

		$personas = array_merge( $this->get_built_in_personas(), $this->fetch_persona_posts() );

		/**
		 * Filters the registered personas.
		 *
		 * Personas are keyed by ID. Each persona is an array with a `label` and
		 * any of the `role`, `voice`, and `audience` keys, all of which are
		 * plain text.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, array<string, string>> $personas Registered personas, keyed by ID.
		 */
		$personas = apply_filters( 'wpai_personas', $personas );

		if ( ! is_array( $personas ) ) {
			$personas = array();
		}

		self::$cached_personas = $this->normalize_personas( $personas );

		return self::$cached_personas;
	}

	/**
	 * Retrieves a single persona by ID.
	 *
	 * @since x.x.x
	 *
	 * @param string $id The persona ID.
	 * @return array<string, string>|null The persona, or null when it is not registered.
	 */
	public function get_persona( string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}

		$personas = $this->get_personas();

		return $personas[ $id ] ?? null;
	}

	/**
	 * Returns the site-wide default persona ID.
	 *
	 * @since x.x.x
	 *
	 * @return string The default persona ID, or an empty string when none is set.
	 */
	public function get_default_persona_id(): string {
		$default = get_option( self::DEFAULT_OPTION, '' );

		return is_string( $default ) ? $default : '';
	}

	/**
	 * Resolves the persona ID that applies to the current generation.
	 *
	 * Resolution order is per-post override, then site-wide default. An empty
	 * string means no persona applies and nothing is injected. A post set to
	 * the reserved {@see Personas::NONE} value opts out of the site-wide
	 * default instead of inheriting it.
	 *
	 * @since x.x.x
	 *
	 * @param int|null $post_id Optional. Post the generation relates to.
	 * @return string The resolved persona ID, or an empty string.
	 */
	public function get_active_persona_id( ?int $post_id = null ): string {
		if ( null === $post_id ) {
			$post_id = self::get_context_post_id();
		}

		$persona_id = '';

		if ( null !== $post_id && $post_id > 0 ) {
			$meta = get_post_meta( $post_id, self::META_KEY, true );

			if ( is_string( $meta ) ) {
				$persona_id = $meta;
			}
		}

		if ( '' === $persona_id ) {
			$persona_id = $this->get_default_persona_id();
		}

		/**
		 * Filters the persona ID applied to the current generation.
		 *
		 * @since x.x.x
		 *
		 * @param string   $persona_id The resolved persona ID, or an empty string for none.
		 * @param int|null $post_id    The post the generation relates to, if known.
		 */
		$persona_id = apply_filters( 'wpai_active_persona', $persona_id, $post_id );

		return is_string( $persona_id ) ? $persona_id : '';
	}

	/**
	 * Formats the active persona as an XML-tagged string for prompt injection.
	 *
	 * @since x.x.x
	 *
	 * @param int|null $post_id Optional. Post the generation relates to.
	 * @return string Formatted persona XML string, or an empty string when nothing applies.
	 */
	public function format_for_prompt( ?int $post_id = null ): string {
		if ( ! $this->should_use_personas() ) {
			return '';
		}

		$persona = $this->get_persona( $this->get_active_persona_id( $post_id ) );

		if ( null === $persona ) {
			return '';
		}

		/**
		 * Filters the maximum character length per persona field.
		 *
		 * @since x.x.x
		 *
		 * @param int $max_length The maximum character length per field. Default 2000.
		 */
		$max_length = (int) apply_filters( 'wpai_max_persona_length', self::DEFAULT_MAX_PERSONA_LENGTH );

		$parts = array();

		foreach ( self::FIELD_TAG_NAMES as $key => $tag_name ) {
			if ( ! isset( $persona[ $key ] ) || '' === $persona[ $key ] ) {
				continue;
			}

			$content = $persona[ $key ];

			if ( mb_strlen( $content, 'UTF-8' ) > $max_length ) {
				$content = mb_substr( $content, 0, $max_length, 'UTF-8' );
			}

			$parts[] = '<' . $tag_name . '>' . $content . '</' . $tag_name . '>';
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return '<persona>' . "\n" . implode( "\n", $parts ) . "\n" . '</persona>';
	}

	/**
	 * Records the post an ability is currently executing against.
	 *
	 * @since x.x.x
	 *
	 * @param int|null $post_id The post ID, or null when the ability has no post context.
	 * @return void
	 */
	public static function push_context( ?int $post_id ): void {
		self::$context_stack[] = $post_id;
	}

	/**
	 * Discards the innermost recorded ability context.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public static function pop_context(): void {
		array_pop( self::$context_stack );
	}

	/**
	 * Returns the post ID for the innermost executing ability.
	 *
	 * @since x.x.x
	 *
	 * @return int|null The post ID, or null when there is no post context.
	 */
	public static function get_context_post_id(): ?int {
		if ( empty( self::$context_stack ) ) {
			return null;
		}

		return self::$context_stack[ count( self::$context_stack ) - 1 ];
	}

	/**
	 * Resets the internal cache. Intended for use in tests.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cached_personas = null;
		self::$context_stack   = array();
	}

	/**
	 * Checks whether personas should be used.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if personas should be used.
	 */
	private function should_use_personas(): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		/**
		 * Filters whether persona integration is enabled.
		 *
		 * @since x.x.x
		 *
		 * @param bool $use_personas Whether to use personas. Default true.
		 */
		return (bool) apply_filters( 'wpai_use_personas', true );
	}

	/**
	 * Returns the personas shipped with the plugin.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array<string, string>> Built-in personas, keyed by ID.
	 */
	private function get_built_in_personas(): array {
		return array(
			'professional'   => array(
				'label'    => __( 'Professional', 'ai' ),
				'role'     => __( 'A subject-matter expert writing on behalf of an established organization.', 'ai' ),
				'voice'    => __( 'Measured, precise, and confident. Favor plain business language over jargon, keep sentences direct, and avoid exclamation marks or hype.', 'ai' ),
				'audience' => __( 'Busy professionals who want the substance quickly.', 'ai' ),
			),
			'conversational' => array(
				'label'    => __( 'Friendly and conversational', 'ai' ),
				'role'     => __( 'A knowledgeable guide talking with the reader one to one.', 'ai' ),
				'voice'    => __( 'Warm, approachable, and informal. Use second person, contractions, and short sentences. Stay genuine rather than chatty for its own sake.', 'ai' ),
				'audience' => __( 'General readers who may be new to the topic.', 'ai' ),
			),
			'technical'      => array(
				'label'    => __( 'Technical expert', 'ai' ),
				'role'     => __( 'An experienced practitioner writing for other practitioners.', 'ai' ),
				'voice'    => __( 'Exact and unembellished. Use correct technical terminology, prefer specifics over generalities, and never overstate certainty.', 'ai' ),
				'audience' => __( 'Developers and technical readers who already know the fundamentals.', 'ai' ),
			),
			'journalistic'   => array(
				'label'    => __( 'Journalistic', 'ai' ),
				'role'     => __( 'A reporter covering the subject for a general publication.', 'ai' ),
				'voice'    => __( 'Neutral, factual, and structured with the most important information first. Attribute claims and avoid promotional language entirely.', 'ai' ),
				'audience' => __( 'Readers looking for an impartial account.', 'ai' ),
			),
			'playful'        => array(
				'label'    => __( 'Playful', 'ai' ),
				'role'     => __( 'An enthusiastic writer for a brand that does not take itself too seriously.', 'ai' ),
				'voice'    => __( 'Energetic and lightly humorous. Keep it brief and human, and never let a joke get in the way of the point.', 'ai' ),
				'audience' => __( 'Readers who enjoy a personable, informal brand.', 'ai' ),
			),
		);
	}

	/**
	 * Fetches user-defined personas from the personas post type.
	 *
	 * The post title becomes the persona label and the post content describes
	 * the voice. Personas are keyed by post slug, so a user-defined persona
	 * replaces a built-in one when the slugs match.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array<string, string>> User-defined personas, keyed by ID.
	 */
	private function fetch_persona_posts(): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => 100,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$personas = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$id = $post->post_name;

			if ( '' === $id ) {
				$id = 'persona-' . $post->ID;
			}

			$personas[ $id ] = array(
				'label'    => $post->post_title,
				'voice'    => $post->post_content,
				'role'     => (string) get_post_meta( $post->ID, '_wpai_persona_role', true ),
				'audience' => (string) get_post_meta( $post->ID, '_wpai_persona_audience', true ),
			);
		}

		return $personas;
	}

	/**
	 * Normalizes a persona registry into plain text entries with a label.
	 *
	 * Personas can come from the database or from third-party filters, so every
	 * field is stripped of markup and collapsed to a single line before it can
	 * reach a prompt.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $personas Raw persona registry.
	 * @return array<string, array<string, string>> Normalized personas, keyed by ID.
	 */
	private function normalize_personas( array $personas ): array {
		$normalized = array();

		foreach ( $personas as $id => $persona ) {
			$id = sanitize_key( (string) $id );

			if ( '' === $id || self::NONE === $id || ! is_array( $persona ) ) {
				continue;
			}

			$entry = array();

			foreach ( array_keys( self::FIELD_TAG_NAMES ) as $key ) {
				if ( ! isset( $persona[ $key ] ) || ! is_string( $persona[ $key ] ) ) {
					continue;
				}

				$value = $this->normalize_persona_text( $persona[ $key ] );

				if ( '' === $value ) {
					continue;
				}

				$entry[ $key ] = $value;
			}

			// A persona without a label cannot be presented for selection.
			if ( ! isset( $entry['label'] ) ) {
				continue;
			}

			$normalized[ $id ] = $entry;
		}

		return $normalized;
	}

	/**
	 * Reduces persona text to plain text safe for prompt injection.
	 *
	 * @since x.x.x
	 *
	 * @param string $text The raw text.
	 * @return string The normalized text.
	 */
	private function normalize_persona_text( string $text ): string {
		$text = wp_strip_all_tags( strip_shortcodes( $text ) );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}
}
