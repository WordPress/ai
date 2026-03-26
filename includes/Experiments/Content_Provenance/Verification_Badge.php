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
 * Renders an optional "Click to Verify" badge on published posts.
 *
 * @since 0.5.0
 */
class Verification_Badge {

	/**
	 * Register the frontend badge filter.
	 *
	 * @since 0.5.0
	 */
	public static function register_hooks(): void {
		add_filter( 'the_content', array( self::class, 'maybe_append_badge' ), 99 );
	}

	/**
	 * Append the C2PA badge to singular published posts if the post is signed.
	 *
	 * @since 0.5.0
	 *
	 * @param string $content The post content.
	 * @return string Content with optional badge appended.
	 */
	public static function maybe_append_badge( string $content ): string {
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
			<span class="c2pa-badge__icon" aria-hidden="true">🛡</span>
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

		return $content . ( $badge ? $badge : '' );
	}
}
