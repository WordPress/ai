<?php
/**
 * Integration tests for Verification_Badge.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\Verification_Badge;

/**
 * Verification_Badge test case.
 *
 * @since 0.5.0
 */
class Verification_BadgeTest extends WP_UnitTestCase {

	/**
	 * Reset badge configuration before each test.
	 *
	 * Other tests may call Verification_Badge::configure() with different
	 * values, so each badge test starts from a known enabled state.
	 */
	public function setUp(): void {
		parent::setUp();
		Verification_Badge::configure( true, 'below' );
	}

	/**
	 * Test that register_hooks adds the content filters.
	 *
	 * @since 0.5.0
	 */
	public function test_register_hooks_adds_filter(): void {
		Verification_Badge::register_hooks();
		$this->assertGreaterThan( 0, has_filter( 'the_content', array( Verification_Badge::class, 'inject_c2pa_embeddings' ) ) );
		$this->assertGreaterThan( 0, has_filter( 'the_content', array( Verification_Badge::class, 'maybe_append_badge' ) ) );
		$this->assertGreaterThan( 0, has_action( 'wp_footer', array( Verification_Badge::class, 'render_provenance_data' ) ) );
	}

	/**
	 * Test that inject_c2pa_embeddings returns content unchanged when not singular.
	 *
	 * @since 0.5.0
	 */
	public function test_inject_embeddings_returns_unchanged_on_archive(): void {
		$content = '<p>Hello World</p>';
		$result  = Verification_Badge::inject_c2pa_embeddings( $content );
		$this->assertSame( $content, $result );
	}

	/**
	 * Test that inject_c2pa_embeddings returns content unchanged when post is not signed.
	 *
	 * @since 0.5.0
	 */
	public function test_inject_embeddings_returns_unchanged_for_unsigned_post(): void {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		$content = '<p>Post content.</p>';
		$result  = Verification_Badge::inject_c2pa_embeddings( $content );

		$this->assertSame( $content, $result );
	}

	/**
	 * Test that inject_c2pa_embeddings replaces content with embedded bytes.
	 *
	 * @since 0.5.0
	 */
	public function test_inject_embeddings_replaces_content_for_signed_post(): void {
		$post_id  = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$embedded = "Signed content.\xEF\xBB\xBF\xEF\xB8\x80";
		update_post_meta( $post_id, '_c2pa_status', 'signed' );
		update_post_meta( $post_id, '_c2pa_embedded_content', $embedded );

		$this->go_to( get_permalink( $post_id ) );

		$result = Verification_Badge::inject_c2pa_embeddings( '<p>Original HTML</p>' );

		$this->assertStringContainsString( $embedded, $result );
		$this->assertStringContainsString( 'c2pa-signed-content', $result );
		$this->assertStringContainsString( 'white-space', $result );
		$this->assertStringNotContainsString( 'Original HTML', $result );
	}

	/**
	 * Test that maybe_append_badge returns content unchanged when not singular.
	 *
	 * @since 0.5.0
	 */
	public function test_maybe_append_badge_returns_unchanged_on_archive(): void {
		$content = '<p>Hello World</p>';
		$result  = Verification_Badge::maybe_append_badge( $content );
		$this->assertSame( $content, $result );
	}

	/**
	 * Test that maybe_append_badge returns content unchanged when post is not signed.
	 *
	 * @since 0.5.0
	 */
	public function test_maybe_append_badge_returns_unchanged_for_unsigned_post(): void {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		$content = '<p>Post content.</p>';
		$result  = Verification_Badge::maybe_append_badge( $content );

		$this->assertSame( $content, $result );
	}

	/**
	 * Test that maybe_append_badge appends badge HTML for a signed post.
	 *
	 * @since 0.5.0
	 */
	public function test_maybe_append_badge_appends_badge_for_signed_post(): void {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_c2pa_status', 'signed' );
		update_post_meta( $post_id, '_c2pa_signer_tier', 'local' );

		$this->go_to( get_permalink( $post_id ) );

		$content = '<p>Post content.</p>';
		$result  = Verification_Badge::maybe_append_badge( $content );

		$this->assertStringContainsString( '<p>Post content.</p>', $result );
		$this->assertStringContainsString( 'c2pa-badge', $result );
		$this->assertStringContainsString( 'Content Integrity Verified', $result );
	}

	/**
	 * Test that badge shows "Verified Publisher" for connected tier.
	 *
	 * @since 0.5.0
	 */
	public function test_badge_label_for_connected_tier(): void {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_c2pa_status', 'signed' );
		update_post_meta( $post_id, '_c2pa_signer_tier', 'connected' );

		$this->go_to( get_permalink( $post_id ) );

		$result = Verification_Badge::maybe_append_badge( '<p>Content</p>' );
		$this->assertStringContainsString( 'Verified Publisher', $result );
	}

	/**
	 * Test that badge shows "Publisher Certificate" for byok tier.
	 *
	 * @since 0.5.0
	 */
	public function test_badge_label_for_byok_tier(): void {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_c2pa_status', 'signed' );
		update_post_meta( $post_id, '_c2pa_signer_tier', 'byok' );

		$this->go_to( get_permalink( $post_id ) );

		$result = Verification_Badge::maybe_append_badge( '<p>Content</p>' );
		$this->assertStringContainsString( 'Publisher Certificate', $result );
	}

	/**
	 * Test that the badge contains the verify REST URL.
	 *
	 * @since 0.5.0
	 */
	public function test_badge_contains_verify_url(): void {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_c2pa_status', 'signed' );

		$this->go_to( get_permalink( $post_id ) );

		$result = Verification_Badge::maybe_append_badge( '<p>Content</p>' );
		$this->assertStringContainsString( 'c2pa-provenance/v1/verify', $result );
	}

	/**
	 * Test that the badge shows the sign date when signed_at meta is present.
	 *
	 * @since 0.5.0
	 */
	public function test_badge_shows_date_when_signed_at_set(): void {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_c2pa_status', 'signed' );
		update_post_meta( $post_id, '_c2pa_signed_at', '2024-01-15T10:30:00+00:00' );

		$this->go_to( get_permalink( $post_id ) );

		$result = Verification_Badge::maybe_append_badge( '<p>Content</p>' );
		$this->assertStringContainsString( 'Signed ', $result );
		$this->assertStringContainsString( 'c2pa-badge__date', $result );
	}

	/**
	 * Test that render_provenance_data outputs the canonical signed bytes.
	 *
	 * @since 0.7.0
	 */
	public function test_render_provenance_data_outputs_script_tag(): void {
		$post_id  = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$embedded = "Signed content.\xEF\xBB\xBF\xEF\xB8\x80";
		update_post_meta( $post_id, '_c2pa_status', 'signed' );
		update_post_meta( $post_id, '_c2pa_embedded_content', $embedded );

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		Verification_Badge::render_provenance_data();
		$result = ob_get_clean();

		$this->assertStringContainsString( 'type="application/c2pa-provenance"', $result );
		$this->assertStringContainsString( $embedded, $result );
	}
}
