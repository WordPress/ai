<?php
/**
 * Integration tests for the Markdown_Feeds experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds
 */

namespace WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Markdown_Feeds\Markdown_Feeds;

/**
 * Markdown_Feeds experiment test case.
 *
 * @since x.x.x
 */
class Markdown_FeedsTest extends WP_UnitTestCase {

	/**
	 * Experiment under test.
	 *
	 * @var Markdown_Feeds
	 */
	private $experiment;

	/**
	 * Sets up the experiment instance.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->experiment = new Markdown_Feeds();
	}

	/**
	 * Cleans up request superglobals mutated by tests.
	 */
	public function tearDown(): void {
		unset( $_GET['format'], $_SERVER['HTTP_ACCEPT'] );
		parent::tearDown();
	}

	/**
	 * Tests experiment identity and metadata.
	 */
	public function test_metadata(): void {
		$this->assertSame( 'markdown-feeds', Markdown_Feeds::get_id() );
		$this->assertSame( 'none', $this->experiment->get_capability() );
		$this->assertSame( 'experimental', $this->experiment->get_stability() );
		$this->assertNotSame( '', $this->experiment->get_label() );
	}

	/**
	 * Tests the accept_header settings field exists and defaults off.
	 */
	public function test_settings_fields(): void {
		$fields = $this->experiment->get_settings_fields();

		$this->assertCount( 1, $fields );
		$this->assertSame( 'accept_header', $fields[0]['id'] );
		$this->assertSame( 'boolean', $fields[0]['type'] );
		$this->assertFalse( $fields[0]['default'] );
	}

	/**
	 * Tests that register() wires the feed and front-end hooks.
	 */
	public function test_register_adds_hooks(): void {
		$this->experiment->register();

		$this->assertNotFalse( has_action( 'do_feed_markdown', array( $this->experiment, 'do_feed_markdown' ) ) );
		$this->assertNotFalse( has_action( 'template_redirect', array( $this->experiment, 'handle_template_redirect' ) ) );
		$this->assertNotFalse( has_action( 'wp_head', array( $this->experiment, 'add_discovery_links' ) ) );
		$this->assertNotFalse( has_filter( 'feed_content_type', array( $this->experiment, 'filter_feed_content_type' ) ) );
	}

	/**
	 * Tests that the feed content type is mapped to text/markdown for the
	 * markdown feed and left untouched for other feeds.
	 */
	public function test_feed_content_type_filtered(): void {
		$this->experiment->register();

		$this->assertSame( 'text/markdown', feed_content_type( Markdown_Feeds::FEED_NAME ) );
		$this->assertSame( 'application/rss+xml', feed_content_type( 'rss2' ) );
	}

	/**
	 * Tests that ?format=md on a published singular post yields markdown.
	 */
	public function test_singular_markdown_served_for_published_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Singular Target',
				'post_status' => 'publish',
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		$_GET['format'] = 'md';

		$markdown = $this->experiment->get_singular_markdown();

