<?php
/**
 * Integration tests for the AI Workspace admin page's prompt seed.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Experiments\AI_Workspace;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Admin_Page;

/**
 * Admin_Page prompt seed test case.
 *
 * The command palette's "Ask AI" row opens the workspace with the search in
 * the `wpai-prompt` query argument.
 *
 * @since x.x.x
 */
class Admin_PageTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		unset( $_GET[ Admin_Page::PROMPT_QUERY_ARG ], $_GET[ Admin_Page::POST_QUERY_ARG ] );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		$this->reset_enqueue_registries();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The query arrives in the seed, ready for the composer.
	 *
	 * @since x.x.x
	 */
	public function test_prompt_seed_carries_the_query() {
		$seed = $this->seed_for( array( Admin_Page::PROMPT_QUERY_ARG => 'which of my posts are missing featured images' ) );

		$this->assertSame( 'prompt', $seed['status'] );
		$this->assertSame( 'which of my posts are missing featured images', $seed['prompt'] );
	}

	/**
	 * A 2,000-character query is cut to 500 characters.
	 *
	 * @since x.x.x
	 */
	public function test_prompt_seed_is_cut_to_the_cap() {
		$seed = $this->seed_for( array( Admin_Page::PROMPT_QUERY_ARG => str_repeat( 'é', 2000 ) ) );

		$this->assertSame( 500, mb_strlen( $seed['prompt'] ) );
		$this->assertSame( str_repeat( 'é', 500 ), $seed['prompt'] );
	}

	/**
	 * A query with markup arrives as plain text on one line.
	 *
	 * @since x.x.x
	 */
	public function test_prompt_seed_is_plain_text() {
		$seed = $this->seed_for( array( Admin_Page::PROMPT_QUERY_ARG => "<script>alert(1)</script>posts with <b>no</b>\nfeatured image" ) );

		$this->assertSame( 'posts with no featured image', $seed['prompt'] );
	}

	/**
	 * An empty or whitespace query gives no seed.
	 *
	 * @since x.x.x
	 */
	public function test_empty_or_whitespace_prompt_gives_no_seed() {
		foreach ( array( '', "   \n\t ", '<b></b>' ) as $query ) {
			$this->assertNull( $this->seed_for( array( Admin_Page::PROMPT_QUERY_ARG => $query ) ), sprintf( 'Query %s must give no seed.', wp_json_encode( $query ) ) );
		}
	}

	/**
	 * A query that is not a string gives no seed.
	 *
	 * @since x.x.x
	 */
	public function test_non_string_prompt_gives_no_seed() {
		$this->assertNull( $this->seed_for( array( Admin_Page::PROMPT_QUERY_ARG => array( 'one', 'two' ) ) ) );
	}

	/**
	 * The prompt wins over a post handoff when both are present.
	 *
	 * @since x.x.x
	 */
	public function test_prompt_seed_takes_precedence_over_a_post_seed() {
		$post_id = $this->factory->post->create( array( 'post_title' => 'A handed-off post' ) );

		$seed = $this->seed_for(
			array(
				Admin_Page::POST_QUERY_ARG   => (string) $post_id,
				Admin_Page::PROMPT_QUERY_ARG => 'list my drafts',
			)
		);

		$this->assertSame( 'prompt', $seed['status'] );
		$this->assertSame( 'list my drafts', $seed['prompt'] );
		$this->assertSame( 0, $seed['postId'] );
		$this->assertSame( '', $seed['title'] );
	}

	/**
	 * A post handoff alone still seeds the post, with no prompt.
	 *
	 * @since x.x.x
	 */
	public function test_post_seed_has_an_empty_prompt() {
		$post_id = $this->factory->post->create( array( 'post_title' => 'A handed-off post' ) );

		$seed = $this->seed_for( array( Admin_Page::POST_QUERY_ARG => (string) $post_id ) );

		$this->assertSame( 'ready', $seed['status'] );
		$this->assertSame( $post_id, $seed['postId'] );
		$this->assertSame( '', $seed['prompt'] );
	}

	/**
	 * Runs the workspace enqueue with the given query arguments and returns the seed.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $query The query arguments.
	 * @return array<string, mixed>|null The decoded seed.
	 */
	private function seed_for( array $query ): ?array {
		$this->reset_enqueue_registries();

		foreach ( $query as $key => $value ) {
			$_GET[ $key ] = $value;
		}

		( new Admin_Page() )->enqueue_assets();

		foreach ( array_keys( $query ) as $key ) {
			unset( $_GET[ $key ] );
		}

		$data  = (string) wp_scripts()->get_data( 'ai_workspace', 'data' );
		$start = strpos( $data, '{' );
		$end   = strrpos( $data, '}' );

		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );

		$decoded = json_decode( substr( $data, (int) $start, (int) $end - (int) $start + 1 ), true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'seed', $decoded );

		return $decoded['seed'];
	}

	/**
	 * Resets the script and style registries, which otherwise outlive a test.
	 *
	 * @since x.x.x
	 */
	private function reset_enqueue_registries(): void {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the enqueue registries between tests.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the enqueue registries between tests.
	}
}
