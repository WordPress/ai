<?php
/**
 * Integration tests for the Speech_Generator class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Text_To_Speech\Speech_Generator;

/**
 * Speech_Generator test case.
 *
 * @since x.x.x
 */
class Speech_GeneratorTest extends WP_UnitTestCase {

	/**
	 * Test that the pre-generate filter short-circuits generation.
	 *
	 * @since x.x.x
	 */
	public function test_pre_generate_filter_short_circuits(): void {
		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function ( $pre, $text, $voice ) {
				return array( 'data' => base64_encode( $text . '|' . $voice ) );
			},
			10,
			3
		);

		$result = ( new Speech_Generator() )->generate_chunk( 'Hello.', 'nova' );

		$this->assertIsArray( $result );
		$this->assertSame( base64_encode( 'Hello.|nova' ), $result['data'] );
		$this->assertSame( 'audio/mpeg', $result['mime_type'] );
		$this->assertSame( array(), $result['provider_metadata'] );
		$this->assertSame( array(), $result['model_metadata'] );
	}

	/**
	 * Test that a WP_Error from the filter is returned as-is.
	 *
	 * @since x.x.x
	 */
	public function test_pre_generate_filter_error_passthrough(): void {
		$error = new WP_Error( 'tts_failed', 'Nope.' );

		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function () use ( $error ) {
				return $error;
			}
		);

		$this->assertSame( $error, ( new Speech_Generator() )->generate_chunk( 'Hello.' ) );
	}
}
