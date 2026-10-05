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
 * @since 1.4.0
 */
class Markdown_FeedsTest extends WP_UnitTestCase {

	/**
	 * Experiment under test.
	 *
	 * @var Markdown_Feeds
	 */
	private $experiment;

	/**
	 * Feed names registered with WordPress before the test ran.
	 *
	 * @var string[]
	 */
	private $feeds;

	/**
	 * Permalink structure in use before the test ran.
	 *
	 * @var string
	 */
	private $permalink_structure;

	/**
	 * Sets up the experiment instance.
	 */
	public function setUp(): void {
		global $wp_rewrite;

		parent::setUp();
		$this->experiment          = new Markdown_Feeds();
		$this->feeds               = $wp_rewrite->feeds;
		$this->permalink_structure = (string) $wp_rewrite->permalink_structure;
	}

	/**
	 * Cleans up request superglobals and rewrite state mutated by tests.
	 */
	public function tearDown(): void {
		global $wp_rewrite;

		unset( $_GET['output_format'], $_SERVER['HTTP_ACCEPT'] );

		$wp_rewrite->feeds = $this->feeds;
		if ( $this->permalink_structure !== (string) $wp_rewrite->permalink_structure ) {
			$this->set_permalink_structure( $this->permalink_structure );
		}

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
	 * Tests that ?output_format=markdown on a published singular post yields markdown.
	 */
	public function test_singular_markdown_served_for_published_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Singular Target',
				'post_status' => 'publish',
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		$_GET['output_format'] = 'markdown';

		$markdown = $this->experiment->get_singular_markdown();

		$this->assertNotNull( $markdown );
		$this->assertStringContainsString( '# Singular Target', $markdown );
	}

	/**
	 * Tests that ?output_format=markdown is ignored on non-singular views.
	 */
	public function test_format_param_ignored_on_home(): void {
		self::factory()->post->create();

		$this->go_to( '/' );
		$_GET['output_format'] = 'markdown';

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
		$_GET['output_format'] = 'markdown';

		$this->assertNull( $this->experiment->get_singular_markdown() );
	}

