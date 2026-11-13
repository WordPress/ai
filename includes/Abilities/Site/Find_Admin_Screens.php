<?php
/**
 * The `ai/find-admin-screens` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Site;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Find_Admin_Screens
 *
 * Registers the read-only `ai/find-admin-screens` ability, which turns a short
 * description of a task, such as "hide my site from google", into links to the
 * admin screens that handle it. It is how the AI Workspace answers "where do I…"
 * with a link, and it is registered for every ability consumer, like
 * {@see Get_Site_Info}.
 *
 * The screens come from a curated map of core screens (`admin-screens.php`),
 * extensible through the `wpai_admin_screens` filter, because the admin menu is
 * not built during a REST request. Matching is word scoring on the server with no
 * model call; the agent's own model chooses among the results. Only screens the
 * current user can open are returned, and every URL is built with `admin_url()`,
 * so a link never comes from model text and never leaves the admin host.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Find_Admin_Screens {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const ABILITY = 'ai/find-admin-screens';

	/**
	 * The most screens one call returns.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_RESULTS = 5;

	/**
	 * The longest query accepted, in characters.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_QUERY_LENGTH = 200;

	/**
	 * Words that carry no meaning for finding a screen.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private const STOPWORDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'a',
		'about',
		'all',
		'am',
		'an',
		'and',
		'any',
		'are',
		'as',
		'at',
		'be',
		'by',
		'can',
		'change',
		'could',
		'do',
		'does',
		'edit',
		'find',
		'for',
		'from',
		'get',
		'go',
		'how',
		'i',
		'in',
		'into',
		'is',
		'it',
		'its',
		'manage',
		'me',
		'my',
		'need',
		'of',
		'on',
		'or',
		'our',
		'please',
		'screen',
		'set',
		'should',
		'show',
		'so',
		'some',
		'that',
		'the',
		'there',
		'this',
		'to',
		'turn',
		'up',
		'want',
		'way',
		'we',
		'what',
		'where',
		'which',
		'who',
		'why',
		'will',
		'with',
		'would',
		'you',
		'your',
	);

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the read-only `ai/find-admin-screens` ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Find Admin Screens', 'ai' ),
				'description'         => __( 'Finds the WordPress admin screens that handle a task, from a short description in the person\'s words, such as "hide my site from google" or "change the timezone". Returns up to five screens the current user can open, best match first, each with its title, its menu section and its admin URL. Link the person to a screen with the returned URL; never build an admin URL yourself. An empty list means no screen matched. Requires an authenticated user.', 'ai' ),
				'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'query' ),
					'properties'           => array(
						'query' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => self::MAX_QUERY_LENGTH,
							'description' => __( 'What the person wants to do or change, in a few words, such as "discourage search engines" or "approve comments".', 'ai' ),
						),
					),
				),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_find_admin_screens' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						// This ability only reads a map held on the local site.
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission callback for the `ai/find-admin-screens` ability.
	 *
	 * Any authenticated user may ask: each screen is checked against the user's
	 * own capabilities before it is returned, so the coarse gate has nothing to add.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed.
	 */
	public function check_permission( $input = array() ): bool {
		unset( $input );

		return is_user_logged_in();
	}

	/**
	 * Executes the `ai/find-admin-screens` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array{screens: list<array{title: string, url: string, section?: string}>} The matching screens, best first.
	 */
	public function execute_find_admin_screens( $input = array() ): array {
		$query = is_array( $input ) && isset( $input['query'] ) && is_string( $input['query'] ) ? $input['query'] : '';
		$query = trim( mb_substr( trim( $query ), 0, self::MAX_QUERY_LENGTH ) );
		$terms = array_values( array_unique( $this->tokenize( $query ) ) );

		if ( array() === $terms ) {
			return array( 'screens' => array() );
		}

		$scored = array();

		foreach ( $this->get_screens() as $index => $screen ) {
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Each screen names the capability core requires to open it.
			if ( ! current_user_can( $screen['capability'] ) ) {
				continue;
			}

			$title_words = $this->tokenize( $screen['title'] );
			$words       = array_merge(
				$title_words,
				$this->tokenize( $screen['section'] ),
				$this->tokenize( implode( ' ', $screen['keywords'] ) )
			);

			$hits       = count( array_intersect( $terms, $words ) );
			$title_hits = count( array_intersect( $terms, $title_words ) );

			if ( 0 === $hits ) {
				continue;
			}

			$scored[] = array(
				'hits'       => $hits,
				'title_hits' => $title_hits,
				'index'      => $index,
				'screen'     => $screen,
			);
		}

		// Most query words matched first, then a match on the title, then map order.
		usort(
			$scored,
			static function ( array $a, array $b ): int {
				return array( $b['hits'], $b['title_hits'], $a['index'] ) <=> array( $a['hits'], $a['title_hits'], $b['index'] );
			}
		);

		$screens = array();

		foreach ( array_slice( $scored, 0, self::MAX_RESULTS ) as $match ) {
			$screen = $match['screen'];
			$item   = array(
				'title' => $screen['title'],
				'url'   => admin_url( $screen['path'] ),
			);

			if ( '' !== $screen['section'] ) {
				$item['section'] = $screen['section'];
			}

			$screens[] = $item;
		}

		return array( 'screens' => $screens );
	}

	/**
	 * Returns the screen map, filtered and validated.
	 *
	 * An entry is dropped unless it has a title, a capability, and a path that is
	 * relative to the admin directory: no scheme, no leading slash or backslash, so
	 * `admin_url()` cannot be pointed at another host.
	 *
	 * @since x.x.x
	 *
	 * @return list<array{title: string, section: string, path: string, keywords: list<string>, capability: string}> The screens.
	 */
	private function get_screens(): array {
		$screens = require __DIR__ . '/admin-screens.php';

		/**
		 * Filters the admin screens the `ai/find-admin-screens` ability can return.
		 *
		 * Each entry is an array with a `title`, a `path` relative to the admin
		 * directory (passed to `admin_url()`), a `capability` the user must have
		 * to be offered it, and optionally `keywords` (a list of words or phrases
		 * a person might use for it) and a short `section` such as "Settings".
		 * Entries earlier in the list win ties.
		 *
		 * @since x.x.x
		 *
		 * @param array<int, array<string, mixed>> $screens The curated core screens.
		 */
		$screens = apply_filters( 'wpai_admin_screens', is_array( $screens ) ? $screens : array() );

		if ( ! is_array( $screens ) ) {
			return array();
		}

		$valid = array();

		foreach ( $screens as $screen ) {
			if ( ! is_array( $screen ) ) {
				continue;
			}

			$title      = isset( $screen['title'] ) && is_string( $screen['title'] ) ? trim( $screen['title'] ) : '';
			$path       = isset( $screen['path'] ) && is_string( $screen['path'] ) ? trim( $screen['path'] ) : '';
			$capability = isset( $screen['capability'] ) && is_string( $screen['capability'] ) ? trim( $screen['capability'] ) : '';
			$section    = isset( $screen['section'] ) && is_string( $screen['section'] ) ? trim( $screen['section'] ) : '';
			$keywords   = isset( $screen['keywords'] ) && is_array( $screen['keywords'] ) ? array_values( array_filter( $screen['keywords'], 'is_string' ) ) : array();

			if ( '' === $title || '' === $capability || ! $this->is_admin_path( $path ) ) {
				continue;
			}

			$valid[] = array(
				'title'      => $title,
				'section'    => $section,
				'path'       => $path,
				'keywords'   => $keywords,
				'capability' => $capability,
			);
		}

		return $valid;
	}

	/**
	 * Whether a path is relative to the admin directory.
	 *
	 * @since x.x.x
	 *
	 * @param string $path The path.
	 * @return bool True for a path such as `options-reading.php` or `admin.php?page=x`.
	 */
	private function is_admin_path( string $path ): bool {
		if ( '' === $path || 1 === preg_match( '#^[/\\\\]|^[a-z][a-z0-9+.\-]*:#i', $path ) ) {
			return false;
		}

		// Nothing before the query string may carry a scheme, a host or a parent directory.
		$file = (string) strtok( $path, '?#' );

		return 1 === preg_match( '#^[a-z0-9_\-./]+$#i', $file ) && false === strpos( $file, '..' ) && false === strpos( $file, '//' );
	}

	/**
	 * Splits text into lowercase, lightly stemmed words without stopwords.
	 *
	 * @since x.x.x
	 *
	 * @param string $text The text.
	 * @return list<string> The words.
	 */
	private function tokenize( string $text ): array {
		$text  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		$words = array();

		foreach ( $parts as $part ) {
			if ( in_array( $part, self::STOPWORDS, true ) ) {
				continue;
			}

			$words[] = $this->stem( $part );
		}

		return $words;
	}

	/**
	 * Reduces a word to a rough stem, so "comments" matches "comment".
	 *
	 * Only plurals are folded. The result never ends in a further plural, so
	 * stemming a stem returns it unchanged and "setting" meets "settings". There
	 * is no "-ing" rule: it took "setting" to "sett" but "settings" only to "setting".
	 *
	 * @since x.x.x
	 *
	 * @param string $word A lowercase word.
	 * @return string The stem.
	 */
	private function stem( string $word ): string {
		$length = strlen( $word );

		if ( $length > 4 && 'ies' === substr( $word, -3 ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}

		if ( $length > 3 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) ) {
			return substr( $word, 0, -1 );
		}

		return $word;
	}

	/**
	 * Builds the output schema for the `ai/find-admin-screens` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'screens' ),
			'properties'           => array(
				'screens' => array(
					'type'        => 'array',
					'maxItems'    => self::MAX_RESULTS,
					'description' => __( 'The matching screens, best match first. Empty when nothing matched.', 'ai' ),
					'items'       => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'title', 'url' ),
						'properties'           => array(
							'title'   => array(
								'type'        => 'string',
								'description' => __( 'The screen\'s menu title, such as Reading.', 'ai' ),
							),
							'section' => array(
								'type'        => 'string',
								'description' => __( 'The admin menu the screen sits under, such as Settings. Absent for a top-level screen.', 'ai' ),
							),
							'url'     => array(
								'type'        => 'string',
								'format'      => 'uri',
								'description' => __( 'The screen\'s admin URL.', 'ai' ),
							),
						),
					),
				),
			),
		);
	}
}
