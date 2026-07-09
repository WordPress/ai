<?php
/**
 * Markdown Feeds experiment.
 *
 * @since x.x.x
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Markdown_Feeds;

use WP_Post;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves WordPress content as Markdown.
 *
 * Adds a `markdown` feed format (available at `/feed/markdown/` and
 * `?feed=markdown` in every feed context), serves singular content as
 * `text/markdown` via `?format=md`, emits autodiscovery link tags, and
 * optionally negotiates via the `Accept: text/markdown` request header.
 *
 * @since x.x.x
 */
class Markdown_Feeds extends Abstract_Feature {

	/**
	 * Feed name registered with WordPress.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const FEED_NAME = 'markdown';

	/**
	 * Option flagging that rewrite rules need flushing on the next request.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const FLUSH_FLAG_OPTION = 'wpai_markdown_feeds_flush_rewrite';

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'markdown-feeds';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Markdown Feeds', 'ai' ),
			'description' => __( 'Serves your content as Markdown for AI agents and other machine readers: adds a Markdown feed at /feed/markdown/ and Markdown versions of individual posts and pages via ?format=md, with optional Accept-header negotiation.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_feed( self::FEED_NAME, array( $this, 'do_feed_markdown' ) );
		add_action( 'template_redirect', array( $this, 'handle_template_redirect' ) );
		add_action( 'wp_head', array( $this, 'add_discovery_links' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'      => 'accept_header',
				'label'   => __( 'Serve Markdown when a request prefers it via the Accept header (may conflict with page caches that ignore the Vary header)', 'ai' ),
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Registers the option-change listeners that schedule a rewrite-rules
	 * flush. This runs for ALL registered features regardless of enablement
	 * (see WordPress\AI\Settings\Settings_Registration), which is required so
	 * the flush also happens on the disable transition, when register() no
	 * longer runs.
	 */
	public function register_settings(): void {
		parent::register_settings();

		$enabled_option = sprintf( 'wpai_feature_%s_enabled', static::get_id() );

		add_action( "add_option_{$enabled_option}", array( $this, 'schedule_rewrite_flush' ) );
		add_action( "update_option_{$enabled_option}", array( $this, 'schedule_rewrite_flush' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite_rules' ) );
	}

	/**
	 * Renders the markdown feed for the current feed query.
	 *
	 * @since x.x.x
	 */
	public function do_feed_markdown(): void {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/markdown; charset=' . get_option( 'blog_charset' ) );
		}

		$renderer = new Markdown_Feed_Renderer();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text Markdown response, not HTML.
		echo $renderer->render();
	}

	/**
	 * Serves singular content as Markdown when requested.
	 *
	 * @since x.x.x
	 */
	public function handle_template_redirect(): void {
		if ( is_singular() && $this->is_accept_negotiation_enabled() && ! headers_sent() ) {
			header( 'Vary: Accept' );
		}

		$markdown = $this->get_singular_markdown();

		if ( null === $markdown ) {
			return;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/markdown; charset=' . get_option( 'blog_charset' ) );
			header( 'X-Robots-Tag: noindex' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text Markdown response, not HTML.
		echo $markdown;
		exit;
	}

	/**
	 * Returns the Markdown document for the current singular request, or null
	 * when Markdown was not requested or must not be served.
	 *
	 * @since x.x.x
	 *
	 * @return string|null Markdown document, or null to serve the normal template.
	 */
	public function get_singular_markdown(): ?string {
		if ( ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( ! $this->is_markdown_requested() ) {
			return null;
		}

		if ( ! is_post_publicly_viewable( $post ) || post_password_required( $post ) ) {
			return null;
		}

		$renderer = new Markdown_Singular_Renderer();

		return $renderer->render( $post );
	}

	/**
	 * Prints Markdown autodiscovery link tags.
	 *
	 * @since x.x.x
	 */
	public function add_discovery_links(): void {
		printf(
			'<link rel="alternate" type="text/markdown" title="%s" href="%s" />' . "\n",
			esc_attr(
				sprintf(
					/* translators: %s: site name. */
					__( '%s Markdown Feed', 'ai' ),
					get_bloginfo( 'name' )
				)
			),
			esc_url( get_feed_link( self::FEED_NAME ) )
		);

		if ( ! is_singular() ) {
			return;
		}

		$permalink = get_permalink();

		if ( ! $permalink ) {
			return;
		}

		printf(
			'<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
			esc_url( add_query_arg( 'format', 'md', $permalink ) )
		);
	}

	/**
	 * Flags that rewrite rules must be flushed on the next request.
	 *
	 * Hooked to the experiment's enabled-option add/update events, which fire
	 * during the settings REST request — too late in that request to flush
	 * with the correct rule set, hence the deferred flag.
	 *
	 * @since x.x.x
	 */
	public function schedule_rewrite_flush(): void {
		update_option( self::FLUSH_FLAG_OPTION, '1', false );
	}

	/**
	 * Flushes rewrite rules once if a flush was scheduled.
	 *
	 * @since x.x.x
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( ! get_option( self::FLUSH_FLAG_OPTION ) ) {
			return;
		}

		delete_option( self::FLUSH_FLAG_OPTION );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Deferred to a single wp_loaded request only when the enabled toggle changed; not run on every request.
		flush_rewrite_rules( false );
	}

	/**
	 * Checks whether the current request asked for Markdown.
	 *
	 * @since x.x.x
	 *
	 * @return bool Whether Markdown output was requested.
	 */
	private function is_markdown_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public, read-only format negotiation.
		if ( isset( $_GET['format'] ) && 'md' === sanitize_key( wp_unslash( (string) $_GET['format'] ) ) ) {
			return true;
		}

		if ( ! $this->is_accept_negotiation_enabled() ) {
			return false;
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) ) : '';

		return 1 === preg_match( '~^text/(?:x-)?markdown(?:[,;]|$)~', $accept );
	}

	/**
	 * Checks whether Accept-header negotiation is enabled via the sub-toggle.
	 *
	 * @since x.x.x
	 *
	 * @return bool Whether Accept-header negotiation is enabled.
	 */
	private function is_accept_negotiation_enabled(): bool {
		return (bool) get_option( static::get_field_option_name( 'accept_header' ), false );
	}
}
