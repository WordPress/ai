<?php
/**
 * Integration tests for the Content_Gap_Suggestions Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content_Gap_Suggestions\Content_Gap_Suggestions;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Stats\Stats_Provider;

/**
 * Test experiment for Content_Gap_Suggestions Ability tests.
 *
 * @since x.x.x
 */
class Test_Content_Gap_Suggestions_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'content-gap-suggestions';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Content Gap Suggestions',
			'description' => 'Surfaces new post topic ideas based on anonymized search patterns.',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Content_Gap_Suggestions Ability test case.
 *
 * @since x.x.x
 */
class Content_Gap_SuggestionsTest extends WP_UnitTestCase {

	/**
	 * Content_Gap_Suggestions ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Content_Gap_Suggestions\Content_Gap_Suggestions
	 */
	private $ability;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$experiment    = new Test_Content_Gap_Suggestions_Experiment();
		$this->ability = new Content_Gap_Suggestions(
			'ai/content-gap-suggestions',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wpai_stats_providers' );
		parent::tearDown();
	}

	/**
	 * Test that guideline_categories() returns site and copy.
	 *
	 * @since x.x.x
	 */
	public function test_guideline_categories_returns_site_and_copy(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'guideline_categories' );
		$method->setAccessible( true );

		$this->assertSame( array( 'site', 'copy' ), $method->invoke( $this->ability ) );
	}

	/**
	 * Test that input_schema() returns the expected structure.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_returns_expected_structure(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'input_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'limit', $schema['properties'] );
	}

	/**
	 * Test that output_schema() returns the expected structure.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_returns_expected_structure(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'suggestions', $schema['properties'] );
	}

	/**
	 * Test that meta() reports show_in_rest.
	 *
	 * @since x.x.x
	 */
	public function test_meta_returns_expected_structure(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'meta' );
		$method->setAccessible( true );

		$this->assertSame( array( 'show_in_rest' => true ), $method->invoke( $this->ability ) );
	}

	/**
	 * Test that suggestions_schema() omits additionalProperties.
	 *
	 * Google's Gemini structured-output schema (a restricted OpenAPI subset)
	 * has no `additionalProperties` field at all - sending it causes a 400
	 * "Cannot find field" error at the `generation_config.response_schema`
	 * level. This guards against reintroducing it.
	 *
	 * @since x.x.x
	 */
	public function test_suggestions_schema_omits_additional_properties(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'suggestions_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertArrayNotHasKey( 'additionalProperties', $schema );
		$this->assertArrayNotHasKey(
			'additionalProperties',
			$schema['properties']['suggestions']['items']
		);
	}

	/**
	 * Test that suggestions_schema() returns the expected structure.
	 *
	 * @since x.x.x
	 */
	public function test_suggestions_schema_returns_expected_structure(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'suggestions_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array( 'suggestions' ), $schema['required'] );

		$items = $schema['properties']['suggestions']['items'];
		$this->assertSame( array( 'title', 'outline' ), $items['required'] );
		$this->assertArrayHasKey( 'title', $items['properties'] );
		$this->assertArrayHasKey( 'outline', $items['properties'] );
	}

	/**
	 * Test that permission_callback() denies users without edit_posts.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_denies_without_edit_posts(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() allows users with edit_posts.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_allows_with_edit_posts(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->ability, array() ) );
	}

	/**
	 * Test that execute_callback() errors when no Stats_Provider is available.
	 *
	 * No analytics plugin is installed in the test environment, so the
	 * default registry state (no available provider) is exercised here.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_errors_without_stats_provider(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_stats_provider', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() returns an empty list when the available
	 * provider has no query data that survives anonymization.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_empty_suggestions_when_no_patterns_survive(): void {
		add_filter(
			'wpai_stats_providers',
			static function () {
				return array(
					new class() implements Stats_Provider {
						public function get_id(): string {
							return 'test-provider';
						}

						public function is_available(): bool {
							return true;
						}

						public function get_search_queries( array $args = array() ) {
							// A single-occurrence term is dropped by the Anonymizer.
							return array(
								array(
									'term'  => 'one off query',
									'count' => 1,
								),
							);
						}

						public function get_post_traffic( int $post_id, array $args = array() ) {
							return array();
						}
					},
				);
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array() );

		$this->assertSame( array( 'suggestions' => array() ), $result );
	}

	/**
	 * Test that execute_callback() surfaces a Stats_Provider error.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_surfaces_stats_provider_error(): void {
		add_filter(
			'wpai_stats_providers',
			static function () {
				return array(
					new class() implements Stats_Provider {
						public function get_id(): string {
							return 'failing-provider';
						}

						public function is_available(): bool {
							return true;
						}

						public function get_search_queries( array $args = array() ) {
							return new WP_Error( 'upstream_failure', 'Upstream API failed.' );
						}

						public function get_post_traffic( int $post_id, array $args = array() ) {
							return array();
						}
					},
				);
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'upstream_failure', $result->get_error_code() );
	}

	/**
	 * Test that parse_suggestions() parses valid JSON and sanitizes fields.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_with_valid_json(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [{"title": "A Great Title", "outline": "- point one\n- point two"}]}';

		$result = $method->invoke( $this->ability, $response, 5 );

		$this->assertCount( 1, $result );
		$this->assertSame( 'A Great Title', $result[0]['title'] );
		$this->assertStringContainsString( 'point one', $result[0]['outline'] );
	}

	/**
	 * Test that parse_suggestions() returns a WP_Error for invalid JSON.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_with_invalid_json(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, 'not json', 5 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_response', $result->get_error_code() );
	}

	/**
	 * Test that parse_suggestions() limits results.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_limits_results(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = wp_json_encode(
			array(
				'suggestions' => array(
					array(
						'title'   => 'One',
						'outline' => '- a',
					),
					array(
						'title'   => 'Two',
						'outline' => '- b',
					),
					array(
						'title'   => 'Three',
						'outline' => '- c',
					),
				),
			)
		);

		$result = $method->invoke( $this->ability, $response, 2 );

		$this->assertCount( 2, $result );
	}

	/**
	 * Test that parse_suggestions() skips entries missing required fields.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_skips_incomplete_entries(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = wp_json_encode(
			array(
				'suggestions' => array(
					array( 'title' => 'Missing outline' ),
					array(
						'title'   => 'Complete',
						'outline' => '- a',
					),
				),
			)
		);

		$result = $method->invoke( $this->ability, $response, 5 );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Complete', $result[0]['title'] );
	}
}
