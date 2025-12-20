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
	 * Test that get_markdown_permalink returns the correct URL for a post.
	 *
	 * @since x.x.x
	 */
	public function test_get_markdown_permalink(): void {
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
	}

	/**
	 * Test that request filter strips .md from post name.
	 *
	 * @since x.x.x
	 */
	public function test_filter_request_strips_md_from_name(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$query_vars = array( 'name' => 'test-post.md' );
		$result     = $this->experiment->filter_request_for_markdown_extension( $query_vars );

		$this->assertEquals( 'test-post', $result['name'] );
	}

	/**
	 * Test that request filter strips .md from pagename.
	 *
	 * @since x.x.x
	 */
	public function test_filter_request_strips_md_from_pagename(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$query_vars = array( 'pagename' => 'sample-page.md' );
		$result     = $this->experiment->filter_request_for_markdown_extension( $query_vars );

		$this->assertEquals( 'sample-page', $result['pagename'] );
	}

	/**
	 * Test that request filter ignores non-.md extensions.
	 *
	 * @since x.x.x
	 */
	public function test_filter_request_ignores_non_md_extensions(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$query_vars = array( 'name' => 'test-post.html' );
		$result     = $this->experiment->filter_request_for_markdown_extension( $query_vars );

		$this->assertEquals( 'test-post.html', $result['name'] );
	}

	/**
	 * Test that request filter ignores POST requests.
	 *
	 * @since x.x.x
	 */
	public function test_filter_request_ignores_post_requests(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$query_vars = array( 'name' => 'test-post.md' );
		$result     = $this->experiment->filter_request_for_markdown_extension( $query_vars );

		$this->assertEquals( 'test-post.md', $result['name'] );
	}

	/**
	 * Test canonical redirect is prevented for .md requests.
	 *
	 * @since x.x.x
	 */
	public function test_filter_redirect_canonical_prevents_redirect_for_md(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// First, trigger the markdown extension detection.
		$query_vars = array( 'name' => 'test-post.md' );
		$this->experiment->filter_request_for_markdown_extension( $query_vars );

		// Now test that canonical redirect is prevented.
		$result = $this->experiment->filter_redirect_canonical( 'http://example.com/test-post/', 'http://example.com/test-post.md' );

		$this->assertFalse( $result );
	}
}
