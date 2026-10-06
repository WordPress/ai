<?php
/**
 * Tests for provider backoff.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Embedding_Client_Exception;
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
	 * Tests the provider-level pause and its recorded error.
	 *
	 * @since x.x.x
	 */
	public function test_provider_error_pauses_for_an_hour(): void {
		$backoff = new Provider_Backoff();
		$now     = time();

		$this->assertSame( $now + Provider_Backoff::PROVIDER_PAUSE, $backoff->record_provider_error( 'openai', 'Unauthorized (401)', $now ) );

		$state = $backoff->get( 'openai' );

		$this->assertSame( Embedding_Client_Exception::PROVIDER, $state['error_class'] );
		$this->assertSame( 'Unauthorized (401)', $state['error'] );
	}
}
