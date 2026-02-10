<?php
/**
 * Markdown Feeds experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Markdown_Feeds;

use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Settings\Settings_Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use function __;
use function add_query_arg;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_bloginfo;
use function get_feed_link;
use function get_option;
use function get_permalink;
use function get_post_status;
use function get_post_type_object;
use function get_queried_object;
use function get_query_var;
use function get_the_title;
use function is_admin;
use function is_feed;
use function is_singular;
use function post_password_required;
use function register_setting;
use function sanitize_key;
use function str_contains;
use function wp_kses;
use function wp_parse_url;

/**
 * Registers Markdown representations for feeds and singular content.
 */
class Markdown_Feeds extends Abstract_Experiment {
	private const FEED_NAME = 'markdown';

	private const OPTION_ENABLE_FEED           = 'ai_experiment_markdown_feeds_enable_feed';
	private const OPTION_ENABLE_FORMAT_PARAM   = 'ai_experiment_markdown_feeds_enable_format_param';
	private const OPTION_ENABLE_MD_EXTENSION   = 'ai_experiment_markdown_feeds_enable_md_extension';
	private const OPTION_ENABLE_ACCEPT_HEADERS = 'ai_experiment_markdown_feeds_enable_accept_headers';

	private const DEFAULT_ENABLE_FEED           = true;
	private const DEFAULT_ENABLE_FORMAT_PARAM   = true;
	private const DEFAULT_ENABLE_MD_EXTENSION   = false;
	private const DEFAULT_ENABLE_ACCEPT_HEADERS = true;

	/**
	 * Whether the current request was made to a `.md` URL.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	private bool $markdown_extension_request = false;

	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'markdown-feeds',
			'label'       => esc_html__( 'Markdown Feeds', 'ai' ),
			'description' => esc_html__( 'Adds Markdown representations of posts and pages via feeds, .md URLs, and Accept header negotiation.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_feed' ), 11 );
		add_filter( 'do_parse_request', array( $this, 'filter_request_for_markdown_extension' ), 1 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ), 10, 2 );
		add_filter( 'wp_headers', array( $this, 'filter_wp_headers' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_singular_markdown' ), 0 );

		// Feed autodiscovery.
		add_action( 'wp_head', array( $this, 'add_feed_autodiscovery_links' ) );
	}

	/**
	 * Registers the Markdown feed.
	 *
	 * @since x.x.x
	 */
	public function register_feed(): void {
		$settings = $this->get_settings();
		if ( ! $settings['enable_feed'] ) {
			return;
		}

		add_feed( self::FEED_NAME, array( $this, 'render_feed' ) );
	}

	/**
	 * Registers the `format` query variable.
	 *
	 * @since x.x.x
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'format';
		return $vars;
	}

	/**
	 * Outputs feed autodiscovery links in the HTML head.
	 *
	 * Mirrors the behavior of `feed_links()` and `feed_links_extra()` for RSS/Atom.
	 *
	 * @since x.x.x
	 */
	public function add_feed_autodiscovery_links(): void {
		$settings = $this->get_settings();
		if ( ! $settings['enable_feed'] ) {
			return;
		}

		// Don't add discovery links on feeds themselves.
		if ( is_feed() ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );

		// Main site feed.
		$feed_url = $this->get_markdown_feed_link();

		/* translators: %s: Site name. */
		$title = sprintf( __( '%s Markdown Feed', 'ai' ), $site_name );

		printf(
			'<link rel="alternate" type="text/markdown" title="%s" href="%s" />' . "\n",
			esc_attr( $title ),
			esc_url( $feed_url )
		);

		if ( ! is_singular() || ! $settings['enable_format_param'] ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof \WP_Post ) || ! $this->is_post_accessible( $post ) ) {
			return;
		}

		// Add the ?format=md alternate link for singular content.
		$format_url = add_query_arg( 'format', 'md', get_permalink( $post ) );
		/* translators: %s: Post title. */
		$md_title = sprintf( __( '%s (Markdown)', 'ai' ), get_the_title( $post ) );

