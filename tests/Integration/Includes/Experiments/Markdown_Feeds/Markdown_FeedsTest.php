<?php
/**
 * Integration tests for the Markdown_Feeds class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds;

use WP_UnitTestCase;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiments\Markdown_Feeds\Markdown_Feeds;

/**
 * Markdown_Feeds test case.
 *
 * @since x.x.x
 */
class Markdown_FeedsTest extends WP_UnitTestCase {

	/**
	 * The experiment instance.
	 *
	 * @var \WordPress\AI\Experiments\Markdown_Feeds\Markdown_Feeds
	 */
	private Markdown_Feeds $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_markdown-feeds_enabled', true );

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();

		$experiment = $registry->get_experiment( 'markdown-feeds' );
		$this->assertInstanceOf( Markdown_Feeds::class, $experiment, 'Markdown feeds experiment should be registered in the registry.' );

		$this->experiment = new Markdown_Feeds();
		$this->experiment->register();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_markdown-feeds_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		delete_option( 'ai_experiment_markdown_feeds_enable_md_extension' );
		delete_option( 'ai_experiment_markdown_feeds_enable_format_param' );
		remove_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration(): void {
		$experiment = new Markdown_Feeds();

		$this->assertEquals( 'markdown-feeds', $experiment->get_id() );
		$this->assertEquals( 'Markdown Feeds', $experiment->get_label() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the Markdown feed is registered.
	 *
	 * @since x.x.x
	 */
	public function test_markdown_feed_is_registered(): void {
		global $wp_rewrite;

		$this->experiment->register_feed();

		// The feed should be added to the registered feeds.
		$this->assertContains( 'markdown', $wp_rewrite->feeds );
	}

	/**
	 * Test that get_markdown_feed_link returns the correct URL.
	 *
	 * @since x.x.x
	 */
	public function test_get_markdown_feed_link(): void {
		$feed_link = $this->experiment->get_markdown_feed_link();

		$this->assertStringContainsString( 'feed', $feed_link );
		$this->assertStringContainsString( 'markdown', $feed_link );
	}

	/**
	 * Test that get_markdown_permalink returns .md URL when extension is enabled.
	 *
	 * @since x.x.x
	 */
	public function test_get_markdown_permalink_with_md_extension(): void {
		global $wp_rewrite;

		update_option( 'ai_experiment_markdown_feeds_enable_md_extension', true );

		$original_structure = (string) get_option( 'permalink_structure' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_title'  => 'Test Post',
					'post_name'   => 'test-post',
					'post_status' => 'publish',
				)
			);

			$post         = get_post( $post_id );
			$md_permalink = $this->experiment->get_markdown_permalink( $post );

			$this->assertStringEndsWith( '.md', $md_permalink );
			$this->assertStringContainsString( 'test-post', $md_permalink );
		} finally {
			$wp_rewrite->set_permalink_structure( $original_structure );
		}
	}

	/**
	 * Test that get_markdown_permalink returns ?format=md by default (extension disabled).
	 *
	 * @since x.x.x
	 */
	public function test_get_markdown_permalink_defaults_to_query_param(): void {
		global $wp_rewrite;

		$original_structure = (string) get_option( 'permalink_structure' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_title'  => 'Test Post',
					'post_name'   => 'test-post',
					'post_status' => 'publish',
				)
			);

			$post         = get_post( $post_id );
			$md_permalink = $this->experiment->get_markdown_permalink( $post );

