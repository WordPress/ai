<?php
/**
 * Tests for the embedding client's error classification.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use RuntimeException;
use Throwable;
use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Embedding_Client;
use WordPress\AI\Embeddings\Sync\Embedding_Client_Exception;
use WordPress\AI\Embeddings\Sync\Embedding_Target;
use WordPress\AiClient\Common\Exception\InvalidArgumentException as Ai_Invalid_Argument_Exception;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\NetworkException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;

/**
 * Embedding_Client test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Embedding_Client
 * @covers \WordPress\AI\Embeddings\Sync\Embedding_Client_Exception
 */
class Embedding_ClientTest extends WP_UnitTestCase {

	/**
	 * Outbound HTTP requests attempted during the current test.
	 *
	 * @var int
	 */
	private int $http_requests = 0;

	/**
	 * Tests the classification table from the spec (§9).
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_classification
	 *
	 * @param \Throwable $error    The SDK failure.
	 * @param string     $expected The expected class.
	 */
	public function test_classify( Throwable $error, string $expected ): void {
		$this->assertSame( $expected, Embedding_Client::classify( $error ) );
	}

	/**
	 * Data provider for classification.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: \Throwable, 1: string}> Cases.
	 */
	public function data_classification(): array {
		return array(
			'429'           => array( new ClientException( 'Too Many Requests (429)', 429 ), Embedding_Client_Exception::RATE_LIMITED ),
			'401'           => array( new ClientException( 'Unauthorized (401)', 401 ), Embedding_Client_Exception::PROVIDER ),
			'403'           => array( new ClientException( 'Forbidden (403)', 403 ), Embedding_Client_Exception::PROVIDER ),
			'404'           => array( new ClientException( 'Not Found (404)', 404 ), Embedding_Client_Exception::PROVIDER ),
			'408'           => array( new ClientException( 'Request Timeout (408)', 408 ), Embedding_Client_Exception::TRANSIENT ),
			'400'           => array( new ClientException( 'Bad Request (400)', 400 ), Embedding_Client_Exception::ITEM ),
			'422'           => array( new ClientException( 'Unprocessable Entity (422)', 422 ), Embedding_Client_Exception::ITEM ),
			'token limit'   => array( new TokenLimitReachedException( 'Too long' ), Embedding_Client_Exception::ITEM ),
			'5xx'           => array( new ServerException( 'Server error (503)', 503 ), Embedding_Client_Exception::TRANSIENT ),
			'network'       => array( new NetworkException( 'Timed out' ), Embedding_Client_Exception::TRANSIENT ),
			'unknown model' => array( new Ai_Invalid_Argument_Exception( 'Provider not registered: nope' ), Embedding_Client_Exception::PROVIDER ),
			'anything else' => array( new RuntimeException( 'Odd' ), Embedding_Client_Exception::TRANSIENT ),
		);
	}

	/**
	 * Tests that the exception carries its class and keeps the original as previous.
	 *
	 * @since x.x.x
	 */
	public function test_exception_carries_its_class(): void {
		$previous  = new RuntimeException( 'root' );
		$exception = new Embedding_Client_Exception( 'Wrapped', Embedding_Client_Exception::ITEM, $previous );

		$this->assertSame( Embedding_Client_Exception::ITEM, $exception->get_error_class() );
		$this->assertSame( $previous, $exception->getPrevious() );
	}

	/**
	 * Tests that an unregistered provider fails as a provider-level error without any HTTP request.
	 *
	 * The SDK resolves the provider before it sends anything, so this path never reaches the network.
	 *
	 * @since x.x.x
	 */
	public function test_embed_with_unregistered_provider_throws_provider_error(): void {
		$this->block_http_requests();

		try {
			( new Embedding_Client() )->embed( new Embedding_Target( 'wpai-nonexistent-provider', 'some-model' ), array( 'Hello' ) );
			$this->fail( 'Expected an Embedding_Client_Exception.' );
		} catch ( Embedding_Client_Exception $e ) {
			$this->assertSame( Embedding_Client_Exception::PROVIDER, $e->get_error_class() );
			$this->assertNotNull( $e->getPrevious() );
		}

		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * Tests that embedding no inputs returns nothing without any request.
	 *
	 * @since x.x.x
	 */
	public function test_embed_with_no_inputs_returns_empty_without_a_request(): void {
		$this->block_http_requests();

		$this->assertSame( array(), ( new Embedding_Client() )->embed( new Embedding_Target( 'wpai-nonexistent-provider', 'some-model' ), array() ) );
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * Starts counting and blocking outbound HTTP requests for the rest of the test.
	 *
	 * @since x.x.x
	 */
	private function block_http_requests(): void {
		$this->http_requests = 0;

		add_filter( 'pre_http_request', array( $this, 'record_http_request' ) );
	}

	/**
	 * Records an attempted HTTP request and short-circuits it.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error The error returned instead of performing the request.
	 */
	public function record_http_request(): \WP_Error {
		++$this->http_requests;

		return new \WP_Error( 'http_blocked', 'Blocked in tests.' );
	}
}