		printf(
			'<link rel="alternate" type="text/markdown" title="%s" href="%s" />' . "\n",
			esc_attr( $md_title ),
			esc_url( $format_url )
		);
	}

	/**
	 * Gets the Markdown feed URL.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Optional. Feed context (empty for main feed, or 'category', 'tag', etc.).
	 * @return string Feed URL.
	 */
	public function get_markdown_feed_link( string $context = '' ): string {
		if ( '' === $context ) {
			return get_feed_link( self::FEED_NAME );
		}

		// For archive feeds, use the base feed link with feed query parameter.
		return add_query_arg( 'feed', self::FEED_NAME, $context );
	}

	/**
	 * Gets the Markdown permalink for a post.
	 *
	 * When the `.md` extension is enabled and the permalink structure supports
	 * it, returns a `.md` suffixed URL.  Otherwise falls back to a
	 * `?format=md` query-string URL.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Markdown permalink (never empty).
	 */
	public function get_markdown_permalink( \WP_Post $post ): string {
		$permalink = get_permalink( $post );
		if ( ! $permalink ) {
			return '';
		}

		$settings = $this->get_settings();

		// Try .md extension when enabled and the URL structure supports it.
		if ( $settings['enable_md_extension'] ) {
			$permalink_structure = (string) get_option( 'permalink_structure' );

			// Only works with pretty permalinks and non-query-string URLs.
			if ( '' !== $permalink_structure && false === strpos( $permalink, '?' ) ) {
				$path = wp_parse_url( $permalink, PHP_URL_PATH );

				// Guard against the homepage / site-root URL — appending .md
				// to the bare domain produces an invalid hostname.
				if ( is_string( $path ) && '/' !== $path ) {
					return rtrim( $permalink, '/' ) . '.md';
				}
			}
		}

		// Fall back to query-string approach when format param is enabled.
		if ( $settings['enable_format_param'] ) {
			return add_query_arg( 'format', 'md', $permalink );
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_ENABLE_FEED,
			array(
				'type'              => 'boolean',
				'default'           => self::DEFAULT_ENABLE_FEED,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_ENABLE_FORMAT_PARAM,
			array(
				'type'              => 'boolean',
				'default'           => self::DEFAULT_ENABLE_FORMAT_PARAM,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_ENABLE_MD_EXTENSION,
			array(
				'type'              => 'boolean',
				'default'           => self::DEFAULT_ENABLE_MD_EXTENSION,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_ENABLE_ACCEPT_HEADERS,
			array(
				'type'              => 'boolean',
				'default'           => self::DEFAULT_ENABLE_ACCEPT_HEADERS,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function render_settings_fields(): void {
		$settings = $this->get_settings();
		?>
		<div class="ai-experiments__item-settings">
			<label class="components-toggle-control" for="<?php echo esc_attr( self::OPTION_ENABLE_FEED ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( self::OPTION_ENABLE_FEED ); ?>"
					name="<?php echo esc_attr( self::OPTION_ENABLE_FEED ); ?>"
					value="1"
					<?php checked( (bool) $settings['enable_feed'] ); ?>
				/>
				<span>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: Markdown feed URL query string. */
							__( 'Enable <code>%s</code> feed endpoint', 'ai' ),
							esc_html( '/?feed=markdown' )
						),
						array( 'code' => array() )
					);
					?>
				</span>
			</label>

			<label class="components-toggle-control" for="<?php echo esc_attr( self::OPTION_ENABLE_FORMAT_PARAM ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( self::OPTION_ENABLE_FORMAT_PARAM ); ?>"
					name="<?php echo esc_attr( self::OPTION_ENABLE_FORMAT_PARAM ); ?>"
					value="1"
					<?php checked( (bool) $settings['enable_format_param'] ); ?>
				/>
				<span>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: Query parameter used for Markdown format. */
							__( 'Enable <code>%s</code> query parameter for any page', 'ai' ),
							esc_html( '?format=md' )
						),
						array( 'code' => array() )
					);
					?>
				</span>
			</label>

			<label class="components-toggle-control" for="<?php echo esc_attr( self::OPTION_ENABLE_MD_EXTENSION ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( self::OPTION_ENABLE_MD_EXTENSION ); ?>"
					name="<?php echo esc_attr( self::OPTION_ENABLE_MD_EXTENSION ); ?>"
					value="1"
					<?php checked( (bool) $settings['enable_md_extension'] ); ?>
				/>
				<span>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: File extension used for Markdown permalinks. */
							__( 'Enable <code>%s</code> permalinks for singular content', 'ai' ),
							'.md'
						),
						array( 'code' => array() )
					);
					?>
				</span>
			</label>

			<label class="components-toggle-control" for="<?php echo esc_attr( self::OPTION_ENABLE_ACCEPT_HEADERS ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( self::OPTION_ENABLE_ACCEPT_HEADERS ); ?>"
					name="<?php echo esc_attr( self::OPTION_ENABLE_ACCEPT_HEADERS ); ?>"
					value="1"
					<?php checked( (bool) $settings['enable_accept_headers'] ); ?>
				/>
				<span>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: HTTP Accept header value used for Markdown negotiation. */
							__( 'Enable <code>%s</code> negotiation for singular content', 'ai' ),
							'Accept: text/markdown'
						),
						array( 'code' => array() )
					);
					?>
				</span>
			</label>
		</div>
		<?php
	}

	/**
	 * Renders the Markdown feed response.
	 *
	 * @since x.x.x
	 *
	 * @param bool   $for_comments Whether the feed is for comments.
	 * @param string $feed         The requested feed name.
	 */
	public function render_feed( $for_comments, $feed ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$renderer = new Markdown_Feed_Renderer();
		$renderer->render();
	}

	/**
	 * Strips `.md` from the request URI before WordPress parses the URL.
	 *
	 * Hooked to `do_parse_request` (fires before `WP::parse_request()`), this
	 * mangles `$_SERVER['REQUEST_URI']` so the rewrite engine sees the clean
	 * slug.  This avoids issues with custom post type query vars, verbose page
	 * rules, and rewrite-rule mis-matches that occur when filtering query vars
	 * after the fact.
	 *
	 * @since x.x.x
	 *
	 * @param bool $do_parse Whether to parse the request. Default true.
	 * @return bool
	 */
	public function filter_request_for_markdown_extension( bool $do_parse ): bool {
		$settings = $this->get_settings();
		if ( ! $settings['enable_md_extension'] ) {
			return $do_parse;
		}

		if ( is_admin() ) {
			return $do_parse;
		}

		$method = $this->get_request_method();
		if ( 'get' !== $method && 'head' !== $method ) {
			return $do_parse;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return $do_parse;
		}

		$uri = (string) $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Used for routing only, not output.

		if ( str_contains( $uri, '.md' ) ) {
			$_SERVER['REQUEST_URI']           = str_replace( '.md', '', $uri );
			$this->markdown_extension_request = true;
		}

		return $do_parse;
	}

	/**
	 * Prevents canonical redirects for `.md` requests.
	 *
	 * @since x.x.x
	 *
	 * @param string|false $redirect_url  The redirect URL.
	 * @param string       $requested_url The requested URL.
	 * @return string|false
	 */
	public function filter_redirect_canonical( $redirect_url, string $requested_url ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( $this->markdown_extension_request ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Adds `Vary: Accept` when Accept header negotiation is enabled.
	 *
	 * @since x.x.x
	 *
	 * @param array<string,string> $headers Array of headers to send.
	 * @return array<string,string>
	 */
	public function filter_wp_headers( array $headers ): array {
		$settings = $this->get_settings();
		if ( ! $settings['enable_accept_headers'] ) {
			return $headers;
		}

		if ( $this->markdown_extension_request ) {
			return $headers;
		}

		if ( ! is_singular() ) {
			return $headers;
		}

		$headers['Vary'] = $this->merge_vary_header( $headers['Vary'] ?? '', 'Accept' );
		return $headers;
	}

	/**
	 * Renders Markdown when requested via `.md`, `?format=md`, or `Accept: text/markdown`.
	 *
	 * Works for both singular and non-singular (archive/homepage) requests.
	 *
	 * @since x.x.x
	 */
	public function maybe_render_singular_markdown(): void {
		$settings = $this->get_settings();

		$method = $this->get_request_method();
		if ( 'get' !== $method && 'head' !== $method ) {
			return;
		}

		$wants_markdown = false;
		if ( $settings['enable_md_extension'] && $this->markdown_extension_request ) {
			$wants_markdown = true;
		} elseif ( $settings['enable_format_param'] && 'md' === get_query_var( 'format' ) ) {
			$wants_markdown = true;
		} elseif ( $settings['enable_accept_headers'] && $this->client_accepts_markdown() ) {
			$wants_markdown = true;
		}

		if ( ! $wants_markdown ) {
			return;
		}

		// Non-singular pages (archives, homepage, category, etc.):
		// render the current query as a markdown feed.
		if ( ! is_singular() ) {
			if ( $this->markdown_extension_request ) {
				$renderer = new Markdown_Singular_Renderer();
				$renderer->render_not_found();
				exit;
			}

			$feed_renderer = new Markdown_Feed_Renderer();
			$feed_renderer->render();
			exit;
		}

		$renderer = new Markdown_Singular_Renderer();

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			$renderer->render_not_found();
			exit;
		}

		// Check post accessibility (status, password protection, etc.).
		if ( ! $this->is_post_accessible( $post ) ) {
			if ( post_password_required( $post ) ) {
				$renderer->render_password_required();
			} else {
				$renderer->render_not_found();
			}
			exit;
		}

		// Send Link header pointing to canonical HTML version.
		$canonical_url = get_permalink( $post );
		if ( $canonical_url ) {
			header( 'Link: <' . esc_url( $canonical_url ) . '>; rel="canonical"', false );
		}

		$renderer->render( $post );
		exit;
	}

	/**
	 * Checks whether a post is accessible for Markdown rendering.
	 *
	 * Validates post status and password protection similar to core feed behavior.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool True if accessible, false otherwise.
	 */
	private function is_post_accessible( \WP_Post $post ): bool {
		$status = get_post_status( $post );

		// Published posts are accessible unless password-protected.
		if ( 'publish' === $status ) {
			return ! post_password_required( $post );
		}

		// Private posts require the read_private_posts capability.
		if ( 'private' === $status ) {
			$post_type_obj = get_post_type_object( $post->post_type );
			if ( ! $post_type_obj ) {
				return false;
			}

			return current_user_can( $post_type_obj->cap->read_private_posts ?? 'read_private_posts' ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined
		}

		// Draft, pending, future, trash, etc. are not accessible.
		return false;
	}

	/**
	 * Checks whether the current request's `Accept` header includes Markdown.
	 *
	 * @since x.x.x
	 *
	 * @return bool
	 */
	private function client_accepts_markdown(): bool {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $accept ) {
			return false;
		}

		$types = array(
			'text/markdown',
			'text/x-markdown',
			'application/markdown',
		);

		foreach ( $types as $type ) {
			if ( false !== strpos( $accept, $type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Merges a token into a `Vary` header value.
	 *
	 * @since x.x.x
	 *
	 * @param string $current Existing Vary header.
	 * @param string $token   Token to add.
	 * @return string
	 */
	private function merge_vary_header( string $current, string $token ): string {
		$current = trim( $current );

		if ( '' === $current ) {
			return $token;
		}

		$parts = array_map( 'trim', explode( ',', $current ) );
		foreach ( $parts as $part ) {
			if ( strtolower( $part ) === strtolower( $token ) ) {
				return $current;
			}
		}

		$parts[] = $token;
		return implode( ', ', $parts );
	}

	/**
	 * Reads experiment settings with defaults.
	 *
	 * @since x.x.x
	 *
	 * @return array{enable_feed: bool, enable_format_param: bool, enable_md_extension: bool, enable_accept_headers: bool}
	 */
	private function get_settings(): array {
		return array(
			'enable_feed'           => (bool) get_option( self::OPTION_ENABLE_FEED, self::DEFAULT_ENABLE_FEED ),
			'enable_format_param'   => (bool) get_option( self::OPTION_ENABLE_FORMAT_PARAM, self::DEFAULT_ENABLE_FORMAT_PARAM ),
			'enable_md_extension'   => (bool) get_option( self::OPTION_ENABLE_MD_EXTENSION, self::DEFAULT_ENABLE_MD_EXTENSION ),
			'enable_accept_headers' => (bool) get_option( self::OPTION_ENABLE_ACCEPT_HEADERS, self::DEFAULT_ENABLE_ACCEPT_HEADERS ),
		);
	}

	/**
	 * Reads the current HTTP request method (lowercase).
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	private function get_request_method(): string {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return 'get';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request method used for routing only.
		return sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
	}
}