	/**
	 * Tests that private posts are not served to anonymous visitors.
	 */
	public function test_private_post_refused_for_anonymous(): void {
		wp_set_current_user( 0 );
		$post_id = self::factory()->post->create( array( 'post_status' => 'private' ) );

		$this->go_to( get_permalink( $post_id ) );
		$_GET['output_format'] = 'markdown';

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
		$this->assertStringContainsString( 'output_format=markdown', $output );
		$this->assertStringContainsString( esc_url( get_feed_link( Markdown_Feeds::FEED_NAME ) ), $output );
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
	 * Tells WordPress whether the Markdown feed is registered, as `add_feed()` would.
	 *
	 * @param bool $registered Whether the feed is registered.
	 */
	private function set_feed_registered( bool $registered ): void {
		global $wp_rewrite;

		$wp_rewrite->feeds = array_values( array_diff( $wp_rewrite->feeds, array( Markdown_Feeds::FEED_NAME ) ) );

		if ( ! $registered ) {
			return;
		}

		$wp_rewrite->feeds[] = Markdown_Feeds::FEED_NAME;
	}

	/**
	 * Returns the stored root feed rewrite rule, e.g. `feed/(feed|rdf|rss|rss2|atom)/?$`.
	 */
	private function get_stored_feed_rule(): string {
		$rules = get_option( 'rewrite_rules' );

		return is_array( $rules ) ? (string) array_search( 'index.php?&feed=$matches[1]', $rules, true ) : '';
	}

	/**
	 * Counts how many times the rewrite rules are rebuilt from here on.
	 *
	 * @param int $rebuilds Counter to increment, passed by reference.
	 */
	private function count_rewrite_rule_rebuilds( int &$rebuilds ): void {
		add_filter(
			'rewrite_rules_array',
			static function ( array $rules ) use ( &$rebuilds ): array {
				++$rebuilds;

				return $rules;
			}
		);
	}

	/**
	 * Keeps the Markdown feed out of rebuilt rules, as another plugin's filter could.
	 *
	 * @param array<string, string> $rules Rewrite rules.
	 * @return array<string, string> Rewrite rules without the feed.
	 */
	public function strip_feed_from_rewrite_rules( array $rules ): array {
		$kept = array();

		foreach ( $rules as $regex => $query ) {
			$kept[ str_replace( '|' . Markdown_Feeds::FEED_NAME, '', (string) $regex ) ] = $query;
		}

		return $kept;
	}

	/**
	 * Tests that rules built while the feed was not registered are rebuilt.
	 *
	 * This is the state after a flush that ran without the experiment loaded, for
	 * example while the plugin was inactive, or when the experiment is enabled by filter.
	 */
	public function test_rules_missing_the_registered_feed_are_rebuilt(): void {
		$this->set_feed_registered( false );
		$this->set_permalink_structure( '/%postname%/' );
		$this->assertSame( 'feed/(feed|rdf|rss|rss2|atom)/?$', $this->get_stored_feed_rule() );

		$this->set_feed_registered( true );
		$this->assertFalse( get_option( Markdown_Feeds::FLUSH_FLAG_OPTION ) );

		$this->experiment->maybe_flush_rewrite_rules();

		$this->assertSame( 'feed/(feed|rdf|rss|rss2|atom|markdown)/?$', $this->get_stored_feed_rule() );
	}

	/**
	 * Tests that another feed whose name only starts with the feed name does not hide the missing feed.
	 */
	public function test_feed_with_a_similar_name_does_not_hide_the_missing_feed(): void {
		global $wp_rewrite;

		$this->set_feed_registered( false );
		$wp_rewrite->feeds[] = Markdown_Feeds::FEED_NAME . '-full';
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_feed_registered( true );

		$this->experiment->maybe_flush_rewrite_rules();

		$this->assertSame( 'feed/(feed|rdf|rss|rss2|atom|markdown-full|markdown)/?$', $this->get_stored_feed_rule() );
	}

	/**
	 * Tests that rules which already list the feed are not rebuilt.
	 */
	public function test_rules_listing_the_feed_are_not_rebuilt(): void {
		$this->set_feed_registered( true );
		$this->set_permalink_structure( '/%postname%/' );

		$rebuilds = 0;
		$this->count_rewrite_rule_rebuilds( $rebuilds );

		$this->experiment->maybe_flush_rewrite_rules();

		$this->assertSame( 0, $rebuilds );
	}

	/**
	 * Tests that a rule from elsewhere pointing to the same query does not make the feed look missing.
	 */
	public function test_other_rule_with_the_feed_query_does_not_cause_a_rebuild(): void {
		$this->set_feed_registered( true );

		add_filter(
			'rewrite_rules_array',
			static function ( array $rules ): array {
				return array( 'podcast/(feed|rss2)/?$' => 'index.php?&feed=$matches[1]' ) + $rules;
			}
		);
		$this->set_permalink_structure( '/%postname%/' );

		$rebuilds = 0;
		$this->count_rewrite_rule_rebuilds( $rebuilds );

		$this->experiment->maybe_flush_rewrite_rules();

		$this->assertSame( 0, $rebuilds );
	}

	/**
	 * Tests that rules without a root feed rule are left alone.
	 */
	public function test_rules_without_a_root_feed_rule_are_not_rebuilt(): void {
		$this->set_feed_registered( true );

		add_filter(
			'rewrite_rules_array',
			static function ( array $rules ): array {
				return array_diff( $rules, array( 'index.php?&feed=$matches[1]' ) );
			}
		);
		$this->set_permalink_structure( '/%postname%/' );
		$this->assertSame( '', $this->get_stored_feed_rule() );

		$rebuilds = 0;
		$this->count_rewrite_rule_rebuilds( $rebuilds );

		$this->experiment->maybe_flush_rewrite_rules();

		$this->assertSame( 0, $rebuilds );
	}

	/**
	 * Tests that nothing is rebuilt while the feed is not registered.
	 *
	 * A rule left behind for a feed that is not registered only matches a URL
	 * that answers 404 either way, so it is left for the next regular flush.
	 *
	 * @dataProvider data_feed_listed_in_stored_rules
	 *
	 * @param bool $listed Whether the stored rules list the feed.
	 */
	public function test_rules_are_not_rebuilt_while_feed_is_not_registered( bool $listed ): void {
		$this->set_feed_registered( $listed );
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_feed_registered( false );

		$rebuilds = 0;
		$this->count_rewrite_rule_rebuilds( $rebuilds );

		$this->experiment->maybe_flush_rewrite_rules();

		$this->assertSame( 0, $rebuilds );
	}

	/**
	 * Data provider for the stored rules listing the feed or not.
	 *
	 * @return array<string, array{bool}>
	 */
	public function data_feed_listed_in_stored_rules(): array {
		return array(
			'rules do not list the feed'         => array( false ),
			'rules still list the leftover feed' => array( true ),
		);
	}

	/**
	 * Tests that nothing is rebuilt with plain permalinks, which store no rules.
	 */
	public function test_plain_permalinks_are_not_rebuilt(): void {
		$this->set_permalink_structure( '' );
		$this->set_feed_registered( true );

		$rebuilds = 0;
		$this->count_rewrite_rule_rebuilds( $rebuilds );

		$this->experiment->maybe_flush_rewrite_rules();

		$this->assertSame( 0, $rebuilds );
	}

	/**
	 * Tests that a rebuild which does not bring the feed back is not repeated on every request.
	 */
	public function test_rebuild_that_does_not_help_is_not_repeated(): void {
		$this->set_feed_registered( false );
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_feed_registered( true );

		// Something else keeps the feed out of the generated rules.
		add_filter( 'rewrite_rules_array', array( $this, 'strip_feed_from_rewrite_rules' ) );

		$rebuilds = 0;
		$this->count_rewrite_rule_rebuilds( $rebuilds );

		$this->experiment->maybe_flush_rewrite_rules();
		$this->assertSame( 1, $rebuilds );

		$this->experiment->maybe_flush_rewrite_rules();
		$this->experiment->maybe_flush_rewrite_rules();
		$this->assertSame( 1, $rebuilds );

		// A flush scheduled by the toggle does not wait.
		$this->experiment->schedule_rewrite_flush();
		$this->experiment->maybe_flush_rewrite_rules();
		$this->assertSame( 2, $rebuilds );
	}

	/**
	 * Tests that a flush which brings the feed back ends the wait left by a rebuild that did not help.
	 */
	public function test_flush_that_brings_the_feed_back_ends_the_wait(): void {
		$this->set_feed_registered( false );
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_feed_registered( true );

		// A rebuild that does not help starts the wait.
		add_filter( 'rewrite_rules_array', array( $this, 'strip_feed_from_rewrite_rules' ) );
		$this->experiment->maybe_flush_rewrite_rules();
		$this->assertSame( 'feed/(feed|rdf|rss|rss2|atom)/?$', $this->get_stored_feed_rule() );

		// Nothing strips the feed any more, and a flush scheduled by the toggle brings it back.
		remove_filter( 'rewrite_rules_array', array( $this, 'strip_feed_from_rewrite_rules' ) );
		$this->experiment->schedule_rewrite_flush();
		$this->experiment->maybe_flush_rewrite_rules();
		$this->assertSame( 'feed/(feed|rdf|rss|rss2|atom|markdown)/?$', $this->get_stored_feed_rule() );

		// The rules are rebuilt without the feed again. They are repaired without waiting.
		$this->set_feed_registered( false );
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_feed_registered( true );
		$this->experiment->maybe_flush_rewrite_rules();

		$this->assertSame( 'feed/(feed|rdf|rss|rss2|atom|markdown)/?$', $this->get_stored_feed_rule() );
	}

	/**
	 * Tests that a rebuild which brings the feed back does not delay the next one.
	 */
	public function test_rebuild_that_helps_does_not_delay_the_next_one(): void {
		$this->set_feed_registered( false );
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_feed_registered( true );
		$this->experiment->maybe_flush_rewrite_rules();

		// The rules are rebuilt without the feed a second time.
		$this->set_feed_registered( false );
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_feed_registered( true );
		$this->assertSame( 'feed/(feed|rdf|rss|rss2|atom)/?$', $this->get_stored_feed_rule() );

		$this->experiment->maybe_flush_rewrite_rules();

		$this->assertSame( 'feed/(feed|rdf|rss|rss2|atom|markdown)/?$', $this->get_stored_feed_rule() );
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

		// Toggle on: Vary: Accept appended (replace = false). No ?output_format=markdown is
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

		$this->assertStringContainsString( esc_url( get_feed_link( Markdown_Feeds::FEED_NAME ) ), $output );
		$this->assertStringNotContainsString( 'output_format=markdown', $output );
	}
}
