<?php
/**
 * Integration tests for the Jetpack_Stats_Provider class.
 *
 * @package WordPress\AI\Tests\Integration\Stats\Providers
 */

namespace WordPress\AI\Tests\Integration\Stats\Providers;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Stats\Providers\Jetpack_Stats_Provider;

/**
 * Jetpack_Stats_Provider test case.
 *
 * @since x.x.x
 */
class Jetpack_Stats_ProviderTest extends WP_UnitTestCase {

	/**
	 * Provider instance under test.
	 *
	 * @var Jetpack_Stats_Provider
	 */
	private Jetpack_Stats_Provider $provider;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 3 ) . '/stubs/jetpack-stats.php';
		unset( $GLOBALS['wpai_test_stats_response'], $GLOBALS['wpai_test_stats_request'] );

		$this->provider = new Jetpack_Stats_Provider();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_jetpack_stats_provider_available' );
		unset( $GLOBALS['wpai_test_stats_response'], $GLOBALS['wpai_test_stats_request'] );
		parent::tearDown();
	}

	/**
	 * Tests that get_id() returns the expected identifier.
	 *
	 * @since x.x.x
	 */
	public function test_get_id(): void {
		$this->assertSame( 'jetpack-stats', $this->provider->get_id() );
	}

	/**
	 * Tests that is_available() honors the availability filter once Jetpack Stats exists.
	 *
	 * @since x.x.x
	 */
	public function test_is_available_respects_filter(): void {
		$this->assertTrue( $this->provider->is_available() );

		add_filter( 'wpai_jetpack_stats_provider_available', '__return_false' );

		$this->assertFalse( $this->provider->is_available() );
	}

	/**
	 * Tests that get_search_queries() errors when unavailable.
	 *
	 * @since x.x.x
	 */
	public function test_get_search_queries_errors_when_unavailable(): void {
		add_filter( 'wpai_jetpack_stats_provider_available', '__return_false' );

		$result = $this->provider->get_search_queries();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stats_provider_unavailable', $result->get_error_code() );
	}

	/**
	 * Tests that get_post_traffic() errors when unavailable.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_traffic_errors_when_unavailable(): void {
		add_filter( 'wpai_jetpack_stats_provider_available', '__return_false' );

		$result = $this->provider->get_post_traffic( 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stats_provider_unavailable', $result->get_error_code() );
	}

	/**
	 * Tests that get_search_queries() requests search-terms and parses the response.
	 *
	 * @since x.x.x
	 */
	public function test_get_search_queries_returns_parsed_terms(): void {
		$GLOBALS['wpai_test_stats_response'] = array(
			'search-terms' => array(
				array(
					'term'  => 'garden',
					'views' => 4,
				),
			),
		);

		$result = $this->provider->get_search_queries( array( 'limit' => 5, 'days' => 7 ) );

		$this->assertSame(
			array(
				array(
					'term'  => 'garden',
					'count' => 4,
				),
			),
			$result
		);
		$this->assertSame( 'search-terms', $GLOBALS['wpai_test_stats_request'][1] );
		$this->assertSame( 7, $GLOBALS['wpai_test_stats_request'][0]['num'] );
		$this->assertSame( 5, $GLOBALS['wpai_test_stats_request'][0]['max'] );
	}

	/**
	 * Tests that get_search_queries() passes through upstream errors.
	 *
	 * @since x.x.x
	 */
	public function test_get_search_queries_passes_through_wp_error(): void {
		$GLOBALS['wpai_test_stats_response'] = new WP_Error( 'upstream', 'Nope' );

		$result = $this->provider->get_search_queries();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'upstream', $result->get_error_code() );
	}

	/**
	 * Tests that get_post_traffic() requests the post resource and parses the response.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_traffic_returns_parsed_series(): void {
		$GLOBALS['wpai_test_stats_response'] = array(
			'data' => array( '2026-08-01' => 3 ),
		);

		$result = $this->provider->get_post_traffic( 42, array( 'days' => 3 ) );

		$this->assertSame(
			array(
				array(
					'date'  => '2026-08-01',
					'views' => 3,
				),
			),
			$result
		);
		$this->assertSame( 'post/42', $GLOBALS['wpai_test_stats_request'][1] );
		$this->assertSame( 3, $GLOBALS['wpai_test_stats_request'][0]['num'] );
	}

	/**
	 * Tests that get_post_traffic() passes through upstream errors.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_traffic_passes_through_wp_error(): void {
		$GLOBALS['wpai_test_stats_response'] = new WP_Error( 'upstream', 'Nope' );

		$result = $this->provider->get_post_traffic( 42 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'upstream', $result->get_error_code() );
	}

	/**
	 * Tests that get_post_traffic() validates the post ID before availability.
	 *
	 * The unavailable-provider error takes precedence in the current
	 * environment, but a zero/negative post ID must never reach the API call.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_traffic_rejects_invalid_post_id(): void {
		$reflection = new \ReflectionClass( $this->provider );
		$method     = $reflection->getMethod( 'get_post_traffic' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->provider, 0 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Tests that parse_search_terms_response() normalizes a valid response.
	 *
	 * @since x.x.x
	 */
	public function test_parse_search_terms_response_normalizes_valid_data(): void {
		$reflection = new \ReflectionClass( $this->provider );
		$method     = $reflection->getMethod( 'parse_search_terms_response' );
		$method->setAccessible( true );

		$response = array(
			'search-terms' => array(
				array(
					'term'  => 'wordpress hosting',
					'views' => 42,
				),
				array(
					'term' => 'no views field',
				),
			),
		);

		$result = $method->invoke( $this->provider, $response );

		$this->assertSame(
			array(
				array(
					'term'  => 'wordpress hosting',
					'count' => 42,
				),
				array(
					'term'  => 'no views field',
					'count' => 1,
				),
			),
			$result
		);
	}

	/**
	 * Tests that parse_search_terms_response() skips entries missing a term.
	 *
	 * @since x.x.x
	 */
	public function test_parse_search_terms_response_skips_entries_without_term(): void {
		$reflection = new \ReflectionClass( $this->provider );
		$method     = $reflection->getMethod( 'parse_search_terms_response' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->provider,
			array(
				'search-terms' => array(
					array( 'views' => 5 ),
					'not-an-array',
				),
			)
		);

		$this->assertSame( array(), $result );
	}

	/**
	 * Tests that parse_search_terms_response() returns an empty array for malformed responses.
	 *
	 * @since x.x.x
	 */
	public function test_parse_search_terms_response_handles_malformed_response(): void {
		$reflection = new \ReflectionClass( $this->provider );
		$method     = $reflection->getMethod( 'parse_search_terms_response' );
		$method->setAccessible( true );

		$this->assertSame( array(), $method->invoke( $this->provider, 'not-an-array' ) );
		$this->assertSame( array(), $method->invoke( $this->provider, array() ) );
		$this->assertSame( array(), $method->invoke( $this->provider, array( 'search-terms' => 'nope' ) ) );
	}

	/**
	 * Tests that parse_post_traffic_response() normalizes a valid response.
	 *
	 * @since x.x.x
	 */
	public function test_parse_post_traffic_response_normalizes_valid_data(): void {
		$reflection = new \ReflectionClass( $this->provider );
		$method     = $reflection->getMethod( 'parse_post_traffic_response' );
		$method->setAccessible( true );

		$response = array(
			'data' => array(
				'2026-08-01' => 12,
				'2026-08-02' => '7',
			),
		);

		$result = $method->invoke( $this->provider, $response );

		$this->assertSame(
			array(
				array(
					'date'  => '2026-08-01',
					'views' => 12,
				),
				array(
					'date'  => '2026-08-02',
					'views' => 7,
				),
			),
			$result
		);
	}

	/**
	 * Tests that parse_post_traffic_response() returns an empty array for malformed responses.
	 *
	 * @since x.x.x
	 */
	public function test_parse_post_traffic_response_handles_malformed_response(): void {
		$reflection = new \ReflectionClass( $this->provider );
		$method     = $reflection->getMethod( 'parse_post_traffic_response' );
		$method->setAccessible( true );

		$this->assertSame( array(), $method->invoke( $this->provider, 'not-an-array' ) );
		$this->assertSame( array(), $method->invoke( $this->provider, array( 'data' => 'nope' ) ) );
	}
}
