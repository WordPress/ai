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
 * Jetpack provider with the Jetpack-dependent checks and client replaced.
 *
 * Jetpack isn't installed in the test environment, and loading fake Jetpack
 * classes would leave them defined for every later test, so the provider's
 * protected seams are overridden instead.
 *
 * @since x.x.x
 */
class Test_Jetpack_Stats_Provider extends Jetpack_Stats_Provider {

	/**
	 * Whether Jetpack is loaded.
	 *
	 * @var bool
	 */
	public bool $loaded = true;

	/**
	 * Whether Jetpack is connected.
	 *
	 * @var bool
	 */
	public bool $connected = true;

	/**
	 * Whether the Stats module is active.
	 *
	 * @var bool
	 */
	public bool $module_active = true;

	/**
	 * Response returned by the fake client.
	 *
	 * @var mixed
	 */
	public $response = array();

	/**
	 * The last client call, as `[ method, args... ]`.
	 *
	 * @var array<int, mixed>
	 */
	public array $request = array();

	/**
	 * {@inheritDoc}
	 */
	protected function is_jetpack_loaded(): bool {
		return $this->loaded;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function is_jetpack_connected(): bool {
		return $this->connected;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function is_stats_module_active(): bool {
		return $this->module_active;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_stats_client(): object {
		$provider = $this;

		return new class( $provider ) {
			/**
			 * The provider recording the calls.
			 *
			 * @var \WordPress\AI\Tests\Integration\Stats\Providers\Test_Jetpack_Stats_Provider
			 */
			private $provider;

			/**
			 * Constructor.
			 *
			 * @param \WordPress\AI\Tests\Integration\Stats\Providers\Test_Jetpack_Stats_Provider $provider The provider.
			 */
			public function __construct( $provider ) {
				$this->provider = $provider;
			}

			/**
			 * Fake search terms request.
			 *
			 * @param array<string, mixed> $args Request args.
			 * @return mixed
			 */
			public function get_search_terms( $args = array() ) {
				$this->provider->request = array( 'get_search_terms', $args );
				return $this->provider->response;
			}
		};
	}
}

/**
 * Jetpack_Stats_Provider test case.
 *
 * @since x.x.x
 */
class Jetpack_Stats_ProviderTest extends WP_UnitTestCase {

	/**
	 * Provider instance under test.
	 *
	 * @var \WordPress\AI\Tests\Integration\Stats\Providers\Test_Jetpack_Stats_Provider
	 */
	private Test_Jetpack_Stats_Provider $provider;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->provider = new Test_Jetpack_Stats_Provider();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_jetpack_stats_provider_available' );
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
	 * Tests that the real provider is unavailable when Jetpack isn't installed.
	 *
	 * @since x.x.x
	 */
	public function test_is_available_false_without_jetpack(): void {
		$this->assertFalse( ( new Jetpack_Stats_Provider() )->is_available() );
	}

	/**
	 * Tests that the provider is available when Jetpack is connected with Stats on.
	 *
	 * @since x.x.x
	 */
	public function test_is_available_when_ready(): void {
		$this->assertTrue( $this->provider->is_available() );
	}

	/**
	 * Tests that the provider is unavailable when Jetpack isn't loaded.
	 *
	 * @since x.x.x
	 */
	public function test_is_available_false_when_not_loaded(): void {
		$this->provider->loaded = false;

		$this->assertFalse( $this->provider->is_available() );
	}

	/**
	 * Tests that the provider is unavailable when Jetpack isn't connected.
	 *
	 * @since x.x.x
	 */
	public function test_is_available_false_when_not_connected(): void {
		$this->provider->connected = false;

		$this->assertFalse( $this->provider->is_available() );
	}

	/**
	 * Tests that the provider is unavailable when the Stats module is off.
	 *
	 * @since x.x.x
	 */
	public function test_is_available_false_when_stats_module_inactive(): void {
		$this->provider->module_active = false;

		$this->assertFalse( $this->provider->is_available() );
	}

	/**
	 * Tests that the availability filter can disable the provider.
	 *
	 * @since x.x.x
	 */
	public function test_is_available_respects_filter(): void {
		add_filter( 'wpai_jetpack_stats_provider_available', '__return_false' );

		$this->assertFalse( $this->provider->is_available() );
	}

	/**
	 * Tests that get_search_queries() errors when unavailable.
	 *
	 * @since x.x.x
	 */
	public function test_get_search_queries_errors_when_unavailable(): void {
		$this->provider->connected = false;

		$result = $this->provider->get_search_queries();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stats_provider_unavailable', $result->get_error_code() );
	}

	/**
	 * Tests that get_search_queries() requests the summary and parses it.
	 *
	 * @since x.x.x
	 */
	public function test_get_search_queries_returns_parsed_terms(): void {
		$this->provider->response = array(
			'summary' => array(
				'search_terms'           => array(
					array(
						'term'  => 'garden',
						'views' => 4,
					),
				),
				'encrypted_search_terms' => 12,
			),
		);

		$result = $this->provider->get_search_queries(
			array(
				'limit' => 5,
				'days'  => 7,
			)
		);

		$this->assertSame(
			array(
				array(
					'term'  => 'garden',
					'count' => 4,
				),
			),
			$result
		);
		$this->assertSame( 'get_search_terms', $this->provider->request[0] );
		$this->assertSame( 7, $this->provider->request[1]['num'] );
		$this->assertSame( 5, $this->provider->request[1]['max'] );
		$this->assertSame( 1, $this->provider->request[1]['summarize'] );
	}

	/**
	 * Tests that the summary is used alone when the response also has per-day terms.
	 *
	 * Using both would double-count the same searches.
	 *
	 * @since x.x.x
	 */
	public function test_get_search_queries_prefers_summary_over_days(): void {
		$this->provider->response = array(
			'summary' => array(
				'search_terms' => array(
					array(
						'term'  => 'garden',
						'views' => 5,
					),
				),
			),
			'days'    => array(
				'2026-08-01' => array(
					'search_terms' => array(
						array(
							'term'  => 'garden',
							'views' => 5,
						),
					),
				),
			),
		);

		$this->assertSame(
			array(
				array(
					'term'  => 'garden',
					'count' => 5,
				),
			),
			$this->provider->get_search_queries()
		);
	}

	/**
	 * Tests that per-day search terms are merged across days when there is no summary.
	 *
	 * @since x.x.x
	 */
	public function test_get_search_queries_merges_per_day_terms(): void {
		$this->provider->response = array(
			'days' => array(
				'2026-08-01' => array(
					'search_terms' => array(
						array(
							'term'  => 'garden',
							'views' => 2,
						),
					),
				),
				'2026-08-02' => array(
					'search_terms' => array(
						array(
							'term'  => 'garden',
							'views' => 3,
						),
					),
				),
			),
		);

		$this->assertSame(
			array(
				array(
					'term'  => 'garden',
					'count' => 5,
				),
			),
			$this->provider->get_search_queries()
		);
	}

	/**
	 * Tests that object responses are handled.
	 *
	 * @since x.x.x
	 */
	public function test_get_search_queries_handles_object_response(): void {
		$this->provider->response = json_decode(
			(string) wp_json_encode(
				array(
					'summary' => array(
						'search_terms' => array(
							array(
								'term'  => 'garden',
								'views' => 1,
							),
						),
					),
				)
			)
		);

		$this->assertSame( 'garden', $this->provider->get_search_queries()[0]['term'] );
	}

	/**
	 * Tests that entries without a term are skipped and malformed responses are empty.
	 *
	 * @since x.x.x
	 */
	public function test_get_search_queries_handles_malformed_responses(): void {
		$this->provider->response = array(
			'summary' => array(
				'search_terms' => array(
					array( 'views' => 5 ),
					'not-an-array',
					array( 'term' => 'no views field' ),
				),
			),
		);

		$this->assertSame(
			array(
				array(
					'term'  => 'no views field',
					'count' => 1,
				),
			),
			$this->provider->get_search_queries()
		);

		foreach ( array( 'not-an-array', array(), array( 'summary' => array( 'search_terms' => 'nope' ) ) ) as $response ) {
			$this->provider->response = $response;
			$this->assertSame( array(), $this->provider->get_search_queries() );
		}
	}

	/**
	 * Tests that get_search_queries() passes through upstream errors.
	 *
	 * @since x.x.x
	 */
	public function test_get_search_queries_passes_through_wp_error(): void {
		$this->provider->response = new WP_Error( 'upstream', 'Nope' );

		$result = $this->provider->get_search_queries();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'upstream', $result->get_error_code() );
	}

}