		$this->assertNotNull( $markdown );
		$this->assertStringContainsString( '# Singular Target', $markdown );
	}

	/**
	 * Tests that ?format=md is ignored on non-singular views.
	 */
	public function test_format_param_ignored_on_home(): void {
		self::factory()->post->create();

		$this->go_to( '/' );
		$_GET['format'] = 'md';

		$this->assertNull( $this->experiment->get_singular_markdown() );
	}

	/**
	 * Tests that password-protected posts are never served as markdown.
	 */
	public function test_password_protected_post_refused(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_password' => 'secret',
				'post_status'   => 'publish',
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		$_GET['format'] = 'md';

		$this->assertNull( $this->experiment->get_singular_markdown() );
	}

	/**
	 * Tests that private posts are not served to anonymous visitors.
	 */
	public function test_private_post_refused_for_anonymous(): void {
		wp_set_current_user( 0 );
		$post_id = self::factory()->post->create( array( 'post_status' => 'private' ) );

		$this->go_to( get_permalink( $post_id ) );
		$_GET['format'] = 'md';

		$this->assertNull( $this->experiment->get_singular_markdown() );
	}

	/**
	 * Tests Accept-header negotiation is gated behind the default-off sub-toggle.
	 */
	public function test_accept_header_respects_sub_toggle(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';

		// Toggle off (default): Accept header alone must not trigger markdown.
		$this->assertNull( $this->experiment->get_singular_markdown() );

		// Toggle on: Accept header now negotiates markdown.
		update_option( Markdown_Feeds::get_field_option_name( 'accept_header' ), true );
		$this->assertNotNull( $this->experiment->get_singular_markdown() );
	}

	/**
	 * Tests that discovery link tags are emitted on singular views.
	 */
	public function test_discovery_links_on_singular(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		$this->experiment->add_discovery_links();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="text/markdown"', $output );
		$this->assertStringContainsString( 'format=md', $output );
		$this->assertStringContainsString( 'feed=markdown', $output );
	}

	/**
	 * Tests the deferred rewrite-flush flag lifecycle.
	 */
	public function test_rewrite_flush_flag_lifecycle(): void {
		$this->experiment->schedule_rewrite_flush();
		$this->assertNotFalse( get_option( Markdown_Feeds::FLUSH_FLAG_OPTION ) );

		$this->experiment->maybe_flush_rewrite_rules();
		$this->assertFalse( get_option( Markdown_Feeds::FLUSH_FLAG_OPTION ) );
	}

	/**
	 * Tests that toggling the experiment's enabled option schedules a flush.
	 */
	public function test_enabled_option_change_schedules_flush(): void {
		$this->experiment->register_settings();

		update_option( 'wpai_feature_markdown-feeds_enabled', true );

		$this->assertNotFalse( get_option( Markdown_Feeds::FLUSH_FLAG_OPTION ) );
	}

	/**
	 * Tests that nothing is registered while the experiment is disabled.
	 */
	public function test_disabled_experiment_registers_nothing(): void {
		$this->assertFalse( $this->experiment->is_enabled() );
		$this->assertFalse( has_action( 'do_feed_markdown' ) );
		$this->assertFalse( has_filter( 'feed_content_type', array( $this->experiment, 'filter_feed_content_type' ) ) );
		$this->assertFalse( has_action( 'template_redirect', array( $this->experiment, 'handle_template_redirect' ) ) );
		$this->assertFalse( has_action( 'wp_head', array( $this->experiment, 'add_discovery_links' ) ) );
	}

	/**
	 * Tests that the Vary: Accept header is emitted (appended) on singular
	 * views exactly when Accept negotiation is enabled.
	 */
	public function test_vary_accept_header_emitted_when_negotiation_enabled(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		$recorder = new class() extends Markdown_Feeds {
			/**
			 * Recorded header calls.
			 *
			 * @var array<int, array{0: string, 1: bool}>
			 */
			public $sent = array();

			/**
			 * Records instead of sending.
			 *
			 * @param string $header  Header line.
			 * @param bool   $replace Replace flag.
			 */
			protected function send_header( string $header, bool $replace = true ): void {
				$this->sent[] = array( $header, $replace );
			}
		};

		// Toggle off (default): no Vary header.
		$recorder->handle_template_redirect();
		$this->assertSame( array(), $recorder->sent );

		// Toggle on: Vary: Accept appended (replace = false). No ?format=md is
		// set, so the handler returns before its exit path.
		update_option( Markdown_Feeds::get_field_option_name( 'accept_header' ), true );
		$recorder->handle_template_redirect();
		$this->assertContains( array( 'Vary: Accept', false ), $recorder->sent );
	}

	/**
	 * Tests that the singular discovery link is suppressed for
	 * password-protected posts while the feed link remains.
	 */
	public function test_discovery_link_suppressed_for_password_protected_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_password' => 'secret',
				'post_status'   => 'publish',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		$this->experiment->add_discovery_links();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'feed=markdown', $output );
		$this->assertStringNotContainsString( 'format=md', $output );
	}
}
