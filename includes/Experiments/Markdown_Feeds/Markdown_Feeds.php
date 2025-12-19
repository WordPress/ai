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

use function __;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function get_option;
use function is_admin;
use function register_setting;
use function sanitize_key;
use function wp_kses;

/**
 * Registers Markdown representations for feeds and singular content.
 */
class Markdown_Feeds extends Abstract_Experiment {
	private const FEED_NAME = 'markdown';

	private const OPTION_ENABLE_FEED           = 'ai_experiment_markdown_feeds_enable_feed';
	private const OPTION_ENABLE_MD_EXTENSION   = 'ai_experiment_markdown_feeds_enable_md_extension';
	private const OPTION_ENABLE_ACCEPT_HEADERS = 'ai_experiment_markdown_feeds_enable_accept_headers';

	private const DEFAULT_ENABLE_FEED           = true;
	private const DEFAULT_ENABLE_MD_EXTENSION   = true;
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
			'label'       => esc_html__( 'Markdown', 'ai' ),
			'description' => esc_html__( 'Adds Markdown representations of posts and pages via feeds, .md URLs, and Accept header negotiation.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_feed' ) );
		add_filter( 'do_parse_request', array( $this, 'maybe_strip_markdown_extension' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ), 10, 2 );
		add_filter( 'wp_headers', array( $this, 'filter_wp_headers' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_singular_markdown' ), 0 );
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
	 * Registers experiment-specific settings.
	 *
	 * @since x.x.x
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
	public function has_settings(): bool {
		return true;
	}

	/**
	 * Renders settings controls on the Experiments screen.
	 *
	 * @since x.x.x
	 */
	public function render_settings_fields(): void {
		$settings = $this->get_settings();
		?>
		<div class="ai-experiment-settings">
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
							__( 'Enable <code>%s</code>', 'ai' ),
							esc_html( '/?feed=markdown' )
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
							esc_html( '.md' )
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
							esc_html( 'Accept: text/markdown' )
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
	 * Removes the `.md` suffix from the request path so WordPress can resolve the
	 * underlying resource via existing rewrite rules.
	 *
	 * @since x.x.x
	 *
	 * @param bool $do_parse Whether to parse the request.
	 * @return bool
	 */
	public function maybe_strip_markdown_extension( bool $do_parse ): bool {
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

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $request_uri ) {
			return $do_parse;
		}

		$parts = wp_parse_url( $request_uri );
		if ( ! is_array( $parts ) ) {
			return $do_parse;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' === $path ) {
			return $do_parse;
		}

		if ( 0 === strpos( $path, '/wp-json/' ) ) {
			return $do_parse;
		}

		$has_trailing_slash = '/' === substr( $path, -1 );
		$path_trimmed       = $has_trailing_slash ? rtrim( $path, '/' ) : $path;

		if ( '.md' !== substr( $path_trimmed, -3 ) ) {
			return $do_parse;
		}

		$base_path = substr( $path_trimmed, 0, -3 );
		if ( '' === $base_path ) {
			$base_path = '/';
		}

		$new_path = $base_path;
		if ( $has_trailing_slash ) {
			$new_path .= '/';
		}

		$new_request_uri = $new_path;
		if ( ! empty( $parts['query'] ) ) {
			$new_request_uri .= '?' . $parts['query'];
		}

		$this->markdown_extension_request = true;

		$_SERVER['REQUEST_URI'] = $new_request_uri;
		if ( isset( $_SERVER['PATH_INFO'] ) ) {
			$_SERVER['PATH_INFO'] = $new_path;
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
	 * Renders Markdown for singular content when requested via `.md` or `Accept: text/markdown`.
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
		} elseif ( $settings['enable_accept_headers'] && $this->client_accepts_markdown() ) {
			$wants_markdown = true;
		}

		if ( ! $wants_markdown ) {
			return;
		}

		if ( ! is_singular() ) {
			if ( $this->markdown_extension_request ) {
				$renderer = new Markdown_Singular_Renderer();
				$renderer->render_not_found();
				exit;
			}

			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			$renderer = new Markdown_Singular_Renderer();
			$renderer->render_not_found();
			exit;
		}

		$renderer = new Markdown_Singular_Renderer();
		$renderer->render( $post );
		exit;
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
	 * @return array{enable_feed: bool, enable_md_extension: bool, enable_accept_headers: bool}
	 */
	private function get_settings(): array {
		return array(
			'enable_feed'           => (bool) get_option( self::OPTION_ENABLE_FEED, self::DEFAULT_ENABLE_FEED ),
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