			$this->assertStringContainsString( 'format=md', $md_permalink );
			$this->assertStringContainsString( 'test-post', $md_permalink );
		} finally {
			$wp_rewrite->set_permalink_structure( $original_structure );
		}
	}

	/**
	 * Test that get_markdown_permalink returns ?format=md with plain permalinks.
	 *
	 * @since x.x.x
	 */
	public function test_get_markdown_permalink_with_plain_permalinks(): void {
		global $wp_rewrite;

		$original_structure = (string) get_option( 'permalink_structure' );
		$wp_rewrite->set_permalink_structure( '' );

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_title'  => 'Plain Post',
					'post_name'   => 'plain-post',
					'post_status' => 'publish',
				)
			);

			$post         = get_post( $post_id );
			$md_permalink = $this->experiment->get_markdown_permalink( $post );

			$this->assertStringContainsString( 'format=md', $md_permalink );
		} finally {
			$wp_rewrite->set_permalink_structure( $original_structure );
		}
	}

	/**
	 * Test that get_markdown_permalink avoids .md on the homepage root URL.
	 *
	 * @since x.x.x
	 */
	public function test_get_markdown_permalink_avoids_md_on_homepage(): void {
		global $wp_rewrite;

		update_option( 'ai_experiment_markdown_feeds_enable_md_extension', true );

		$original_structure = (string) get_option( 'permalink_structure' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		$original_show_on_front = get_option( 'show_on_front' );
		$original_page_on_front = get_option( 'page_on_front' );

		try {
			$page_id = self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_title'  => 'Home',
					'post_name'   => '',
					'post_status' => 'publish',
				)
			);

			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page_id );

			$page         = get_post( $page_id );
			$md_permalink = $this->experiment->get_markdown_permalink( $page );

			// Should fall back to ?format=md, NOT produce example.org.md.
			$this->assertStringContainsString( 'format=md', $md_permalink );
			$this->assertStringNotContainsString( '.md', $md_permalink );
		} finally {
			$wp_rewrite->set_permalink_structure( $original_structure );
			update_option( 'show_on_front', $original_show_on_front );
			update_option( 'page_on_front', $original_page_on_front );
		}
	}

	/**
	 * Test that filter strips .md from the request URI.
	 *
	 * @since x.x.x
	 */
	public function test_filter_request_strips_md_from_uri(): void {
		update_option( 'ai_experiment_markdown_feeds_enable_md_extension', true );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/test-post.md';

		$result = $this->experiment->filter_request_for_markdown_extension( true );

		$this->assertTrue( $result );
		$this->assertEquals( '/test-post', $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test assertion.
	}

	/**
	 * Test that filter strips .md from pagename URI.
	 *
	 * @since x.x.x
	 */
	public function test_filter_request_strips_md_from_page_uri(): void {
		update_option( 'ai_experiment_markdown_feeds_enable_md_extension', true );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/sample-page.md';

		$result = $this->experiment->filter_request_for_markdown_extension( true );

		$this->assertTrue( $result );
		$this->assertEquals( '/sample-page', $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test assertion.
	}

	/**
	 * Test that filter ignores URIs without .md.
	 *
	 * @since x.x.x
	 */
	public function test_filter_request_ignores_non_md_uris(): void {
		update_option( 'ai_experiment_markdown_feeds_enable_md_extension', true );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/test-post.html';

		$result = $this->experiment->filter_request_for_markdown_extension( true );

		$this->assertTrue( $result );
		$this->assertEquals( '/test-post.html', $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test assertion.
	}

	/**
	 * Test that filter ignores POST requests.
	 *
	 * @since x.x.x
	 */
	public function test_filter_request_ignores_post_requests(): void {
		update_option( 'ai_experiment_markdown_feeds_enable_md_extension', true );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/test-post.md';

		$result = $this->experiment->filter_request_for_markdown_extension( true );

		$this->assertTrue( $result );
		$this->assertEquals( '/test-post.md', $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test assertion.
	}

	/**
	 * Test that filter is a no-op when .md extension is disabled.
	 *
	 * @since x.x.x
	 */
	public function test_filter_request_noop_when_extension_disabled(): void {
		// Default is disabled, so no need to set option.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/test-post.md';

		$result = $this->experiment->filter_request_for_markdown_extension( true );

		$this->assertTrue( $result );
		$this->assertEquals( '/test-post.md', $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test assertion.
	}

	/**
	 * Test canonical redirect is prevented for .md requests.
	 *
	 * @since x.x.x
	 */
	public function test_filter_redirect_canonical_prevents_redirect_for_md(): void {
		update_option( 'ai_experiment_markdown_feeds_enable_md_extension', true );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/test-post.md';

		// Trigger the markdown extension detection.
		$this->experiment->filter_request_for_markdown_extension( true );

		// Now test that canonical redirect is prevented.
		$result = $this->experiment->filter_redirect_canonical( 'http://example.com/test-post/', 'http://example.com/test-post.md' );

		$this->assertFalse( $result );
	}

	/**
	 * Test that register_query_vars adds the format variable.
	 *
	 * @since x.x.x
	 */
	public function test_register_query_vars(): void {
		$vars   = array( 'p', 'name' );
		$result = $this->experiment->register_query_vars( $vars );

		$this->assertContains( 'format', $result );
		$this->assertContains( 'p', $result );
		$this->assertContains( 'name', $result );
	}

	/**
	 * Test that get_markdown_permalink returns empty when both format param and extension are disabled.
	 *
	 * @since x.x.x
	 */
	public function test_get_markdown_permalink_empty_when_all_disabled(): void {
		global $wp_rewrite;

		// Extension is off by default; also disable format param.
		update_option( 'ai_experiment_markdown_feeds_enable_format_param', '0' );

		$original_structure = (string) get_option( 'permalink_structure' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_title'  => 'Disabled Post',
					'post_name'   => 'disabled-post',
					'post_status' => 'publish',
				)
			);

			$post = get_post( $post_id );
			$this->assertSame( '', $this->experiment->get_markdown_permalink( $post ) );
		} finally {
			$wp_rewrite->set_permalink_structure( $original_structure );
		}
	}

	/**
	 * Test that format param is enabled by default.
	 *
	 * @since x.x.x
	 */
	public function test_format_param_enabled_by_default(): void {
		global $wp_rewrite;

		$original_structure = (string) get_option( 'permalink_structure' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_title'  => 'Format Post',
					'post_name'   => 'format-post',
					'post_status' => 'publish',
				)
			);

			$post         = get_post( $post_id );
			$md_permalink = $this->experiment->get_markdown_permalink( $post );

			$this->assertStringContainsString( 'format=md', $md_permalink );
		} finally {
			$wp_rewrite->set_permalink_structure( $original_structure );
		}
	}
}
