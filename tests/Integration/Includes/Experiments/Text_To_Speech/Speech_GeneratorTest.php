<?php
/**
 * Integration tests for the Speech_Generator class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Text_To_Speech\Audio_Combiner;
use WordPress\AI\Experiments\Text_To_Speech\Speech_Generator;

/**
 * Speech_Generator test case.
 *
 * @since 1.5.0
 */
class Speech_GeneratorTest extends WP_UnitTestCase {

	/**
	 * Tear down test case.
	 *
	 * @since 1.5.0
	 */
	public function tearDown(): void {
		Fake_Speech_Provider::uninstall();

		parent::tearDown();
	}

	/**
	 * Test that a provider that cannot output MP3 fails the MP3 check with
	 * an explanation, while plain text to speech is still supported.
	 *
	 * @since 1.5.0
	 */
	public function test_check_support_rejects_provider_without_mp3(): void {
		Fake_Speech_Provider::install( array( 'audio/wav' ) );

		$generator = new Speech_Generator();
		$result    = $generator->check_support( '', Audio_Combiner::MIME_TYPE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tts_mp3_unsupported', $result->get_error_code() );
		$this->assertTrue( $generator->check_support() );
	}

	/**
	 * Test that a provider that can output MP3 passes the MP3 check.
	 *
	 * @since 1.5.0
	 */
	public function test_check_support_accepts_provider_with_mp3(): void {
		Fake_Speech_Provider::install( array( 'audio/wav', 'audio/mpeg' ) );

		$this->assertTrue( ( new Speech_Generator() )->check_support( '', Audio_Combiner::MIME_TYPE ) );
	}

	/**
	 * Test that with no text to speech provider at all, the error says so
	 * rather than blaming MP3.
	 *
	 * @since 1.5.0
	 */
	public function test_check_support_without_provider_reports_unsupported(): void {
		Fake_Speech_Provider::install( array( 'audio/wav' ) );
		Fake_Speech_Provider::remove_all_providers();

		$result = ( new Speech_Generator() )->check_support( '', Audio_Combiner::MIME_TYPE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_model', $result->get_error_code() );
	}

	/**
	 * Test that the required MIME type reaches the short-circuit filter.
	 *
	 * @since 1.5.0
	 */
	public function test_pre_generate_filter_receives_mime_type(): void {
		$received = null;

		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function ( $pre, $text, $voice, $mime_type ) use ( &$received ) {
				$received = $mime_type;

				return array( 'data' => base64_encode( 'audio' ) );
			},
			10,
			4
		);

		( new Speech_Generator() )->generate_chunk( 'Hello.', '', Audio_Combiner::MIME_TYPE );

		$this->assertSame( Audio_Combiner::MIME_TYPE, $received );
	}

	/**
	 * Test that the pre-generate filter short-circuits generation.
	 *
	 * @since 1.5.0
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
	 * @since 1.5.0
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

	/**
	 * Test that a result whose MIME type is not audio is rejected, so its
	 * bytes can never reach the media library under a non-audio extension.
	 *
	 * @since 1.5.0
	 */
	public function test_non_audio_mime_type_is_rejected(): void {
		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function () {
				return array(
					'data'      => base64_encode( '<script>alert(1)</script>' ),
					'mime_type' => 'text/html',
				);
			}
		);

		$result = ( new Speech_Generator() )->generate_chunk( 'Hello.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tts_invalid_audio_type', $result->get_error_code() );
	}

	/**
	 * Test that audio MIME types other than MP3 still pass.
	 *
	 * @since 1.5.0
	 */
	public function test_other_audio_mime_types_pass(): void {
		add_filter(
			'wpai_tts_pre_generate_chunk',
			static function () {
				return array(
					'data'      => base64_encode( 'RIFF' ),
					'mime_type' => 'audio/wav',
				);
			}
		);

		$result = ( new Speech_Generator() )->generate_chunk( 'Hello.' );

		$this->assertIsArray( $result );
		$this->assertSame( 'audio/wav', $result['mime_type'] );
	}
}
