<?php
/**
 * Markdown Feeds experiment.
 *
 * @since 1.4.0
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
 * @since 1.4.0
 */
class Markdown_Feeds extends Abstract_Feature {

	/**
	 * Feed name registered with WordPress.
	 *
	 * @since 1.4.0
	 *
	 * @var string
	 */
	public const FEED_NAME = 'markdown';

	/**
	 * Option flagging that rewrite rules need flushing on the next request.
	 *
	 * @since 1.4.0
	 *
	 * @var string
	 */
	public const FLUSH_FLAG_OPTION = 'wpai_markdown_feeds_flush_rewrite';

	/**
	 * Transient that pauses rewrite rule repairs after one that did not add the feed.
	 *
	 * @since 1.4.0
	 *
	 * @var string
	 */
	private const REWRITE_REPAIR_TRANSIENT = 'wpai_markdown_feeds_rewrite_repair';

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
			'description' => __( 'Serves your content as Markdown for AI agents and other machine readers: adds a Markdown feed at /feed/markdown/ and Markdown versions of individual posts and pages via ?output_format=markdown, with optional Accept-header negotiation.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_feed( self::FEED_NAME, array( $this, 'do_feed_markdown' ) );
		add_filter( 'feed_content_type', array( $this, 'filter_feed_content_type' ), 10, 2 );
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
	 * @since x.x.x
	 */
	public function get_preloaded_options(): array {
		return array( self::FLUSH_FLAG_OPTION );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Registers the option-change listeners that schedule a rewrite-rules
	 * flush. This runs for ALL registered features regardless of enablement
	 * which is required so the flush also happens on the disable transition.
	 */
	public function register_settings(): void {
		parent::register_settings();

		$enabled_option = sprintf( 'wpai_feature_%s_enabled', static::get_id() );

		add_action( "add_option_{$enabled_option}", array( $this, 'schedule_rewrite_flush' ) );
		add_action( "update_option_{$enabled_option}", array( $this, 'schedule_rewrite_flush' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite_rules' ) );
	}

	/**
	 * Sends an HTTP header when headers have not already been sent.
	 *
	 * @since 1.4.0
	 *
	 * @param string $header  Header line to send.
	 * @param bool   $replace Whether to replace a previously sent header of the same name.
	 */
	protected function send_header( string $header, bool $replace = true ): void {
		if ( headers_sent() ) {
			return;
		}

		header( $header, $replace );
	}

	/**
	 * Renders the markdown feed for the current feed query.
	 *
	 * @since 1.4.0
	 */
	public function do_feed_markdown(): void {
		$this->send_header( 'Content-Type: text/markdown; charset=' . get_option( 'blog_charset' ) );

		$renderer = new Markdown_Feed_Renderer();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text Markdown response, not HTML.
		echo $renderer->render();
	}

	/**
	 * Filters the content type reported for the markdown feed.
	 *
	 * @since 1.4.0
	 *
	 * @param string $content_type Content type being sent for the feed.
	 * @param string $type         Type of feed being requested.
	 * @return string Content type, mapped to text/markdown for the markdown feed.
	 */
	public function filter_feed_content_type( string $content_type, string $type ): string {
		if ( self::FEED_NAME === $type ) {
			return 'text/markdown';
		}

		return $content_type;
	}

	/**
	 * Serves singular content as Markdown when requested.
	 *
	 * @since 1.4.0
	 */
	public function handle_template_redirect(): void {
		if ( is_singular() && $this->is_accept_negotiation_enabled() ) {
			$this->send_header( 'Vary: Accept', false );
		}

		$markdown = $this->get_singular_markdown();

		if ( null === $markdown ) {
			return;
		}

		$this->send_header( 'Content-Type: text/markdown; charset=' . get_option( 'blog_charset' ) );
		$this->send_header( 'X-Robots-Tag: noindex' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text Markdown response, not HTML.
		echo $markdown;
		exit;
	}

	/**
	 * Returns the Markdown document for the current singular request, or null
	 * when Markdown was not requested or must not be served.
	 *
	 * @since 1.4.0
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
	 * @since 1.4.0
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

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || ! is_post_publicly_viewable( $post ) || post_password_required( $post ) ) {
			return;
		}

		$permalink = get_permalink( $post );

		if ( ! $permalink ) {
			return;
		}

		printf(
			'<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
			esc_url( add_query_arg( 'output_format', 'markdown', $permalink ) )
		);
	}

	/**
	 * Flags that rewrite rules must be flushed on the next request.
	 *
	 * @since 1.4.0
	 */
	public function schedule_rewrite_flush(): void {
		update_option( self::FLUSH_FLAG_OPTION, '1', false );
	}

	/**
	 * Flushes rewrite rules as needed.
	 *
	 * @since 1.4.0
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::FLUSH_FLAG_OPTION ) ) {
			delete_option( self::FLUSH_FLAG_OPTION );
		} elseif ( ! $this->is_feed_missing_from_rewrite_rules() || get_transient( self::REWRITE_REPAIR_TRANSIENT ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Deferred to a single wp_loaded request, only when the enabled toggle changed or the stored rules do not list the registered feed.
		flush_rewrite_rules( false );

		// Nothing is missing, so end any wait left by an earlier flush that did not help.
		if ( ! $this->is_feed_missing_from_rewrite_rules() ) {
			delete_transient( self::REWRITE_REPAIR_TRANSIENT );
			return;
		}

		// The flush did not add the feed, so something else keeps it out. Wait before trying again.
		set_transient( self::REWRITE_REPAIR_TRANSIENT, 1, HOUR_IN_SECONDS );
	}

	/**
	 * Checks whether the feed is registered but missing from the stored rewrite rules.
	 *
	 * @since 1.4.0
	 *
	 * @return bool Whether the stored rewrite rules must be rebuilt to serve the feed.
	 */
	private function is_feed_missing_from_rewrite_rules(): bool {
		global $wp_rewrite;

		// Nothing to repair while the feed is not registered.
		if ( ! in_array( self::FEED_NAME, $wp_rewrite->feeds, true ) ) {
			return false;
		}

		$rules = get_option( 'rewrite_rules' );

		// Plain permalinks store no rules.
		if ( ! is_array( $rules ) ) {
			return false;
		}

		/*
		 * The root feed rules point to this query and list every registered feed
		 * in their key, e.g. `feed/(feed|rdf|rss|rss2|atom|markdown)/?$`.
		 */
		$feed_rules = array_keys( $rules, 'index.php?&feed=$matches[1]', true );

		return array() !== $feed_rules && array() === preg_grep( '/[(|]' . preg_quote( self::FEED_NAME, '/' ) . '[|)]/', $feed_rules );
	}

	/**
	 * Checks whether the current request asked for Markdown.
	 *
	 * @since 1.4.0
	 *
	 * @return bool Whether Markdown output was requested.
	 */
	private function is_markdown_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public, read-only format negotiation.
		if ( isset( $_GET['output_format'] ) && 'markdown' === sanitize_key( wp_unslash( (string) $_GET['output_format'] ) ) ) {
			return true;
		}

		if ( ! $this->is_accept_negotiation_enabled() ) {
			return false;
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) ) : '';

		return $this->prefers_markdown( $accept );
	}

	/**
	 * Checks whether an Accept header prefers Markdown over HTML.
	 *
	 * Markdown wins with a higher effective quality than HTML, or on a tie
	 * when its match is more specific or listed first. Refusals and
	 * wildcard-only headers keep HTML.
	 *
	 * @since x.x.x
	 *
	 * @param string $accept Accept header value.
	 * @return bool Whether Markdown is preferred.
	 */
	private function prefers_markdown( string $accept ): bool {
		// Best match so far per media type: specificity, quality, position in the header.
		$best = array(
			'text/html'     => array( 0, 0.0, PHP_INT_MAX ),
			'text/markdown' => array( 0, 0.0, PHP_INT_MAX ),
		);

		foreach ( explode( ',', strtolower( $accept ) ) as $position => $range ) {
			$params  = array_map( 'trim', explode( ';', $range ) );
			$type    = array_shift( $params );
			$quality = 1.0;

			foreach ( $params as $param ) {
				if ( 1 !== preg_match( '/^q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/', $param, $matches ) ) {
					continue;
				}

				$quality = (float) $matches[1];
			}

			if ( 'text/x-markdown' === $type ) {
				$type = 'text/markdown';
			}

			foreach ( $best as $media_type => $current ) {
				if ( $type === $media_type ) {
					$specificity = 3;
				} elseif ( 'text/*' === $type ) {
					$specificity = 2;
				} elseif ( '*/*' === $type ) {
					$specificity = 1;
				} else {
					continue;
				}

				if ( $specificity < $current[0] || ( $specificity === $current[0] && $quality <= $current[1] ) ) {
					continue;
				}

				$best[ $media_type ] = array( $specificity, $quality, $position );
			}
		}

		[ $markdown_specificity, $markdown_quality, $markdown_position ] = $best['text/markdown'];
		[ $html_specificity, $html_quality, $html_position ]             = $best['text/html'];

		if ( $markdown_quality <= 0 ) {
			return false;
		}

		if ( $markdown_quality !== $html_quality ) {
			return $markdown_quality > $html_quality;
		}

		// Same quality: the more specific match wins, then the one listed first. One range matching both keeps HTML.
		if ( $markdown_specificity !== $html_specificity ) {
			return $markdown_specificity > $html_specificity;
		}

		return $markdown_position < $html_position;
	}

	/**
	 * Checks whether Accept-header negotiation is enabled via the sub-toggle.
	 *
	 * @since 1.4.0
	 *
	 * @return bool Whether Accept-header negotiation is enabled.
	 */
	private function is_accept_negotiation_enabled(): bool {
		return (bool) get_option( static::get_field_option_name( 'accept_header' ), false );
	}
}
