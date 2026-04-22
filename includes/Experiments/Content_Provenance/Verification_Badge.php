<?php
/**
 * Verification Badge for Content_Provenance experiment.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles C2PA provenance embedding injection and optional verification badge.
 *
 * Signed content is injected at priority 1, replacing WordPress HTML with the
 * exact NFC(plain_text) + wrapper bytes that were signed. wpautop is removed
 * so the byte stream is not modified. A wrapping div with white-space:pre-line
 * preserves paragraph formatting visually.
 *
 * The badge appends at PHP_INT_MAX (after the wrapper) inside the_content so
 * it inherits the theme's content-area alignment.
 *
 * @since x.x.x
 */
class Verification_Badge {

	/**
	 * Whether the badge is enabled.
	 *
	 * @var bool
	 */
	private static bool $show_badge = true;

	/**
	 * Badge position relative to content: 'above', 'below', or 'inline'.
	 *
	 * @var string
	 */
	private static string $badge_position = 'below';

	/**
	 * Configures badge display settings.
	 *
	 * Must be called before register_hooks() so the the_content filter
	 * respects the show_badge and badge_position options.
	 *
	 * @since x.x.x
	 *
	 * @param bool   $show_badge     Whether to display the badge.
	 * @param string $badge_position Badge position: 'above', 'below', or 'inline'.
	 */
	public static function configure( bool $show_badge, string $badge_position ): void {
		self::$show_badge     = $show_badge;
		self::$badge_position = $badge_position;
	}

	/**
	 * Register the frontend embedding injection and badge hooks.
	 *
	 * @since x.x.x
	 */
	public static function register_hooks(): void {
		// Replace content with the signed embedded bytes at priority 1.
		// Also removes wpautop so the byte stream stays intact.
		add_filter( 'the_content', array( self::class, 'inject_c2pa_embeddings' ), 1 );

		// Append badge at the very end, after the wrapper.
		// Inside the_content so the theme's content container provides alignment.
		add_filter( 'the_content', array( self::class, 'maybe_append_badge' ), PHP_INT_MAX );

		// Expose canonical signed bytes for client-side verification tools.
		add_action( 'wp_footer', array( self::class, 'render_provenance_data' ) );
	}

	/**
	 * Inject the signed embedded content, suppressing wpautop.
	 *
	 * Replaces the WordPress-formatted HTML with the exact bytes that were
	 * signed: NFC(plain_text) + invisible wrapper. Removes wpautop so the
	 * byte stream is not modified by paragraph wrapping. A containing div
	 * with white-space:pre-line preserves visual paragraph formatting.
	 *
	 * @since x.x.x
	 *
	 * @param string $content Clean post content.
	 * @return string Signed content with invisible Unicode markers.
	 */
	public static function inject_c2pa_embeddings( string $content ): string {
		if ( ! is_singular() || is_admin() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$status = get_post_meta( $post_id, '_c2pa_status', true );
		if ( 'signed' !== $status ) {
			return $content;
		}

		$embedded_content = get_post_meta( $post_id, '_c2pa_embedded_content', true );
		if ( empty( $embedded_content ) ) {
			return $content;
		}

		// Prevent wpautop from wrapping the signed text in <p> tags.
		// The signed content hash covers the raw plain text bytes; any HTML
		// injection by wpautop shifts byte offsets and breaks verification.
		remove_filter( 'the_content', 'wpautop' );

		// Wrap in a div with pre-line whitespace so double newlines display
		// as paragraph breaks. The div tags are outside the signed bytes;
		// innerText extraction strips them, leaving the exact signed text.
		return '<div class="c2pa-signed-content" style="white-space: pre-line;">'
			. $embedded_content
			. '</div>';
	}

	/**
	 * Output a script tag containing the canonical signed bytes.
	 *
	 * The script tag uses type="application/c2pa-provenance" so browsers
	 * do not execute it. Client-side verification widgets and browser
	 * extensions can read the exact signed bytes without relying on
	 * innerText extraction.
	 *
	 * @since x.x.x
	 */
	public static function render_provenance_data(): void {
		if ( ! is_singular() || is_admin() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$status = get_post_meta( $post_id, '_c2pa_status', true );
		if ( 'signed' !== $status ) {
			return;
		}

		$embedded_content = get_post_meta( $post_id, '_c2pa_embedded_content', true );
		if ( empty( $embedded_content ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Signed C2PA bytes contain invisible Unicode markers; escaping alters the byte stream and breaks cryptographic verification.
		printf(
			'<script type="application/c2pa-provenance" data-post-id="%d">%s</script>',
			absint( $post_id ),
			$embedded_content
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Append the C2PA badge to singular published posts if the post is signed.
	 *
	 * Runs at PHP_INT_MAX so the badge HTML appears after the invisible wrapper
	 * in the DOM. The theme's content container provides horizontal alignment.
	 *
	 * @since x.x.x
	 *
	 * @param string $content The post content.
	 * @return string Content with optional badge appended.
	 */
	public static function maybe_append_badge( string $content ): string {
		if ( ! self::$show_badge ) {
			return $content;
		}

		if ( ! is_singular() || is_admin() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$status = get_post_meta( $post_id, '_c2pa_status', true );
		if ( 'signed' !== $status ) {
			return $content;
		}

		$signed_at   = get_post_meta( $post_id, '_c2pa_signed_at', true );
		$raw_tier    = get_post_meta( $post_id, '_c2pa_signer_tier', true );
		$signer_tier = $raw_tier ? (string) $raw_tier : 'local';
		$verify_url  = rest_url( 'c2pa-provenance/v1/verify' );
		$formatted   = $signed_at ? date_i18n( get_option( 'date_format' ), strtotime( $signed_at ) ) : '';

		if ( 'connected' === $signer_tier ) {
			$tier_label = esc_html__( 'Verified Publisher', 'ai' );
		} elseif ( 'byok' === $signer_tier ) {
			$tier_label = esc_html__( 'Publisher Certificate', 'ai' );
		} else {
			$tier_label = esc_html__( 'Content Integrity Verified', 'ai' );
		}

		ob_start();
		?>
		<div class="c2pa-badge" role="complementary" aria-label="<?php esc_attr_e( 'Content provenance information', 'ai' ); ?>">
			<span class="c2pa-badge__icon" aria-hidden="true">&#x1F6E1;</span>
			<span class="c2pa-badge__label"><?php echo esc_html( $tier_label ); ?></span>
			<?php if ( $formatted ) : ?>
				<span class="c2pa-badge__date">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: date signed */
							__( 'Signed %s', 'ai' ),
							$formatted
						)
					);
					?>
				</span>
			<?php endif; ?>
			<a href="<?php echo esc_url( $verify_url ); ?>" class="c2pa-badge__link" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Verify', 'ai' ); ?>
			</a>
		</div>
		<?php
		$badge = ob_get_clean();

		if ( ! $badge ) {
			return $content;
		}

		if ( 'above' === self::$badge_position ) {
			return $badge . $content;
		}

		return $content . $badge;
	}
}
