<?php
/**
 * Integration tests for the Embeddings_Controller class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\REST
 */

namespace WordPress\AI\Tests\Integration\Includes\REST;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Sync\Embedding_Sync;
use WordPress\AI\REST\Embeddings_Controller;
use WordPress\AI\Tests\Integration\Includes\Embeddings\Sync\Fake_Embedding_Client;
use WordPress\AI\Tests\Integration\Includes\Embeddings\Sync\Sync_Tables_Trait;

/**
 * Embeddings_Controller test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\REST\Embeddings_Controller
 */
class Embeddings_ControllerTest extends WP_UnitTestCase {

	use Sync_Tables_Trait;

	private const CONSUMER = 'test-consumer';

	private const ROUTE = '/ai/v1/embeddings/consumers/' . self::CONSUMER;

	/**
	 * Sync facade used by the controller.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Embedding_Sync
	 */
	private Embedding_Sync $sync;

	/**
	 * Creates the tables once for the class.
	 *
	 * @since x.x.x
	 */
	public static function wpSetUpBeforeClass(): void {
		self::create_sync_tables();
	}

	/**
	 * Drops the tables after the class.
	 *
	 * @since x.x.x
	 */
	public static function wpTearDownAfterClass(): void {
		self::drop_sync_tables();
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->sync = new Embedding_Sync( null, new Fake_Embedding_Client() );
		Embedding_Sync::set_instance( $this->sync );

		( new Embeddings_Controller() )->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	protected function tearDown(): void {
		Embedding_Sync::set_instance( null );

		parent::tearDown();
	}

	/**
	 * Registers the test consumer on a model indexing tags.
	 *
	 * @since x.x.x
	 */
	private function register_consumer(): void {
		$this->assertTrue(
			Embedding_Sync::register_consumer(
				self::CONSUMER,
				array(
					'provider' => 'openai',
					'model'    => 'text-embedding-3-small',
					'objects'  => array( 'term' => array( 'post_tag' ) ),
				)
			)
		);
	}

	/**
	 * Tests that every route rejects users without manage_options.
	 *
	 * @since x.x.x
	 */
	public function test_routes_require_manage_options(): void {
		$this->register_consumer();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$requests = array(
			new WP_REST_Request( 'GET', self::ROUTE ),
			new WP_REST_Request( 'POST', self::ROUTE . '/backfill' ),
			new WP_REST_Request( 'DELETE', self::ROUTE . '/backfill' ),
		);

		foreach ( $requests as $request ) {
			$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status(), $request->get_method() );
		}
	}

	/**
	 * Tests that the status carries the target and totals summed over coverage.
	 *
	 * @since x.x.x
	 */
	public function test_status_includes_totals_and_target(): void {
		$this->register_consumer();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$tag_ids = self::factory()->tag->create_many( 2 );
		$this->sync->get_repository()->save(
			new Embedding_Record( 'term', $tag_ids[0], 'openai', 'text-embedding-3-small', array( 0.1, 0.2, 0.3 ), 0, 'hash', 0, 'post_tag' )
		);

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'openai', $data['target']['provider'] );
		$this->assertSame( 'text-embedding-3-small', $data['target']['model'] );
		$this->assertSame(
			array(
				'indexed'   => 1,
				'indexable' => 2,
			),
			$data['totals']
		);
	}
}
