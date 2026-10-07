<?php
/**
 * Tests for provider backoff.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Embedding_Client_Exception;
use WordPress\AI\Embeddings\Sync\Embedding_Target;
use WordPress\AI\Embeddings\Sync\Provider_Backoff;

/**
 * Provider_Backoff test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Provider_Backoff
 */
class Provider_BackoffTest extends WP_UnitTestCase {

	/**
	 * Tests that rate limits double the delay up to the cap.
	 *
	 * @since x.x.x
	 */
	public function test_rate_limits_back_off_exponentially_to_a_cap(): void {
		$backoff = new Provider_Backoff();
		$now     = 1000000;

		$this->assertSame( $now + 30, $backoff->record_rate_limit( 'openai', 'Too Many Requests (429)', $now ) );
		$this->assertSame( $now + 60, $backoff->record_rate_limit( 'openai', 'Too Many Requests (429)', $now ) );
		$this->assertSame( $now + 120, $backoff->record_rate_limit( 'openai', 'Too Many Requests (429)', $now ) );

		for ( $i = 0; $i < 10; $i++ ) {
			$until = $backoff->record_rate_limit( 'openai', 'Too Many Requests (429)', $now );
		}

		$this->assertSame( $now + Provider_Backoff::MAX_DELAY, $until );
	}

	/**
	 * Tests pause state over time, isolation between providers, and clearing.
	 *
	 * @since x.x.x
	 */
	public function test_pause_state(): void {
		$backoff = new Provider_Backoff();
		$now     = time();

		$backoff->record_rate_limit( 'openai', 'Too Many Requests (429)', $now );

		$this->assertTrue( $backoff->is_paused( 'openai', $now ) );
		$this->assertFalse( $backoff->is_paused( 'openai', $now + 31 ) );
		$this->assertFalse( $backoff->is_paused( 'google', $now ) );

		$backoff->clear( 'openai' );

		$this->assertNull( $backoff->get( 'openai' ) );
		$this->assertSame( $now + 30, $backoff->record_rate_limit( 'openai', 'again', $now ) );
	}

	/**
	 * Tests the hour-long provider error pause, that model-scoped pauses do not affect other models, and that get_until_for() combines both scopes.
	 *
	 * @since x.x.x
	 */
	public function test_model_scoped_pause(): void {
		$backoff = new Provider_Backoff();
		$now     = time();
		$a       = new Embedding_Target( 'openai', 'model-a' );
		$b       = new Embedding_Target( 'openai', 'model-b' );

		$this->assertSame( $now + Provider_Backoff::PROVIDER_PAUSE, $backoff->record_provider_error( 'openai', 'Not Found (404)', $now, 'model-a' ) );

		$state = $backoff->get( 'openai', 'model-a' );

		$this->assertSame( Embedding_Client_Exception::PROVIDER, $state['error_class'] );
		$this->assertSame( 'Not Found (404)', $state['error'] );
		$this->assertSame( $now + Provider_Backoff::PROVIDER_PAUSE, $backoff->get_until_for( $a, $now ) );
		$this->assertNull( $backoff->get_until_for( $b, $now ) );
		$this->assertFalse( $backoff->is_paused( 'openai', $now ), 'Provider-wide state is untouched.' );

		$backoff->record_rate_limit( 'openai', 'Too Many Requests (429)', $now );

		$this->assertSame( $now + Provider_Backoff::MIN_DELAY, $backoff->get_until_for( $b, $now ), 'Rate limits stay provider-wide.' );
		$this->assertSame( $now + Provider_Backoff::PROVIDER_PAUSE, $backoff->get_until_for( $a, $now ), 'The later pause wins.' );

		$backoff->clear( 'openai', 'model-a' );

		$this->assertNull( $backoff->get( 'openai', 'model-a' ) );

		// A provider-wide error pauses for the same hour and records its error.
		$this->assertSame( $now + Provider_Backoff::PROVIDER_PAUSE, $backoff->record_provider_error( 'openai', 'Unauthorized (401)', $now ) );

		$state = $backoff->get( 'openai' );

		$this->assertSame( Embedding_Client_Exception::PROVIDER, $state['error_class'] );
		$this->assertSame( 'Unauthorized (401)', $state['error'] );
	}

	/**
	 * Tests that a stored error is decoded, so HTML-escaped text is kept raw.
	 *
	 * @since x.x.x
	 */
	public function test_stored_error_is_decoded(): void {
		$backoff = new Provider_Backoff();

		$backoff->record_provider_error( 'openai', esc_html( 'Model "a" & <b>' ), null, 'model-a' );

		$this->assertSame( 'Model "a" & <b>', $backoff->get( 'openai', 'model-a' )['error'] );
	}
}
